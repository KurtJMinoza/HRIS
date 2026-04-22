<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\OvertimeApprovalService;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\PayrollRulesEngineService;
use App\Support\EmployeeScheduleResolver;
use App\Support\OvertimeFilingRules;
use App\Support\PhPayrollReference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeOvertimeController extends Controller
{
    public function __construct(
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly OvertimeApprovalService $overtimeApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    private const NO_SCHEDULE_ASSIGNED_MESSAGE = 'No schedule assigned yet. Please contact the administrator.';

    private const REST_DAY_MESSAGE = 'No work schedule for this date (rest day or unassigned).';

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Validate schedule, attendance, and times; return computed OT quantities.
     *
     * @return array{schedule_end: \Carbon\Carbon, expected_end: \Carbon\Carbon, computed_minutes: int, computed_hours: float}
     */
    private function computeOvertimeRequestQuantities(User $user, string $dateYmd, string $expectedEndTimeHmi): array
    {
        $tz = $this->attendanceTimezone();
        $user->loadMissing('workingSchedule');

        $schedule = EmployeeScheduleResolver::resolve($user);

        if (! is_array($schedule) || $schedule === []) {
            throw ValidationException::withMessages([
                'date' => [self::NO_SCHEDULE_ASSIGNED_MESSAGE],
            ]);
        }

        $carbonDate = Carbon::parse($dateYmd, $tz);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($carbonDate);
        $daySchedule = $schedule[$dayKey] ?? null;

        if (! is_array($daySchedule) || empty($daySchedule['out']) || empty($daySchedule['in'])) {
            throw ValidationException::withMessages([
                'date' => [self::REST_DAY_MESSAGE],
            ]);
        }

        $hasApprovedLeave = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateYmd)
            ->whereDate('end_date', '>=', $dateYmd)
            ->exists();

        if ($hasApprovedLeave) {
            throw ValidationException::withMessages([
                'date' => ['You have an approved leave for this date. Overtime is not allowed.'],
            ]);
        }

        OvertimeFilingRules::assertDateWithinFilingWindow($dateYmd, $tz);

        $presence = OvertimeFilingRules::clockInOutPresenceForDate($user->id, $dateYmd, $tz);
        $hasClockIn = $presence['has_clock_in'];
        $hasClockOut = $presence['has_clock_out'];
        $lastClockOutAt = $presence['last_clock_out_at'];

        $todayYmd = Carbon::now($tz)->toDateString();

        if ($dateYmd < $todayYmd) {
            OvertimeFilingRules::assertPastDateHasCompletedAttendance($user->id, $dateYmd, $tz);
        } elseif (! $hasClockIn) {
            throw ValidationException::withMessages([
                'date' => ['Clock in for this date first. Once clocked in, you can file overtime immediately — no need to wait for clock-out.'],
            ]);
        }

        $scheduleStart = Carbon::parse($dateYmd.' '.trim((string) $daySchedule['in']), $tz);
        $scheduleEnd = Carbon::parse($dateYmd.' '.trim((string) $daySchedule['out']), $tz);
        if ($scheduleEnd->lessThanOrEqualTo($scheduleStart)) {
            $scheduleEnd->addDay();
        }
        $overnight = $scheduleEnd->toDateString() !== $scheduleStart->toDateString();

        $expectedEnd = Carbon::parse($dateYmd.' '.$expectedEndTimeHmi, $tz);
        if ($overnight && $expectedEnd->lessThanOrEqualTo($scheduleStart)) {
            $expectedEnd->addDay();
        }

        if ($expectedEnd->lessThanOrEqualTo($scheduleEnd)) {
            throw ValidationException::withMessages([
                'expected_end_time' => ['Expected end time must be later than your scheduled shift end ('.($scheduleEnd->format('H:i')).').'],
            ]);
        }

        if ($hasClockOut && $lastClockOutAt !== null) {
            $lastOutLocal = $lastClockOutAt->copy()->timezone($tz);
            if ($expectedEnd->lessThan($lastOutLocal)) {
                throw ValidationException::withMessages([
                    'expected_end_time' => ['Expected end time cannot be earlier than your actual clock-out time.'],
                ]);
            }
        }

        $computedMinutes = (int) $scheduleEnd->diffInMinutes($expectedEnd);

        if ($computedMinutes <= 0) {
            throw ValidationException::withMessages([
                'expected_end_time' => ['Overtime must extend beyond your scheduled end time to be valid.'],
            ]);
        }

        $computedHours = round($computedMinutes / 60, 2);

        return [
            'schedule_end' => $scheduleEnd,
            'expected_end' => $expectedEnd,
            'computed_minutes' => $computedMinutes,
            'computed_hours' => $computedHours,
        ];
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        $segments = explode('/', $normalized);
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    private function attachmentBasename(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return basename(str_replace('\\', '/', $path));
    }

    private function ensureSelfOvertimeAccess(User $user): void
    {
        if ($user->isEmployee()) {
            return;
        }
        // HR panel and line-manager admins use the same /overtime/* self-service routes for their
        // own requests (Admin → Overtime loads this flow). Org-head-only allowance was too narrow.
        if ($user->isAdmin()) {
            return;
        }

        throw ValidationException::withMessages([
            'user' => ['You are not allowed to use self-service overtime for this account.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requesterMetaForSubject(User $subject): array
    {
        $hr = $this->hrRoleResolver->resolveForApprovalSubject($subject);

        return [
            'requested_by_id' => $subject->id,
            'requested_by_name' => $subject->name,
            'requested_by_position' => $subject->position,
            'requested_by_profile_image_url' => $subject->profile_image_url,
            'requested_by_hr_role' => $hr->value,
            'requested_by_role_label' => $hr->badgeLabel(),
        ];
    }

    private function mergeOvertimeRemarksIntoProgress(Overtime $overtime, array $steps): array
    {
        if (! $overtime->relationLoaded('approvalAudits')) {
            return $steps;
        }

        $audits = $overtime->approvalAudits;
        foreach ($steps as $i => $step) {
            $key = $step['key'] ?? '';
            $remarks = null;
            if ($key === 'submitted') {
                $a = $audits->firstWhere('action', 'file');
                $remarks = $a?->details;
            } elseif ($key === 'line_approval') {
                $a = $audits->firstWhere('action', 'approve_first');
                $remarks = $a?->details;
            } elseif ($key === 'hr_final') {
                $a = $audits->firstWhere('action', 'approve_final') ?? $audits->firstWhere('action', 'reject');
                $remarks = $a?->details;
            }
            $steps[$i]['remarks'] = $remarks;
        }

        return $steps;
    }

    private function mapOvertimeRowForEmployee(Overtime $o): array
    {
        $o->loadMissing([
            'approvedBy:id,name',
            'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
            'filedBy:id,name,profile_image',
            'firstApprover:id,name,profile_image',
            'secondApprover:id,name,profile_image',
            'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
        ]);

        $subject = $o->user;
        $requesterMeta = $subject instanceof User ? $this->requesterMetaForSubject($subject) : [];

        return array_merge([
            'id' => $o->id,
            'date' => $o->date?->toDateString(),
            'schedule_end' => $o->schedule_end?->format('H:i'),
            'expected_end_time' => $o->expected_end_time?->format('H:i'),
            'computed_hours' => (float) $o->computed_hours,
            'computed_minutes' => $o->computed_minutes,
            'ot_type' => $o->ot_type,
            'reason' => $o->reason,
            'status' => $o->status,
            'remarks' => $o->remarks,
            'rejection_note' => $o->rejection_note,
            'has_attachment' => ! empty($o->attachment_path),
            'attachment_url' => $this->publicMediaUrl($o->attachment_path),
            'attachment_filename' => $this->attachmentBasename($o->attachment_path),
            'approved_at' => $o->approved_at?->toIso8601String(),
            'approved_by_name' => $o->approvedBy?->name,
            'created_at' => $o->created_at?->toIso8601String(),
            'filed_at' => $o->filed_at?->toIso8601String(),
            'display_status' => $this->overtimeApprovalService->deriveDisplayStatusLabel($o),
            'approval_stage' => $o->approval_stage,
            'approval_progress' => $this->mergeOvertimeRemarksIntoProgress(
                $o,
                $this->overtimeApprovalService->buildApprovalProgress($o)
            ),
            'approval_history' => $o->approvalAudits->map(function (OvertimeApprovalAudit $a) {
                return [
                    'action' => $a->action,
                    'approver_role' => $a->approver_role,
                    'details' => $a->details,
                    'at' => $a->created_at?->toIso8601String(),
                    'actor_name' => $a->actor?->name,
                ];
            })->values()->all(),
        ], $requesterMeta, PhPayrollReference::ruleMetaForOvertime($o->ph_ot_rule));
    }

    /**
     * List overtime records for the authenticated employee.
     */
    public function myIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(50, $perPage));

        $paginator = Overtime::query()
            ->where('user_id', $user->id)
            ->with([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (Overtime $o) => $this->mapOvertimeRowForEmployee($o))->values();

        return response()->json([
            'overtimes' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Single overtime row (employee owns record).
     */
    public function myShow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        $overtime = Overtime::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->with([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ])
            ->firstOrFail();

        return response()->json([
            'overtime' => $this->mapOvertimeRowForEmployee($overtime),
        ]);
    }

    /**
     * Update a pending overtime request (recompute hours if expected end changes).
     */
    public function myUpdate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        $overtime = Overtime::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($overtime->status !== Overtime::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'id' => ['Only pending overtime requests can be edited.'],
            ]);
        }

        if (! $overtime->pending_approval || ($overtime->approval_stage ?? \App\Support\HrApprovalStages::PENDING_FIRST) !== \App\Support\HrApprovalStages::PENDING_FIRST) {
            throw ValidationException::withMessages([
                'id' => ['This request cannot be edited after a manager has approved it.'],
            ]);
        }

        $validated = $request->validate([
            'expected_end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'ph_ot_rule' => ['required', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:10'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $dateYmd = $overtime->date->toDateString();
        $tz = $this->attendanceTimezone();
        $todayInTz = Carbon::now($tz)->toDateString();

        if ($dateYmd > $todayInTz) {
            throw ValidationException::withMessages([
                'date' => ['Invalid overtime record date.'],
            ]);
        }

        $computed = $this->computeOvertimeRequestQuantities($user, $dateYmd, $validated['expected_end_time']);

        $attachmentPath = $overtime->attachment_path;
        if ($request->hasFile('attachment')) {
            if (is_string($attachmentPath) && $attachmentPath !== '' && Storage::disk('public')->exists($attachmentPath)) {
                Storage::disk('public')->delete($attachmentPath);
            }
            $attachmentPath = $request->file('attachment')->store('overtime_attachments', 'public');
        }

        $overtime->fill([
            'schedule_end' => $computed['schedule_end']->format('H:i:s'),
            'expected_end_time' => $computed['expected_end']->format('H:i:s'),
            'computed_minutes' => $computed['computed_minutes'],
            'computed_hours' => $computed['computed_hours'],
            'ph_ot_rule' => $validated['ph_ot_rule'],
            'ot_type' => $validated['category'],
            'reason' => $validated['reason'],
            'attachment_path' => $attachmentPath,
        ]);
        $overtime->save();

        $overtime->load('approvedBy:id,name');

        return response()->json([
            'message' => 'Overtime request updated.',
            'overtime' => $this->mapOvertimeRowForEmployee($overtime->fresh([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ])),
        ]);
    }

    /**
     * Cancel (delete) a pending overtime request.
     */
    public function myDestroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        $overtime = Overtime::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($overtime->status !== Overtime::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'id' => ['Only pending overtime requests can be cancelled.'],
            ]);
        }

        $path = $overtime->attachment_path;
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $overtime->delete();

        return response()->json([
            'message' => 'Overtime request cancelled.',
        ]);
    }

    /**
     * Context for the OT request form (schedule end, clock-in/out, hybrid pre/post mode).
     */
    public function requestContext(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => ['Account is deactivated.'],
            ]);
        }

        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $dateYmd = Carbon::parse($request->query('date'))->toDateString();
        $tz = $this->attendanceTimezone();
        $todayInTz = Carbon::now($tz)->toDateString();

        if ($dateYmd > $todayInTz) {
            throw ValidationException::withMessages([
                'date' => ['Overtime date cannot be in the future.'],
            ]);
        }

        $filingDays = OvertimeFilingRules::filingWindowDays();
        $earliestAllowed = OvertimeFilingRules::earliestAllowedOvertimeDate($tz);

        $user->loadMissing('workingSchedule');

        $schedule = EmployeeScheduleResolver::resolve($user);

        $carbonDate = Carbon::parse($dateYmd, $tz);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($carbonDate);
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;

        $phOtOptions = PhPayrollReference::otMultiplierDropdownOptions();

        if (! is_array($schedule) || $schedule === []) {
            return response()->json([
                'date' => $dateYmd,
                'filing_window_days' => $filingDays,
                'earliest_allowed_date' => $earliestAllowed,
                'has_assigned_schedule' => false,
                'is_workday' => false,
                'schedule_start' => null,
                'schedule_end' => null,
                'overnight_shift' => false,
                'has_clock_in' => false,
                'has_clock_out' => false,
                'last_clock_out_at' => null,
                'mode' => 'unavailable',
                'mode_label' => 'No schedule assigned',
                'help' => self::NO_SCHEDULE_ASSIGNED_MESSAGE,
                'ph_ot_rule_options' => $phOtOptions,
                'default_ph_ot_rule' => 'ORD',
                'ph_ot_rule_help' => 'Select the PH pay condition for overtime (Ordinary, Rest day, Holiday…). Default matches calendar + schedule when you file on a workday.',
            ]);
        }

        if (! is_array($daySchedule) || empty($daySchedule['in'] ?? null) || empty($daySchedule['out'] ?? null)) {
            return response()->json([
                'date' => $dateYmd,
                'filing_window_days' => $filingDays,
                'earliest_allowed_date' => $earliestAllowed,
                'has_assigned_schedule' => true,
                'is_workday' => false,
                'schedule_start' => null,
                'schedule_end' => null,
                'overnight_shift' => false,
                'has_clock_in' => false,
                'has_clock_out' => false,
                'last_clock_out_at' => null,
                'mode' => 'unavailable',
                'mode_label' => 'Not a scheduled workday',
                'help' => self::REST_DAY_MESSAGE,
                'ph_ot_rule_options' => $phOtOptions,
                'default_ph_ot_rule' => 'RD',
                'ph_ot_rule_help' => 'This date is a scheduled rest day. If you work overtime on a rest day, choose Rest Day (or holiday+rest if a holiday applies).',
            ]);
        }

        $scheduleStart = Carbon::parse($dateYmd.' '.trim((string) $daySchedule['in']), $tz);
        $scheduleEnd = Carbon::parse($dateYmd.' '.trim((string) $daySchedule['out']), $tz);
        if ($scheduleEnd->lessThanOrEqualTo($scheduleStart)) {
            $scheduleEnd->addDay();
        }
        $overnight = $scheduleEnd->toDateString() !== $scheduleStart->toDateString();

        $presence = OvertimeFilingRules::clockInOutPresenceForDate($user->id, $dateYmd, $tz);
        $hasClockIn = $presence['has_clock_in'];
        $hasClockOut = $presence['has_clock_out'];
        $lastClockOutAt = $presence['last_clock_out_at'];

        $outsideFiling = false;
        if ($dateYmd < $todayInTz) {
            $d = Carbon::parse($dateYmd, $tz)->startOfDay();
            $todayStart = Carbon::now($tz)->startOfDay();
            $outsideFiling = (int) $d->diffInDays($todayStart) > $filingDays;
        }

        $mode = 'unavailable';
        $modeLabel = 'Unavailable';
        $help = '';

        if ($outsideFiling) {
            $modeLabel = 'Outside filing window';
            $help = sprintf(
                'Late OT filing is only allowed within %d calendar days after the work date. Contact HR for older dates.',
                $filingDays
            );
        } elseif ($dateYmd < $todayInTz) {
            if (! $hasClockIn || ! $hasClockOut) {
                $modeLabel = 'Attendance incomplete for this date';
                $help = 'For past dates, both clock-in and clock-out must exist on that day before you can file overtime (late filing). Use corrections if logs are missing.';
            } else {
                $mode = 'post_ot';
                $modeLabel = 'Late filing (post-shift)';
                $help = 'You are filing overtime after the work day. Expected end must be after your scheduled end and not before your actual clock-out time.';
            }
        } elseif (! $hasClockIn) {
            $modeLabel = 'Clock in required';
            $help = 'Clock in for this date first. Once clocked in, you can file overtime immediately — no need to wait for clock-out.';
        } elseif (! $hasClockOut) {
            $mode = 'pre_ot';
            $modeLabel = 'Ready to file advance OT';
            $help = 'You are currently on shift. Submit your overtime request now — no need to wait for clock-out. Set the expected end time after your scheduled shift end. Clock out as usual when you finish.';
        } else {
            $mode = 'post_ot';
            $modeLabel = 'Post-shift OT';
            $help = 'You have completed attendance for this date. Expected end time must be after your scheduled end and not before your actual clock-out time.';
        }

        $isRestDay = $this->rulesEngine->isRestDay($schedule, $carbonDate);
        $holidayType = $this->rulesEngine->getHolidayType($dateYmd, $user->getEffectiveCompanyId());
        $defaultPhOtRule = $this->rulesEngine->resolveRuleCode($isRestDay, $holidayType);

        return response()->json([
            'date' => $dateYmd,
            'filing_window_days' => $filingDays,
            'earliest_allowed_date' => $earliestAllowed,
            'has_assigned_schedule' => true,
            'is_workday' => true,
            'schedule_start' => $scheduleStart->format('H:i'),
            'schedule_end' => $scheduleEnd->format('H:i'),
            'overnight_shift' => $overnight,
            'has_clock_in' => $hasClockIn,
            'has_clock_out' => $hasClockOut,
            'last_clock_out_at' => $lastClockOutAt?->toIso8601String(),
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'help' => $help,
            'ph_ot_rule_options' => $phOtOptions,
            'default_ph_ot_rule' => $defaultPhOtRule,
            'ph_ot_rule_help' => 'Select the PH pay condition for this overtime. Default follows Admin → Holidays + your rest-day schedule for this date. Change only if HR instructs you.',
        ]);
    }

    /**
     * Submit a manual overtime request for the authenticated employee.
     *
     * Hybrid rules:
     * - Valid schedule for that date (JSON or working_schedule_id)
     * - Date is today or earlier (attendance TZ), not future
     * - At least one clock-in on that date (pre-OT while still in, or post-OT after clock-out)
     * - Expected end strictly after scheduled end (night-shift aware)
     * - When clock-out exists, expected end must not be before actual clock-out
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => ['Account is deactivated.'],
            ]);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'expected_end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'ph_ot_rule' => ['required', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:10'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $tz = $this->attendanceTimezone();
        $dateYmd = Carbon::parse($validated['date'])->toDateString();
        $todayInTz = Carbon::now($tz)->toDateString();

        if ($dateYmd > $todayInTz) {
            throw ValidationException::withMessages([
                'date' => ['Overtime date cannot be in the future.'],
            ]);
        }

        try {
            $day = Carbon::parse($dateYmd)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $user->id, $day, $day);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $existing = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateYmd)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'date' => ['You already have an overtime record for this date.'],
            ]);
        }

        $computed = $this->computeOvertimeRequestQuantities($user, $dateYmd, $validated['expected_end_time']);

        $routing = $this->hrApprovalChainResolver->resolveRoutingDecision($user);
        $chain = $routing['chain'];
        if ($chain === null) {
            throw ValidationException::withMessages([
                'user' => ['Your role cannot file overtime requests.'],
            ]);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('overtime_attachments', 'public');
        }

        $stage = $this->hrApprovalChainResolver->initialApprovalStage($user);
        $firstApproverId = $stage === \App\Support\HrApprovalStages::PENDING_FIRST
            ? ($routing['first_level_approver']?->id)
            : null;
        $hrApproverId = $routing['hr_approver']?->id;
        if (! $hrApproverId) {
            throw ValidationException::withMessages([
                'approval' => ['No active Admin (HR) approver is configured.'],
            ]);
        }
        $fileDetails = (string) $validated['reason'];

        $overtime = DB::transaction(function () use ($user, $dateYmd, $computed, $validated, $attachmentPath, $stage, $firstApproverId, $hrApproverId, $fileDetails) {
            $overtime = Overtime::create([
                'user_id' => $user->id,
                'date' => $dateYmd,
                'schedule_end' => $computed['schedule_end']->format('H:i:s'),
                'time_out' => null,
                'expected_end_time' => $computed['expected_end']->format('H:i:s'),
                'computed_minutes' => $computed['computed_minutes'],
                'computed_hours' => $computed['computed_hours'],
                'ph_ot_rule' => $validated['ph_ot_rule'],
                'ot_type' => $validated['category'],
                'reason' => $validated['reason'],
                'attachment_path' => $attachmentPath,
                'status' => Overtime::STATUS_PENDING,
                'created_by' => $user->id,
                'approval_stage' => $stage,
                'pending_approval' => true,
                'first_approver_id' => $firstApproverId,
                'second_approver_id' => $hrApproverId,
                'filed_at' => now(),
                'filed_by' => $user->id,
            ]);

            OvertimeApprovalAudit::create([
                'overtime_id' => $overtime->id,
                'actor_id' => $user->id,
                'employee_id' => $user->id,
                'action' => 'file',
                'details' => $fileDetails,
                'approver_role' => $this->hrRoleResolver->resolveForApprovalSubject($user)->badgeLabel(),
            ]);

            return $overtime;
        });

        return response()->json([
            'message' => 'Overtime request submitted successfully.',
            'overtime' => $this->mapOvertimeRowForEmployee($overtime->fresh([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ])),
        ], 201);
    }
}
