<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Overtime;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

/**
 * Resolves clock-in / clock-out for a calendar day in the attendance timezone.
 * Used by payroll, premium reports, and the rules engine so numbers stay aligned.
 *
 * Order matches Admin → Reports detailed rows:
 * 1) Device logs (clock-in on dateKey, then clock-out after that in).
 * 2) Overlay approved manual attendance (correction) for time_in / time_out when set.
 * 3) If still no clock-out, use approved overtime expected end (virtual punch-out).
 */
class AttendanceSessionService
{
    /** @var array<string, array{0: ?Carbon, 1: ?Carbon}> */
    private array $timesForDateCache = [];

    /**
     * When true, {@see getTimesForDate} resolves from {@see beginBulkPayrollSession} stores (3 queries total)
     * instead of querying corrections/logs/OT per employee per day — used by bulk draft payroll generation.
     */
    private bool $bulkPayrollMode = false;

    /** @var array<string, AttendanceCorrection> key "{userId}|{Y-m-d}" */
    private array $bulkCorrectionsByUserDate = [];

    /** @var array<string, Overtime> key "{userId}|{Y-m-d}" */
    private array $bulkOvertimeByUserDate = [];

    /** @var array<int, list<AttendanceLog>> verified_at ascending per user */
    private array $bulkLogsByUserId = [];

    public function flushRuntimeCache(): void
    {
        $this->timesForDateCache = [];
        $this->endBulkPayrollSession();
    }

