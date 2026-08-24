<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\OvertimeAutoApproveOverride;
use App\Models\Payslip;
use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Services\OrgApprovalWorkflowService;
use App\Support\EmployeeScheduleResolver;
use App\Support\HrApprovalStages;
use App\Support\OvertimeFilingRules;
use App\Support\OvertimeModuleCache;
use App\Support\PhPayrollReference;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-approves filed overtime for employees on the admin override list when they have complete
 * attendance (clock-in and clock-out) on that date, capped by max hours per day.
 */
class OvertimeAutoApproveService
{
    /** @var list<string> */
    private const HOLIDAY_OT_RULES = ['RH', 'RHRD', 'SH', 'SHRD', 'DH', 'DHRD'];

    public function __construct(
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly NotificationService $notificationService,
        private readonly EmailTriggerService $emailTrigger,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollRulesEngineService $payrollRulesEngine,
    ) {}

    public function activeOverrideForUser(int $userId): ?OvertimeAutoApproveOverride
    {
        return OvertimeAutoApproveOverride::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();
    }

    public function isEnabledForUser(int $userId): bool
    {
        return $this->activeOverrideForUser($userId) !== null;
    }

    /**
     * Complete-attendance gate: clock-in and clock-out required (kiosk logs and/or approved manual attendance).
     */
    public function employeeIsPresentForDate(int $userId, string $dateYmd): bool
    {
        if ($this->hasApprovedLeaveOnDate($userId, $dateYmd)) {
            return false;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        return OvertimeFilingRules::pastDateHasCompletedAttendance($userId, $dateYmd, $tz);
    }

    public function hasApprovedLeaveOnDate(int $userId, string $dateYmd): bool
    {
        return LeaveRequest::query()
            ->where('user_id', $userId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $dateYmd)
            ->where('end_date', '>=', $dateYmd)
            ->exists();
    }

    /**
     * Sum of already-approved OT hours for the employee on a calendar date (excludes one request).
     */
    public function approvedHoursOnDate(int $userId, string $dateYmd, ?int $excludeOvertimeId = null): float
    {
        $query = Overtime::query()
            ->where('user_id', $userId)
            ->whereDate('date', $dateYmd)
            ->where('status', Overtime::STATUS_APPROVED);

        if ($excludeOvertimeId !== null) {
            $query->whereKeyNot($excludeOvertimeId);
        }

        $total = 0.0;
        foreach ($query->get(['approved_ot_hours', 'computed_hours']) as $row) {
            $hours = $row->approved_ot_hours !== null
                ? (float) $row->approved_ot_hours
                : (float) ($row->computed_hours ?? 0);
            $total += max(0.0, $hours);
        }

        return round($total, 2);
    }

    /**
     * Before payroll, file + auto-approve standing OT for each complete-attendance day in range when the employee is on the override list.
     * Skips days that already have a non-rejected OT row or fail present / leave / payroll-lock gates.
     *
     * @return int Number of days where standing OT was created and auto-approved
     */
    public function ensureStandingOvertimeForPeriod(User $employee, Carbon $from, Carbon $to): int
    {
        $override = $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride) {
            return 0;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $today = Carbon::now($tz)->startOfDay();
        $cursor = $from->copy()->timezone($tz)->startOfDay();
        $end = $to->copy()->timezone($tz)->startOfDay();
        $created = 0;
        $synced = 0;
        $revoked = $this->revokeInvalidStandingOvertimeForPeriod($employee, $from, $to);

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($cursor->greaterThan($today)) {
                break;
            }
            $dateYmd = $cursor->toDateString();
            if ($this->syncStandingOvertimeForDate($employee, $dateYmd, $override)) {
                $synced++;
            }
            if ($this->ensureStandingOvertimeForDate($employee, $dateYmd, $override)) {
                $created++;
            }
            $cursor->addDay();
        }

        if ($created > 0 || $synced > 0 || $revoked > 0) {
            OvertimeModuleCache::flush();
            Log::info('overtime_standing_accrual.period', [
                'user_id' => (int) $employee->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days_created' => $created,
                'days_synced' => $synced,
                'days_revoked' => $revoked,
            ]);
        }

        return $created;
    }

