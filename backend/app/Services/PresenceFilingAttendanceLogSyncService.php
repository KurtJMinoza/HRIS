<?php

namespace App\Services;

use App\Models\AttendanceCorrectionAudit;
use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;

/**
 * Writes HR-approved corrected times into {@see AttendanceLog} so DTR, kiosk,
 * and reports match the correction record (not stale raw punches).
 *
 * Call inside an outer {@see \Illuminate\Support\Facades\DB::transaction}.
 */
class PresenceFilingAttendanceLogSyncService
{
    public function __construct(
        private readonly PresenceFilingService $presenceFilingService,
    ) {}

    private function effectiveStamp(AttendanceLog $log): ?Carbon
    {
        $stamp = $log->verified_at ?? $log->created_at;
        if ($stamp === null) {
            return null;
        }

        return $stamp instanceof Carbon ? $stamp->copy() : Carbon::parse($stamp);
    }

    /**
     * Apply HR-approved correction to same-day attendance punches (UTC).
     * Only requested punch types are changed; the opposite punch is preserved as-is.
     *
     * @return array{applied_time_in: ?\Carbon\Carbon, applied_time_out: ?\Carbon\Carbon}
     */
    public function syncApprovedCorrectionToLogs(
        User $employee,
        string $dateKey,
        ?Carbon $timeInUtc,
        ?Carbon $timeOutUtc,
        User $hrApprover,
        ?int $attendanceCorrectionId = null,
        ?string $approverRoleLabel = null,
        ?string $issueKind = null,
    ): array {
        $tz = $this->presenceFilingService->attendanceTimezone();
        $effectiveIssueKind = is_string($issueKind) ? trim($issueKind) : null;

        $rangeStart = Carbon::parse($dateKey, $tz)->startOfDay()->utc();
        $rangeEnd = Carbon::parse($dateKey, $tz)->endOfDay()->utc();
        if ($timeInUtc !== null && $timeInUtc->lessThan($rangeStart)) {
            $rangeStart = $timeInUtc->copy()->subSecond();
        }
        if ($timeOutUtc !== null && $timeOutUtc->greaterThan($rangeEnd)) {
            $rangeEnd = $timeOutUtc->copy()->addSecond();
        }

        $existingLogs = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('verified_at', [$rangeStart, $rangeEnd])
                    ->orWhereBetween('created_at', [$rangeStart, $rangeEnd]);
            })
            ->orderBy('created_at')
            ->get();

        $existingIn = $existingLogs
            ->first(fn (AttendanceLog $log) => $log->type === AttendanceLog::TYPE_CLOCK_IN && $this->effectiveStamp($log) !== null);
        $existingOut = $existingLogs
            ->first(fn (AttendanceLog $log) => $log->type === AttendanceLog::TYPE_CLOCK_OUT && $this->effectiveStamp($log) !== null);

        $applyIn = in_array($effectiveIssueKind, ['missing_in', 'both'], true);
        $applyOut = in_array($effectiveIssueKind, ['missing_out', 'both'], true);
        if (! $applyIn && ! $applyOut) {
            // Backward compatibility: unknown / legacy issue kind means apply both provided values.
            $applyIn = $timeInUtc !== null;
            $applyOut = $timeOutUtc !== null;
        }

        $finalIn = $applyIn ? $timeInUtc : ($existingIn ? $this->effectiveStamp($existingIn) : null);
        $finalOut = $applyOut ? $timeOutUtc : ($existingOut ? $this->effectiveStamp($existingOut) : null);

        if ($finalIn && $finalOut && $finalIn->greaterThanOrEqualTo($finalOut)) {
            // Keep existing data if applying requested timestamps would violate basic order.
            // Caller validation should prevent this, but we fail-safe for data integrity.
            $finalIn = $existingIn ? $this->effectiveStamp($existingIn) : null;
            $finalOut = $existingOut ? $this->effectiveStamp($existingOut) : null;
        }

        $now = now();
        $base = [
            'user_id' => $employee->id,
            'verified_at' => null,
            'ip_address' => null,
            'user_agent' => null,
            'latitude' => null,
            'longitude' => null,
            'similarity_score' => null,
            'liveness_score' => null,
            'overtime_hours' => null,
            'night_hours' => null,
            'premium_type' => null,
            'calculated_pay_factor' => null,
        ];

        // Idempotent merge strategy:
        // - Keep at most one row per type (clock_in / clock_out) for the date range.
        // - Update existing canonical row when present, otherwise create.
        // - Delete duplicate rows per type in the same day window.
        $byType = $existingLogs->groupBy('type');

        $this->upsertCanonicalTypeLog(
            $employee->id,
            AttendanceLog::TYPE_CLOCK_IN,
            $finalIn,
            $byType->get(AttendanceLog::TYPE_CLOCK_IN, collect()),
            $base,
            $now
        );
        $this->upsertCanonicalTypeLog(
            $employee->id,
            AttendanceLog::TYPE_CLOCK_OUT,
            $finalOut,
            $byType->get(AttendanceLog::TYPE_CLOCK_OUT, collect()),
            $base,
            $now
        );

        if ($attendanceCorrectionId !== null) {
            $ts = now();
            AttendanceCorrectionAudit::create([
                'attendance_correction_id' => $attendanceCorrectionId,
                'admin_id' => $hrApprover->id,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => null,
                'previous_time_out' => null,
                'new_time_in' => $finalIn,
                'new_time_out' => $finalOut,
                'reason' => sprintf(
                    'Attendance logs synchronized at %s by %s (issue: %s)',
                    $ts->toIso8601String(),
                    $hrApprover->name,
                    $effectiveIssueKind ?? 'legacy'
                ),
                'action' => 'apply_attendance_logs',
                'approver_role' => $approverRoleLabel ?? 'Admin HR',
            ]);
        }

        return [
            'applied_time_in' => $finalIn,
            'applied_time_out' => $finalOut,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AttendanceLog>  $typeLogs
     * @param  array<string, mixed>  $base
     */
    private function upsertCanonicalTypeLog(
        int $userId,
        string $type,
        ?Carbon $targetUtc,
        \Illuminate\Support\Collection $typeLogs,
        array $base,
        Carbon $now
    ): void {
        $ordered = $typeLogs
            ->sortBy(fn (AttendanceLog $log) => $this->effectiveStamp($log)?->timestamp ?? 0)
            ->values();
        $canonical = null;
        if ($ordered->isNotEmpty()) {
            // Keep earliest clock-in and latest clock-out as canonical before updating.
            $canonical = $type === AttendanceLog::TYPE_CLOCK_OUT ? $ordered->last() : $ordered->first();
        }

        if ($targetUtc === null) {
            // No target punch for this type: remove all existing entries of this type in range.
            if ($ordered->isNotEmpty()) {
                AttendanceLog::query()->whereIn('id', $ordered->pluck('id')->all())->delete();
            }

            return;
        }

        if ($canonical instanceof AttendanceLog) {
            AttendanceLog::query()->whereKey($canonical->id)->update([
                'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                'verified_at' => $targetUtc,
                'created_at' => $targetUtc,
                'updated_at' => $now,
            ]);

            $duplicates = $ordered
                ->filter(fn (AttendanceLog $log) => (int) $log->id !== (int) $canonical->id)
                ->pluck('id')
                ->all();
            if ($duplicates !== []) {
                AttendanceLog::query()->whereIn('id', $duplicates)->delete();
            }

            return;
        }

        AttendanceLog::query()->create(array_merge($base, [
            'type' => $type,
            'verified_at' => $targetUtc,
            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
            'created_at' => $targetUtc,
            'updated_at' => $now,
        ]));
    }
}