    /**
     * Prefetch corrections, attendance_logs, and approved overtime for the pay window (+/- one local day).
     *
     * @param  list<int>  $userIds
     * @return array{corrections_ms: float, ot_stub_ms: float, logs_ms: float}
     */
    public function beginBulkPayrollSession(array $userIds, string $fromDate, string $toDate): array
    {
        $this->endBulkPayrollSession();

        $timings = ['corrections_ms' => 0.0, 'ot_stub_ms' => 0.0, 'logs_ms' => 0.0];

        $userIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($userIds === []) {
            return $timings;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $this->bulkPayrollMode = true;

        $__t = microtime(true);
        $correctionRows = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get();

        foreach ($correctionRows as $c) {
            $d = $c->date instanceof Carbon
                ? $c->date->toDateString()
                : Carbon::parse((string) $c->date)->toDateString();
            $key = ((int) $c->user_id).'|'.$d;
            if (! array_key_exists($key, $this->bulkCorrectionsByUserDate)) {
                $this->bulkCorrectionsByUserDate[$key] = $c;
            }
        }
        $timings['corrections_ms'] = (microtime(true) - $__t) * 1000;
        $timings['corrections_count'] = $correctionRows->count();

        $__t = microtime(true);
        $otRows = Overtime::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->where('status', Overtime::STATUS_APPROVED)
            ->orderByDesc('id')
            ->get();

        foreach ($otRows as $ot) {
            $d = $ot->date instanceof Carbon
                ? $ot->date->toDateString()
                : Carbon::parse((string) $ot->date)->toDateString();
            $key = ((int) $ot->user_id).'|'.$d;
            if (! array_key_exists($key, $this->bulkOvertimeByUserDate)) {
                $this->bulkOvertimeByUserDate[$key] = $ot;
            }
        }
        $timings['ot_stub_ms'] = (microtime(true) - $__t) * 1000;

        $rangeStartLocal = Carbon::parse($fromDate, $tz)->startOfDay()->subDay();
        $rangeEndLocal = Carbon::parse($toDate, $tz)->endOfDay()->addDay();
        $rangeStartUtc = $rangeStartLocal->copy()->timezone('UTC');
        $rangeEndUtc = $rangeEndLocal->copy()->timezone('UTC');

        $__t = microtime(true);
        $logs = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('verified_at', [$rangeStartUtc, $rangeEndUtc])
            ->orderBy('verified_at')
            ->get(['id', 'user_id', 'type', 'verified_at']);

        foreach ($logs as $log) {
            $uid = (int) $log->user_id;
            $this->bulkLogsByUserId[$uid][] = $log;
        }
        $timings['logs_ms'] = (microtime(true) - $__t) * 1000;
        $timings['logs_count'] = $logs->count();

        return $timings;
    }

    public function isBulkPayrollMode(): bool
    {
        return $this->bulkPayrollMode;
    }

    /**
     * Any verified attendance log on the local calendar day (bulk session only).
     */
    public function hasAnyVerifiedLogOnLocalDate(int $userId, string $dateKey, string $tz): bool
    {
        $dayStartUtc = Carbon::parse($dateKey, $tz)->startOfDay()->timezone('UTC');
        $dayEndUtc = Carbon::parse($dateKey, $tz)->endOfDay()->timezone('UTC');

        foreach ($this->bulkLogsByUserId[$userId] ?? [] as $log) {
            $v = $log->verified_at;
            if ($v !== null && $v >= $dayStartUtc && $v <= $dayEndUtc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors {@see PayrollComputationService::resolveAllowanceAttendanceValidity} using bulk stores.
     *
     * @return array{valid: bool, reason: string, sources: array<string, bool>}
     */
    public function allowanceAttendanceValidityForPayroll(User $user, string $dateKey, string $tz): array
    {
        $sources = [
            'raw_clock_in' => false,
            'raw_clock_out' => false,
            'approved_correction' => false,
            'approved_correction_time_in' => false,
            'approved_correction_time_out' => false,
        ];

        $correction = $this->bulkCorrectionsByUserDate[((int) $user->id).'|'.$dateKey] ?? null;

        if ($correction) {
            $sources['approved_correction'] = true;
            $sources['approved_correction_time_in'] = $correction->time_in !== null;
            $sources['approved_correction_time_out'] = $correction->time_out !== null;
        }

        $dayStartUtc = Carbon::parse($dateKey, $tz)->startOfDay()->timezone('UTC');
        $dayEndUtc = Carbon::parse($dateKey, $tz)->endOfDay()->timezone('UTC');

        foreach ($this->bulkLogsByUserId[(int) $user->id] ?? [] as $log) {
            $v = $log->verified_at;
            if ($v === null || $v < $dayStartUtc || $v > $dayEndUtc) {
                continue;
            }
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $sources['raw_clock_in'] = true;
            }
            if ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $sources['raw_clock_out'] = true;
            }
        }

        $hasValidInOut = (($sources['raw_clock_in'] || $sources['approved_correction_time_in'])
            && ($sources['raw_clock_out'] || $sources['approved_correction_time_out']));
        $valid = $hasValidInOut || $sources['approved_correction'];

        return [
            'valid' => $valid,
            'reason' => $valid
                ? ($sources['approved_correction'] ? 'approved_attendance_correction' : 'valid_attendance_session')
                : 'missing_attendance_or_approved_correction',
            'sources' => $sources,
        ];
    }

    public function endBulkPayrollSession(): void
    {
        $this->bulkPayrollMode = false;
        $this->bulkCorrectionsByUserDate = [];
        $this->bulkOvertimeByUserDate = [];
        $this->bulkLogsByUserId = [];
    }

    /**
     * @return array<int, list<AttendanceLog>>
     */
    public function bulkLogsByUserId(): array
    {
        return $this->bulkLogsByUserId;
    }

    public function getTimesForDate(User $user, string $dateKey, ?string $tz = null): array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $cacheKey = ((int) $user->id).'|'.$dateKey.'|'.$tz;
        if (array_key_exists($cacheKey, $this->timesForDateCache)) {
            [$cachedIn, $cachedOut] = $this->timesForDateCache[$cacheKey];

            return [$cachedIn?->copy(), $cachedOut?->copy()];
        }

        if ($this->bulkPayrollMode) {
            [$timeIn, $timeOut] = $this->resolveTimesForDateUsingBulkStores($user, $dateKey, $tz);
            if ($timeIn === null || $timeOut === null) {
                $this->timesForDateCache[$cacheKey] = [null, null];

                return [null, null];
            }
            $this->timesForDateCache[$cacheKey] = [$timeIn->copy(), $timeOut->copy()];

            return [$timeIn->copy(), $timeOut->copy()];
        }

        $correction = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();

        $timeIn = null;
        $timeOut = null;

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $clockIn = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->orderBy('verified_at')
            ->first();

        if (! $clockIn) {
            $prevDayStart = $dayStart->copy()->subDay()->startOfDay();
            $prevDayEnd = $dayEnd->copy()->subDay()->endOfDay();
            $clockIn = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereBetween('verified_at', [$prevDayStart->setTimezone('UTC'), $prevDayEnd->setTimezone('UTC')])
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->orderBy('verified_at')
                ->first();
        }

        if ($clockIn) {
            $candidateIn = $clockIn->verified_at->copy()->timezone($tz);
            if ($candidateIn->toDateString() === $dateKey) {
                $timeIn = $candidateIn;
                $clockOutSearchEndUtc = $this->clockOutSearchEndUtc($user, $dateKey, $tz, $dayEnd);
                $clockOut = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->where('verified_at', '>=', $timeIn->copy()->setTimezone('UTC'))
                    ->where('verified_at', '<=', $clockOutSearchEndUtc)
                    ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                    ->orderBy('verified_at')
                    ->first();
                if ($clockOut) {
                    $timeOut = $clockOut->verified_at->copy()->timezone($tz);
                }
            }
        }

        if ($correction) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in->copy()->timezone($tz);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out->copy()->timezone($tz);
            }
        }