    /**
     * Re-align existing standing OT hours when override max_hours_per_day changes (no new backfill).
     *
     * @return int Number of days where standing OT hours were updated or revoked
     */
    public function syncStandingOvertimeHoursForPeriod(User $employee, Carbon $from, Carbon $to): int
    {
        $override = $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride) {
            return 0;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $today = Carbon::now($tz)->startOfDay();
        $cursor = $from->copy()->timezone($tz)->startOfDay();
        $end = $to->copy()->timezone($tz)->startOfDay();
        $synced = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($cursor->greaterThan($today)) {
                break;
            }
            if ($this->syncStandingOvertimeForDate($employee, $cursor->toDateString(), $override)) {
                $synced++;
            }
            $cursor->addDay();
        }

        if ($synced > 0) {
            OvertimeModuleCache::flush();
        }

        return $synced;
    }

    /**
     * Reject standing OT rows when attendance is no longer complete (missing in/out).
     *
     * @return int Number of standing OT rows revoked
     */
    public function revokeInvalidStandingOvertimeForPeriod(User $employee, Carbon $from, Carbon $to): int
    {
        $override = $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride) {
            return 0;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $cursor = $from->copy()->timezone($tz)->startOfDay();
        $end = $to->copy()->timezone($tz)->startOfDay();
        $revoked = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            $revoked += $this->revokeInvalidStandingOvertimeForDate($employee, $cursor->toDateString());
            $cursor->addDay();
        }

        return $revoked;
    }

    public function revokeInvalidStandingOvertimeForDate(User $employee, string $dateYmd): int
    {
        if ($this->employeeIsPresentForDate((int) $employee->id, $dateYmd)) {
            return 0;
        }

        $rows = Overtime::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateYmd)
            ->where('status', Overtime::STATUS_APPROVED)
            ->get()
            ->filter(fn (Overtime $ot): bool => $this->isStandingOvertime($ot));

        if ($rows->isEmpty()) {
            return 0;
        }

        $revoked = 0;
        $now = now();
        $actor = $this->resolveHrApprover();
        foreach ($rows as $overtime) {
            if ($overtime->paid_payroll_run_id !== null && (int) $overtime->paid_payroll_run_id > 0) {
                continue;
            }

            $overtime->status = Overtime::STATUS_REJECTED;
            $overtime->pending_approval = false;
            $overtime->approval_stage = HrApprovalStages::REJECTED;
            $overtime->approved_ot_hours = null;
            $overtime->payable_ot_hours = 0;
            $overtime->approved_at = null;
            $overtime->approved_by = null;
            $overtime->remarks = 'Revoked: incomplete attendance (time-in and time-out required for auto-approve OT).';
            $overtime->save();

            if ($actor instanceof User) {
                OvertimeApprovalAudit::create([
                    'overtime_id' => $overtime->id,
                    'actor_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'action' => 'revoke_standing_incomplete_attendance',
                    'details' => $overtime->remarks,
                    'approver_role' => 'system',
                ]);
            }

            ReviewRequestCache::forget('overtime', (int) $overtime->id);
            $revoked++;
        }

        if ($revoked > 0) {
            Log::info('overtime_standing_accrual.revoked_incomplete_attendance', [
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
                'revoked_count' => $revoked,
            ]);
        }

        return $revoked;
    }

    private function isStandingOvertime(Overtime $overtime): bool
    {
        return str_starts_with(trim((string) ($overtime->reason ?? '')), 'Standing OT');
    }

    /**
     * Re-align approved standing OT hours when max_hours_per_day changes (or payroll re-runs).
     *
     * @return bool True when at least one standing row was updated or revoked
     */
    public function syncStandingOvertimeForDate(User $employee, string $dateYmd, ?OvertimeAutoApproveOverride $override = null): bool
    {
        $override ??= $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride || ! $override->is_active) {
            return false;
        }

        if (! $this->employeeIsPresentForDate((int) $employee->id, $dateYmd)) {
            return false;
        }

        $standingRows = Overtime::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateYmd)
            ->where('status', Overtime::STATUS_APPROVED)
            ->orderBy('id')
            ->get()
            ->filter(fn (Overtime $ot): bool => $this->isStandingOvertime($ot))
            ->values();

        if ($standingRows->isEmpty()) {
            return false;
        }

        try {
            $d = Carbon::parse($dateYmd)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException) {
            return false;
        }

        $maxHours = max(0.0, (float) ($override->max_hours_per_day ?? OvertimeAutoApproveOverride::DEFAULT_MAX_HOURS_PER_DAY));
        $totalApproved = $this->approvedHoursOnDate((int) $employee->id, $dateYmd);
        $standingApproved = round($standingRows->sum(function (Overtime $ot): float {
            return (float) ($ot->approved_ot_hours ?? $ot->computed_hours ?? 0);
        }), 2);
        $nonStandingApproved = round(max(0.0, $totalApproved - $standingApproved), 2);
        $targetHours = round(max(0.0, $maxHours - $nonStandingApproved), 2);

        $changed = false;
        $primary = $standingRows->first();
        foreach ($standingRows->slice(1) as $duplicate) {
            if ($this->rejectStandingOvertime($duplicate, $employee, 'Revoked: duplicate standing OT row.')) {
                $changed = true;
            }
        }

        if ($targetHours <= 0) {
            if ($this->rejectStandingOvertime($primary, $employee, 'Revoked: daily auto-approve allowance already used by other OT.')) {
                $changed = true;
            }

            return $changed;
        }

        if ($primary->paid_payroll_run_id !== null && (int) $primary->paid_payroll_run_id > 0) {
            return $changed;
        }

        $expectedRule = $this->resolveExpectedPhOtRule($employee, $dateYmd);
        $currentHours = round((float) ($primary->approved_ot_hours ?? $primary->computed_hours ?? 0), 2);
        $currentRule = strtoupper(trim((string) ($primary->ph_ot_rule ?? 'ORD')));
        $hoursNeedSync = abs($currentHours - $targetHours) >= 0.01;
        $ruleNeedsSync = $currentRule !== $expectedRule;

        if (! $hoursNeedSync && ! $ruleNeedsSync) {
            return $changed;
        }

        $approvedWindow = $this->approvedWindowForHours($primary, $targetHours, $dateYmd);
        $computedMinutes = (int) round($targetHours * 60);
        $details = $hoursNeedSync && $ruleNeedsSync
            ? sprintf(
                'Standing OT synced to %.2f hrs (%s; daily limit %.2f hrs; %.2f from other approved OT).',
                $targetHours,
                $expectedRule,
                $maxHours,
                $nonStandingApproved,
            )
            : ($hoursNeedSync
                ? sprintf(
                    'Standing OT synced to %.2f hrs (daily limit %.2f hrs; %.2f from other approved OT).',
                    $targetHours,
                    $maxHours,
                    $nonStandingApproved,
                )
                : sprintf(
                    'Standing OT day type synced to %s (scope-aware holiday rule).',
                    $expectedRule,
                ));

        if ($hoursNeedSync) {
            $primary->expected_end_time = $approvedWindow['end'];
            $primary->computed_minutes = $computedMinutes;
            $primary->computed_hours = $targetHours;
            $primary->approved_ot_start = $approvedWindow['start'];
            $primary->approved_ot_end = $approvedWindow['end'];
            $primary->approved_ot_hours = $targetHours;
            $primary->payable_ot_hours = $targetHours;
        }

        $primary->ph_ot_rule = $expectedRule;
        $primary->remarks = $details;
        $primary->save();

        OvertimeApprovalAudit::create([
            'overtime_id' => $primary->id,
            'actor_id' => $this->resolveHrApprover()?->id,
            'employee_id' => $employee->id,
            'action' => $ruleNeedsSync && ! $hoursNeedSync ? 'sync_standing_rule' : 'sync_standing_hours',
            'details' => $details,
            'approver_role' => 'system',
        ]);

        $this->runStandingHoursSyncSideEffects($primary->fresh(['user']));

        Log::info('overtime_standing_accrual.synced', [
            'overtime_id' => (int) $primary->id,
            'user_id' => (int) $employee->id,
            'date' => $dateYmd,
            'previous_hours' => $currentHours,
            'target_hours' => $targetHours,
            'previous_ph_ot_rule' => $currentRule,
            'target_ph_ot_rule' => $expectedRule,
            'max_hours_per_day' => $maxHours,
            'hours_synced' => $hoursNeedSync,
            'rule_synced' => $ruleNeedsSync,
        ]);

        return true;
    }

    public function ensureStandingOvertimeForDate(User $employee, string $dateYmd, ?OvertimeAutoApproveOverride $override = null): bool
    {
        $override ??= $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride) {
            return false;
        }

        if (Overtime::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateYmd)
            ->where('status', '!=', Overtime::STATUS_REJECTED)
            ->exists()) {
            return false;
        }

        if (! $this->employeeIsPresentForDate((int) $employee->id, $dateYmd)) {
            return false;
        }

        $maxHours = max(0.0, (float) ($override->max_hours_per_day ?? OvertimeAutoApproveOverride::DEFAULT_MAX_HOURS_PER_DAY));
        $hours = round(max(0.0, $maxHours - $this->approvedHoursOnDate((int) $employee->id, $dateYmd)), 2);
        if ($hours <= 0) {
            return false;
        }

        try {
            $d = Carbon::parse($dateYmd)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException) {
            return false;
        }

        $hrApprover = $this->resolveHrApprover();
        if (! $hrApprover instanceof User) {
            Log::warning('overtime_standing_accrual.skipped_no_hr_approver', [
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
            ]);

            return false;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $schedule = EmployeeScheduleResolver::resolveForDate($employee, $dateYmd);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateYmd, $tz));
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;
        $schedEndStr = is_array($daySchedule) && ! empty($daySchedule['out'])
            ? trim((string) $daySchedule['out'])
            : '17:00';

        $start = Carbon::parse($dateYmd.' '.$schedEndStr, $tz);
        $end = $start->copy()->addMinutes((int) round($hours * 60));
        $computedMinutes = (int) $start->diffInMinutes($end);
        if ($computedMinutes <= 0) {
            return false;
        }

        $expectedRule = $this->resolveExpectedPhOtRule($employee, $dateYmd);
        $reason = 'Standing OT (auto-approve override — complete attendance day).';

        $overtime = Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => $dateYmd,
            'schedule_end' => $start->format('H:i:s'),
            'time_out' => null,
            'expected_end_time' => $end->format('H:i:s'),
            'approved_ot_start' => null,
            'approved_ot_end' => null,
            'approved_ot_hours' => null,
            'actual_rendered_ot_hours' => 0,
            'payable_ot_hours' => 0,
            'unapproved_ot_hours' => 0,
            'computed_minutes' => $computedMinutes,
            'computed_hours' => round($computedMinutes / 60, 2),
            'ph_ot_rule' => $expectedRule,
            'ot_type' => 'regular',
            'reason' => $reason,
            'status' => Overtime::STATUS_PENDING,
            'created_by' => $employee->id,
            'approval_stage' => HrApprovalStages::PENDING_SECOND,
            'pending_approval' => true,
            'second_approver_id' => $hrApprover->id,
            'filed_at' => now(),
            'filed_by' => $employee->id,
        ]);

        OvertimeApprovalAudit::create([
            'overtime_id' => $overtime->id,
            'actor_id' => $employee->id,
            'employee_id' => $employee->id,
            'action' => 'file_standing',
            'details' => $reason,
            'approver_role' => $this->hrRoleResolver->resolveForApprovalSubject($employee)->badgeLabel(),
        ]);

        $this->approvalWorkflowService->ensureRecordsForRequest(
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $employee,
            $employee,
        );

        if (! $this->tryAutoApproveAfterFiling($overtime->fresh(), $employee)) {
            Log::info('overtime_standing_accrual.pending_after_file', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Attempt final auto-approval after filing. Returns true when the request was auto-approved.
     * Approved hours are capped by remaining daily allowance (max_hours_per_day − already approved).
     */
    public function tryAutoApproveAfterFiling(Overtime $overtime, User $employee): bool
    {
        $override = $this->activeOverrideForUser((int) $employee->id);
        if (! $override instanceof OvertimeAutoApproveOverride) {
            return false;
        }

        $dateYmd = $overtime->date?->toDateString();
        if ($dateYmd === null) {
            return false;
        }

        if (! $this->employeeIsPresentForDate((int) $employee->id, $dateYmd)) {
            Log::info('overtime_auto_approve.skipped_incomplete_attendance', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
            ]);

            return false;
        }

        // Holiday scope (same basis as OT filing defaults / payroll): holiday OT rates only when covered.
        $expectedRule = $this->resolveExpectedPhOtRule($employee, $dateYmd);
        $filedRule = strtoupper(trim((string) ($overtime->ph_ot_rule ?? 'ORD')));
        if ($filedRule === '' || ! in_array($filedRule, PhPayrollReference::OT_RULE_CODES, true)) {
            $filedRule = 'ORD';
        }

        if ($this->isHolidayOtRule($filedRule) && ! $this->holidayOtRulesCompatible($filedRule, $expectedRule)) {
            Log::info('overtime_auto_approve.skipped_holiday_scope_mismatch', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
                'filed_ph_ot_rule' => $filedRule,
                'expected_ph_ot_rule' => $expectedRule,
            ]);

            return false;
        }

        $maxHours = max(0.0, (float) ($override->max_hours_per_day ?? OvertimeAutoApproveOverride::DEFAULT_MAX_HOURS_PER_DAY));
        $alreadyApproved = $this->approvedHoursOnDate((int) $employee->id, $dateYmd, (int) $overtime->id);
        $remaining = round(max(0.0, $maxHours - $alreadyApproved), 2);

        if ($remaining <= 0) {
            Log::info('overtime_auto_approve.skipped_daily_limit', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
                'max_hours_per_day' => $maxHours,
                'already_approved_hours' => $alreadyApproved,
            ]);

            return false;
        }

        $requestedHours = round(max(0.0, (float) ($overtime->computed_hours ?? 0)), 2);
        if ($requestedHours <= 0) {
            return false;
        }

        $approveHours = round(min($requestedHours, $remaining), 2);
        $capped = $approveHours < $requestedHours;

        try {
            $d = Carbon::parse($dateYmd)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException) {
            Log::info('overtime_auto_approve.skipped_payroll_locked', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
                'date' => $dateYmd,
            ]);

            return false;
        }

        $actor = $this->resolveAutoApproveActor($overtime);
        if (! $actor instanceof User) {
            Log::warning('overtime_auto_approve.skipped_no_actor', [
                'overtime_id' => (int) $overtime->id,
                'user_id' => (int) $employee->id,
            ]);

            return false;
        }

        $details = $capped
            ? sprintf(
                'Auto-approved %.2f of %.2f hours (daily limit %.2f hrs; %.2f already approved; OT rule %s).',
                $approveHours,
                $requestedHours,
                $maxHours,
                $alreadyApproved,
                $expectedRule,
            )
            : sprintf(
                'Auto-approved %.2f hours (daily limit %.2f hrs; OT rule %s — present + holiday scope).',
                $approveHours,
                $maxHours,
                $expectedRule,
            );

        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $approvedWindow = $this->approvedWindowForHours($overtime, $approveHours, $dateYmd);

        DB::transaction(function () use ($overtime, $employee, $actor, $now, $roleLabel, $details, $approveHours, $approvedWindow, $expectedRule): void {
            OrgApprovalRecord::query()
                ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
                ->where('request_id', (int) $overtime->id)
                ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                ->update([
                    'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                    'remarks' => $details,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $actor->display_name ?? $actor->name,
                    'updated_at' => $now,
                ]);

            $overtime->status = Overtime::STATUS_APPROVED;
            $overtime->pending_approval = false;
            $overtime->approval_stage = HrApprovalStages::APPROVED;
            $overtime->second_approver_id = $actor->id;
            $overtime->second_approved_at = $now;
            $overtime->approved_by = $actor->id;
            $overtime->approved_at = $now;
            $overtime->approved_ot_start = $approvedWindow['start'];
            $overtime->approved_ot_end = $approvedWindow['end'];
            $overtime->approved_ot_hours = $approveHours;
            // Scope-aware day type — same holiday coverage as payroll OT multipliers.
            $overtime->ph_ot_rule = $expectedRule;
            // Ensure payslip/payroll can read payable hours immediately (approved basis).
            $overtime->payable_ot_hours = $approveHours;
            $overtime->remarks = $details;
            $overtime->locked_at = $now;
            $overtime->updated_by = $actor->id;
            $overtime->save();

            OvertimeApprovalAudit::create([
                'overtime_id' => $overtime->id,
                'actor_id' => $actor->id,
                'employee_id' => $employee->id,
                'action' => 'approve_final',
                'details' => $details,
                'approver_role' => $roleLabel,
            ]);
        });

        $this->runPostApprovalSideEffects($overtime->fresh(['user']), $actor);

        Log::info('overtime_auto_approve.approved', [
            'overtime_id' => (int) $overtime->id,
            'user_id' => (int) $employee->id,
            'actor_id' => (int) $actor->id,
            'date' => $dateYmd,
            'approved_hours' => $approveHours,
            'requested_hours' => $requestedHours,
            'max_hours_per_day' => $maxHours,
            'capped' => $capped,
            'ph_ot_rule' => $expectedRule,
            'filed_ph_ot_rule' => $filedRule,
        ]);

        return true;
    }

    /**
     * Scope-aware PH OT rule for the employee/date (mirrors EmployeeOvertimeController::detectDefaultPhOtRule).
     */
    public function resolveExpectedPhOtRule(User $user, string $dateYmd): string
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $schedule = EmployeeScheduleResolver::resolve($user);
        $carbonDate = Carbon::parse($dateYmd, $tz);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($carbonDate);
        $daySchedule = $schedule[$dayKey] ?? null;
        $isRestDay = $daySchedule === null;

        $holidayType = $this->payrollRulesEngine->getHolidayTypeForUser($user, $dateYmd);

        if ($isRestDay && $holidayType === 'regular') {
            return 'RHRD';
        }
        if ($isRestDay && $holidayType === 'special') {
            return 'SHRD';
        }
        if ($isRestDay && $holidayType === 'double') {
            return 'DHRD';
        }
        if ($holidayType === 'regular') {
            return 'RH';
        }
        if ($holidayType === 'special') {
            return 'SH';
        }
        if ($holidayType === 'double') {
            return 'DH';
        }
        if ($isRestDay) {
            return 'RD';
        }

        return 'ORD';
    }

    public function isHolidayOtRule(string $rule): bool
    {
        return in_array(strtoupper($rule), self::HOLIDAY_OT_RULES, true);
    }

    /**
     * Filed holiday OT is allowed to auto-approve only when scope-aware expected rule is the same holiday family
     * (or an exact match including rest-day variants).
     */
    public function holidayOtRulesCompatible(string $filedRule, string $expectedRule): bool
    {
        $filed = strtoupper($filedRule);
        $expected = strtoupper($expectedRule);
        if ($filed === $expected) {
            return true;
        }

        $filedFamily = $this->holidayFamily($filed);
        $expectedFamily = $this->holidayFamily($expected);

        return $filedFamily !== null && $filedFamily === $expectedFamily;
    }

    private function holidayFamily(string $rule): ?string
    {
        return match (strtoupper($rule)) {
            'RH', 'RHRD' => 'regular',
            'SH', 'SHRD' => 'special',
            'DH', 'DHRD' => 'double',
            default => null,
        };
    }

    /**
     * @return array{start: string|null, end: string|null}
     */
    private function approvedWindowForHours(Overtime $overtime, float $approveHours, string $dateYmd): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $startRaw = $overtime->schedule_end?->format('H:i:s');
        if ($startRaw === null) {
            return ['start' => null, 'end' => null];
        }

        $start = Carbon::parse($dateYmd.' '.$startRaw, $tz);
        $end = $start->copy()->addMinutes((int) round($approveHours * 60));

        return [
            'start' => $start->format('H:i:s'),
            'end' => $end->format('H:i:s'),
        ];
    }

    private function resolveAutoApproveActor(Overtime $overtime): ?User
    {
        if ($overtime->second_approver_id) {
            $hr = User::query()->find((int) $overtime->second_approver_id);
            if ($hr instanceof User && $hr->is_active) {
                return $hr;
            }
        }

        return $this->resolveHrApprover();
    }

    private function resolveHrApprover(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('id')
            ->get()
            ->first(fn (User $candidate): bool => $this->hrRoleResolver->resolve($candidate)->value === 'admin_hr');
    }

    private function rejectStandingOvertime(Overtime $overtime, User $employee, string $reason): bool
    {
        if ($overtime->paid_payroll_run_id !== null && (int) $overtime->paid_payroll_run_id > 0) {
            return false;
        }

        $overtime->status = Overtime::STATUS_REJECTED;
        $overtime->pending_approval = false;
        $overtime->approval_stage = HrApprovalStages::REJECTED;
        $overtime->approved_ot_hours = null;
        $overtime->payable_ot_hours = 0;
        $overtime->approved_at = null;
        $overtime->approved_by = null;
        $overtime->remarks = $reason;
        $overtime->save();

        $actor = $this->resolveHrApprover();
        if ($actor instanceof User) {
            OvertimeApprovalAudit::create([
                'overtime_id' => $overtime->id,
                'actor_id' => $actor->id,
                'employee_id' => $employee->id,
                'action' => 'revoke_standing',
                'details' => $reason,
                'approver_role' => 'system',
            ]);
        }

        ReviewRequestCache::forget('overtime', (int) $overtime->id);
        $this->runStandingHoursSyncSideEffects($overtime->fresh(['user']));

        return true;
    }

    private function runStandingHoursSyncSideEffects(Overtime $overtime): void
    {
        $employee = $overtime->user;
        $dateKey = $overtime->date?->toDateString();
        if (! $employee instanceof User || $dateKey === null) {
            return;
        }

        ReviewRequestCache::forget('overtime', (int) $overtime->id);
        OvertimeModuleCache::flush();
        ReportsCacheService::invalidateAttendanceCache((int) $employee->id, $dateKey);
        $this->clearAffectedDraftPayrollSnapshots($overtime);
    }

    private function runPostApprovalSideEffects(Overtime $overtime, User $actor): void
    {
        $employee = $overtime->user;
        $dateKey = $overtime->date?->toDateString();
        if (! $employee instanceof User || $dateKey === null) {
            return;
        }

        // ponytail: skip rendered/payable resync — syncActualClockOutToFiledOvertime applies min(rendered, approved)
        // and would zero payable_ot_hours when the employee left on schedule but OT was approved on filing.

        $this->runStandingHoursSyncSideEffects($overtime);

        $this->notificationService->notifyRequester(
            $employee,
            $overtime,
            'overtime',
            'overtime.final_approved',
            'Overtime request approved',
            'Your overtime request has been auto-approved.',
            '/employee/overtime?request_id='.$overtime->id,
        );
        $this->emailTrigger->overtimeFinalApproved($overtime);
    }

    private function clearAffectedDraftPayrollSnapshots(Overtime $overtime): void
    {
        $date = $overtime->date?->toDateString();
        if ($date === null || (int) $overtime->user_id <= 0) {
            return;
        }

        // Draft + generated are regenerable snapshots (same as PayslipService::draftSnapshotStatuses).
        // Finalized/emailed payslips are left alone.
        $drafts = Payslip::query()
            ->where('user_id', (int) $overtime->user_id)
            ->whereIn('status', [Payslip::STATUS_DRAFT, Payslip::STATUS_GENERATED])
            ->whereNull('voided_at')
            ->whereDate('pay_period_start', '<=', $date)
            ->whereDate('pay_period_end', '>=', $date)
            ->get(['id', 'company_id', 'pay_period_start', 'pay_period_end', 'payroll_batch_run_id']);

        if ($drafts->isEmpty()) {
            return;
        }

        $draftIds = $drafts->pluck('id')->map(fn ($id) => (int) $id)->all();
        Payslip::query()->whereIn('id', $draftIds)->delete();

        $batchIds = $drafts->pluck('payroll_batch_run_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($batchIds !== []) {
            PayrollBatchRun::query()
                ->whereIn('id', $batchIds)
                ->whereIn('status', [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FAILED])
                ->update([
                    'error_message' => 'Needs recompute: overtime request '.$overtime->id.' was approved. Regenerate payslips to include OT.',
                ]);
        }

        foreach ($drafts as $draft) {
            PayrollBatchRun::query()
                ->where('status', PayrollBatchRun::STATUS_DRAFT)
                ->whereDate('pay_period_start', $draft->pay_period_start?->toDateString() ?? $date)
                ->whereDate('pay_period_end', $draft->pay_period_end?->toDateString() ?? $date)
                ->when($draft->company_id !== null, fn ($q) => $q->where('company_id', (int) $draft->company_id))
                ->update([
                    'error_message' => 'Needs recompute: overtime request '.$overtime->id.' was approved. Regenerate payslips to include OT.',
                ]);
        }

        Log::info('payroll_draft_cache_cleared_for_overtime', [
            'overtime_id' => (int) $overtime->id,
            'employee_id' => (int) $overtime->user_id,
            'date' => $date,
            'deleted_draft_payslip_ids' => $draftIds,
            'affected_batch_ids' => $batchIds,
        ]);
    }
}