        if ($timeIn !== null && $timeOut === null) {
            // Missing-in corrections often provide only the approved time-in while the actual
            // device clock-out remains in attendance_logs. Payroll must merge those sources so
            // undertime days (e.g. 08:00 correction + 09:42 clock-out) pay actual worked time.
            $clockOutSearchEndUtc = $this->clockOutSearchEndUtc($user, $dateKey, $tz, $dayEnd);
            $clockOut = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->where('verified_at', '>=', $timeIn->copy()->setTimezone('UTC'))
                ->where('verified_at', '<=', $clockOutSearchEndUtc)
                ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                ->orderBy('verified_at')
                ->first();
            if ($clockOut) {
                $timeOut = $clockOut->verified_at->copy()->timezone($tz);
            }
        }

        if ($timeIn === null || $timeOut === null) {
            $this->timesForDateCache[$cacheKey] = [null, null];

            return [null, null];
        }

        $this->timesForDateCache[$cacheKey] = [$timeIn->copy(), $timeOut->copy()];

        return [$timeIn, $timeOut];
    }

    private function clockOutSearchEndUtc(User $user, string $dateKey, string $tz, Carbon $dayEnd): Carbon
    {
        $end = $dayEnd->copy();

        $user->loadMissing('workingSchedule');
        $effectiveSchedule = EmployeeScheduleResolver::resolve($user);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz));
        $daySchedule = is_array($effectiveSchedule) ? ($effectiveSchedule[$dayKey] ?? null) : null;
        if (is_array($daySchedule)) {
            $inRaw = trim((string) ($daySchedule['in'] ?? ''));
            $outRaw = trim((string) ($daySchedule['out'] ?? ''));
            if ($inRaw !== '' && $outRaw !== '') {
                try {
                    $scheduledIn = Carbon::parse($dateKey.' '.$inRaw, $tz);
                    $scheduledOut = Carbon::parse($dateKey.' '.$outRaw, $tz);
                    if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
                        $scheduledOut->addDay();
                        $end = $end->max($scheduledOut->copy()->addHours(4));
                    }
                } catch (\Throwable) {
                    // Keep the same-day bound if a malformed schedule cannot be parsed.
                }
            }
        }

        $approvedOtEnd = $this->approvedOvertimeSearchEnd($user, $dateKey, $tz);
        if ($approvedOtEnd !== null) {
            $end = $end->max($approvedOtEnd->copy()->addHours(2));
        }

        return $end->setTimezone('UTC');
    }

    /**
     * Approved OT end can expand the log search range, but it must never become actual time_out.
     */
    private function approvedOvertimeSearchEnd(User $user, string $dateKey, string $tz): ?Carbon
    {
        $ot = null;
        if ($this->bulkPayrollMode) {
            $ot = $this->bulkOvertimeByUserDate[(int) $user->id.'|'.$dateKey] ?? null;
        } else {
            $ot = Overtime::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $dateKey)
                ->where('status', Overtime::STATUS_APPROVED)
                ->first();
        }

        if (! $ot instanceof Overtime) {
            return null;
        }

        $user->loadMissing('workingSchedule');
        $effectiveSchedule = EmployeeScheduleResolver::resolve($user);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz));
        $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey])
            ? $effectiveSchedule[$dayKey]
            : null;

        return AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
            $ot,
            $dateKey,
            is_array($todaySchedule) ? $todaySchedule : null,
            $tz
        );
    }

    /**
     * Same rules as the SQL-backed path in {@see getTimesForDate}, using stores built by {@see beginBulkPayrollSession}.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveTimesForDateUsingBulkStores(User $user, string $dateKey, string $tz): array
    {
        $uid = (int) $user->id;
        $correction = $this->bulkCorrectionsByUserDate[$uid.'|'.$dateKey] ?? null;

        $timeIn = null;
        $timeOut = null;

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->timezone('UTC');
        $dayEndUtc = $dayEnd->copy()->timezone('UTC');

        $clockIn = $this->firstAttendanceLogInUtcWindow($uid, AttendanceLog::TYPE_CLOCK_IN, $dayStartUtc, $dayEndUtc);

        if (! $clockIn) {
            $prevDayStart = $dayStart->copy()->subDay()->startOfDay();
            $prevDayEnd = $dayEnd->copy()->subDay()->endOfDay();
            $clockIn = $this->firstAttendanceLogInUtcWindow(
                $uid,
                AttendanceLog::TYPE_CLOCK_IN,
                $prevDayStart->copy()->timezone('UTC'),
                $prevDayEnd->copy()->timezone('UTC')
            );
        }

        if ($clockIn) {
            $candidateIn = $clockIn->verified_at->copy()->timezone($tz);
            if ($candidateIn->toDateString() === $dateKey) {
                $timeIn = $candidateIn;
                $clockOutSearchEndUtc = $this->clockOutSearchEndUtc($user, $dateKey, $tz, $dayEnd);
                $clockOut = $this->firstAttendanceLogInUtcWindow(
                    $uid,
                    AttendanceLog::TYPE_CLOCK_OUT,
                    $timeIn->copy()->timezone('UTC'),
                    $clockOutSearchEndUtc
                );
                if ($clockOut) {
                    $timeOut = $clockOut->verified_at->copy()->timezone($tz);
                }
            }
        }

        if ($correction) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in->copy()->timezone($tz);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out->copy()->timezone($tz);
            }
        }

        if ($timeIn !== null && $timeOut === null) {
            $clockOutSearchEndUtc = $this->clockOutSearchEndUtc($user, $dateKey, $tz, $dayEnd);
            $clockOut = $this->firstAttendanceLogInUtcWindow(
                $uid,
                AttendanceLog::TYPE_CLOCK_OUT,
                $timeIn->copy()->timezone('UTC'),
                $clockOutSearchEndUtc
            );
            if ($clockOut) {
                $timeOut = $clockOut->verified_at->copy()->timezone($tz);
            }
        }

        if ($timeIn === null || $timeOut === null) {
            return [null, null];
        }

        return [$timeIn, $timeOut];
    }

    /**
     * @param  Carbon  $startUtc  inclusive
     * @param  Carbon  $endUtc  inclusive
     */
    private function firstAttendanceLogInUtcWindow(
        int $userId,
        string $type,
        Carbon $startUtc,
        Carbon $endUtc
    ): ?AttendanceLog {
        foreach ($this->bulkLogsByUserId[$userId] ?? [] as $log) {
            if ($log->type !== $type) {
                continue;
            }
            $v = $log->verified_at;
            if ($v >= $startUtc && $v <= $endUtc) {
                return $log;
            }
        }

        return null;
    }
}
