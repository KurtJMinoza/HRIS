<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\NotificationService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OtDetectionService;
use App\Services\OvertimeApprovalService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\EmployeeScheduleResolver;
use App\Support\OvertimeModuleCache;
use App\Support\PhPayrollReference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeOvertimeController extends Controller
{
    public function __construct(
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly OvertimeApprovalService $overtimeApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OtDetectionService $otDetectionService,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
        private readonly NotificationService $notificationService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly \App\Services\EmailTriggerService $emailTrigger,
    ) {}

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Flexible OT filing quantities from user-provided time range.
     *
     * @return array{schedule_end: \Carbon\Carbon, expected_end: \Carbon\Carbon, computed_minutes: int, computed_hours: float}
     */
    private function computeOvertimeRequestQuantities(string $dateYmd, string $startTimeHmi, string $endTimeHmi): array
    {
        $tz = $this->attendanceTimezone();
        $start = Carbon::parse($dateYmd.' '.$startTimeHmi, $tz);
        $end = Carbon::parse($dateYmd.' '.$endTimeHmi, $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $computedMinutes = (int) $start->diffInMinutes($end);

        if ($computedMinutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be later than start time.'],
            ]);
        }

        $computedHours = round($computedMinutes / 60, 2);

        return [
            'schedule_end' => $start,
            'expected_end' => $end,
            'computed_minutes' => $computedMinutes,
            'computed_hours' => $computedHours,
        ];
    }

    private function validateNoOverlappingOvertime(
        User $user,
        string $dateYmd,
        string $startTimeHmi,
        string $endTimeHmi,
        ?int $ignoreId = null
    ): void {
        $tz = $this->attendanceTimezone();
        $newStart = Carbon::parse($dateYmd.' '.$startTimeHmi, $tz);
        $newEnd = Carbon::parse($dateYmd.' '.$endTimeHmi, $tz);
        if ($newEnd->lessThanOrEqualTo($newStart)) {
            $newEnd->addDay();
        }

        $existing = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateYmd)
            ->where('status', '!=', Overtime::STATUS_REJECTED)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'schedule_end', 'expected_end_time', 'status']);

        foreach ($existing as $row) {
            if ($row->schedule_end === null || $row->expected_end_time === null) {
                continue;
            }
            $existingStart = Carbon::parse($dateYmd.' '.$row->schedule_end->format('H:i:s'), $tz);
            $existingEnd = Carbon::parse($dateYmd.' '.$row->expected_end_time->format('H:i:s'), $tz);
            if ($existingEnd->lessThanOrEqualTo($existingStart)) {
                $existingEnd->addDay();
            }
            if ($newStart->lessThan($existingEnd) && $existingStart->lessThan($newEnd)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Overtime request overlaps an existing OT window for this date. File only the uncovered time, such as after the previous approved end.'],
                ]);
            }
        }
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
        if ($user->isSystemAccessOnly()) {
            throw ValidationException::withMessages([
                'user' => ['System access accounts cannot file employee overtime requests.'],
            ]);
        }

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
            'requested_by_name' => $subject->display_name,
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
            if ($remarks !== null) {
                $steps[$i]['remarks'] = $remarks;
            }
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>|null  $detected
     * @return array<int, array{key:string,start_time:string,end_time:string,minutes:int,hours:float,label:string}>
     */
    private function mapDetectedSegmentsForFiling(?array $detected): array
    {
        if (! is_array($detected)) {
            return [];
        }

        $segments = [];
        $scheduleStart = is_string($detected['schedule_start'] ?? null) ? $detected['schedule_start'] : null;
        $scheduleEnd = is_string($detected['schedule_end'] ?? null) ? $detected['schedule_end'] : null;

        $pre = $detected['pre_shift'] ?? null;
        if (is_array($pre) && $scheduleStart && is_string($pre['clock_in'] ?? null)) {
            $minutes = max(0, (int) ($pre['minutes'] ?? 0));
            if ($minutes > 0) {
                $segments[] = [
                    'key' => 'pre_shift',
                    'start_time' => Carbon::parse($pre['clock_in'])->format('H:i'),
                    'end_time' => Carbon::parse($scheduleStart)->format('H:i'),
                    'minutes' => $minutes,
                    'hours' => round($minutes / 60, 2),
                    'label' => (string) ($pre['label'] ?? ''),
                ];
            }
        }

        $post = $detected['post_shift'] ?? null;
        if (is_array($post) && $scheduleEnd && is_string($post['work_end'] ?? null)) {
            $minutes = max(0, (int) ($post['minutes'] ?? 0));
            if ($minutes > 0) {
                $segments[] = [
                    'key' => 'post_shift',
                    'start_time' => Carbon::parse($scheduleEnd)->format('H:i'),
                    'end_time' => Carbon::parse($post['work_end'])->format('H:i'),
                    'minutes' => $minutes,
                    'hours' => round($minutes / 60, 2),
                    'label' => (string) ($post['label'] ?? ''),
                ];
            }
        }

        return $segments;
    }

    private function canDeleteOvertimeRequest(User $actor, Overtime $overtime): bool
    {
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return false;
        }

        $actorId = (int) $actor->id;

        return $actorId === (int) $overtime->filed_by
            || $actorId === (int) $overtime->user_id;
    }

    /**
     * Keep My Filings action affordances aligned with the admin/all-filings list.
     *
     * @return array<string, mixed>
     */
    private function overtimeActorFlags(Overtime $overtime, ?User $actor): array
    {
        if (! $actor instanceof User) {
            return [
                'actor_can_approve' => false,
                'actor_can_reject' => false,
                'actor_can_delete' => false,
            ];
        }

        return [
            'actor_can_approve' => $this->overtimeApprovalService->canApprove($actor, $overtime),
            'actor_can_reject' => $this->overtimeApprovalService->canReject($actor, $overtime),
            'actor_can_delete' => $this->canDeleteOvertimeRequest($actor, $overtime),
        ];
    }

    private function deriveBadgeColor(Overtime $overtime): string
    {
        if ($overtime->status === Overtime::STATUS_REJECTED || $overtime->rejected_at !== null) {
            return 'red';
        }
        if ($overtime->status === Overtime::STATUS_APPROVED) {
            return 'green';
        }

        return 'orange';
    }

    private function deriveCurrentStepName(Overtime $overtime): ?string
    {
        if ($overtime->status === Overtime::STATUS_APPROVED || $overtime->status === Overtime::STATUS_REJECTED || $overtime->rejected_at) {
            return null;
        }

        return $this->overtimeApprovalService->buildCurrentStepLabel($overtime);
    }

    private function deriveCurrentApproverName(Overtime $overtime): ?string
    {
        $progress = $this->overtimeApprovalService->buildApprovalProgress($overtime);
        foreach ($progress as $step) {
            if (($step['status'] ?? '') === 'current') {
                return $step['approver_name'] ?? null;
            }
        }

        return null;
    }

    private function derivePendingDisplayStatus(Overtime $overtime, ?User $currentApprover): string
    {
        $step = $this->deriveCurrentStepName($overtime);

        if ($step) {
            return 'Pending '.$step.' Approval';
        }

        return 'Pending';
    }

    private function deriveStepNameFromApprover(?User $approver): ?string
    {
        if ($approver === null) {
            return null;
        }

        $progress = $this->overtimeApprovalService->buildApprovalProgress($approver);

        return null;
    }

    private function deriveTableStepName(string $approvalStage): ?string
    {
        return match ($approvalStage) {
            \App\Support\HrApprovalStages::PENDING_FIRST => 'Department Head',
            \App\Support\HrApprovalStages::PENDING_SECOND => 'HR',
            default => null,
        };
    }

    private function deriveTableDisplayStatus(string $status, ?string $stepName): string
    {
        if ($status === Overtime::STATUS_APPROVED) {
            return 'Approved';
        }
        if ($status === Overtime::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($stepName) {
            return 'Pending '.$stepName.' Approval';
        }

        return 'Pending';
    }

    private function detectDefaultPhOtRule(User $user, string $dateYmd): string
    {
        $schedule = EmployeeScheduleResolver::resolve($user);
        $carbonDate = Carbon::parse($dateYmd, $this->attendanceTimezone());
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($carbonDate);
        $daySchedule = $schedule[$dayKey] ?? null;
        $isRestDay = $daySchedule === null;

        $holiday = \App\Models\Holiday::query()
            ->whereDate('date', $dateYmd)
            ->first();

        $holidayType = $holiday?->type ?? null;

        if ($isRestDay && $holidayType === 'regular') {
            return 'RHRD';
        }
        if ($isRestDay && in_array($holidayType, ['special', 'company', 'special_working'], true)) {
            return 'SHRD';
        }
        if ($holidayType === 'regular') {
            return 'RH';
        }
        if (in_array($holidayType, ['special', 'company', 'special_working'], true)) {
            return 'SH';
        }
        if ($isRestDay) {
            return 'RD';
        }

        return 'ORD';
    }

    private function mapOvertimeRowForEmployee(Overtime $o, ?User $actor = null): array
    {
        $o->loadMissing([
            'approvedBy:id,name,first_name,middle_name,last_name,suffix',
            'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
            'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
        ]);

        $subject = $o->user;
        $requesterMeta = $subject instanceof User ? $this->requesterMetaForSubject($subject) : [];

        return array_merge([
            'id' => $o->id,
            'date' => $o->date?->toDateString(),
            'schedule_end' => $o->schedule_end?->format('H:i'),
            'expected_end_time' => $o->expected_end_time?->format('H:i'),
            'approved_ot_start' => $o->approved_ot_start?->format('H:i'),
            'approved_ot_end' => $o->approved_ot_end?->format('H:i'),
            'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
            'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
            'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
            'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
            'overtime_reduction_reason' => $o->overtime_reduction_reason,
            'start_time' => $o->schedule_end?->format('H:i'),
            'end_time' => $o->expected_end_time?->format('H:i'),
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
            'approved_by_name' => $o->approvedBy?->display_name,
            'created_at' => $o->created_at?->toIso8601String(),
            'filed_at' => $o->filed_at?->toIso8601String(),
            'display_status' => $this->overtimeApprovalService->deriveDisplayStatusLabel($o),
            'display_badge_color' => $this->deriveBadgeColor($o),
            'current_step_name' => $this->deriveCurrentStepName($o),
            'current_approver_name' => $this->deriveCurrentApproverName($o),
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
                    'actor_name' => $a->actor?->display_name,
                ];
            })->values()->all(),
        ], $requesterMeta, $this->overtimeActorFlags($o, $actor), PhPayrollReference::ruleMetaForOvertime($o->ph_ot_rule));
    }

    /**
     * Lightweight employee overtime row for request tables.
     *
     * Keep this payload intentionally small: no approval history, attachment URLs,
     * employee profile, attendance payload, audit logs, or payroll recomputation.
     *
     * @return array<string, mixed>
     */
    private function mapOvertimeTableRowForEmployee(Overtime $o, User $actor): array
    {
        $currentApprover = null;
        if (($o->approval_stage ?? null) === \App\Support\HrApprovalStages::PENDING_FIRST) {
            $currentApprover = $o->firstApprover;
        } elseif (($o->approval_stage ?? null) === \App\Support\HrApprovalStages::PENDING_SECOND) {
            $currentApprover = $o->secondApprover;
        }

        $canCancel = $this->canDeleteOvertimeRequest($actor, $o);
        $canEdit = $o->status === Overtime::STATUS_PENDING
            && (! $o->approval_stage || $o->approval_stage === \App\Support\HrApprovalStages::PENDING_FIRST);
        $reason = trim((string) ($o->reason ?? ''));
        $reasonSummary = null;
        if ($reason !== '') {
            $reasonSummary = strlen($reason) > 140 ? substr($reason, 0, 137).'...' : $reason;
        }
        $stepName = $o->status === Overtime::STATUS_PENDING
            ? $this->deriveTableStepName((string) ($o->approval_stage ?? ''))
            : null;
        $displayStatus = $this->deriveTableDisplayStatus($o->status, $stepName);

        return array_merge([
            'id' => (int) $o->id,
            'overtime_date' => $o->date->toDateString(),
            'requested_start_time' => $o->schedule_end?->format('H:i'),
            'requested_end_time' => $o->expected_end_time?->format('H:i'),
            'requested_hours' => (float) ($o->computed_hours ?? 0),
            'approved_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
            'status' => $o->status,
            'approval_stage' => $o->approval_stage,
            'current_step_name' => $stepName,
            'current_approver_name' => $currentApprover?->display_name,
            'display_badge_color' => $this->deriveBadgeColor($o),
            'reason_summary' => $reasonSummary,
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
            'can_cancel' => $canCancel,
            'can_view' => true,
            'can_edit' => $canEdit,

            // Compatibility aliases used by the existing shared table.
            'date' => $o->date->toDateString(),
            'schedule_end' => $o->schedule_end?->format('H:i'),
            'expected_end_time' => $o->expected_end_time?->format('H:i'),
            'start_time' => $o->schedule_end?->format('H:i'),
            'end_time' => $o->expected_end_time?->format('H:i'),
            'computed_hours' => (float) ($o->computed_hours ?? 0),
            'computed_minutes' => $o->computed_minutes,
            'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
            'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
            'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
            'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
            'overtime_reduction_reason' => $o->overtime_reduction_reason,
            'ot_type' => $o->ot_type,
            'reason' => $reasonSummary,
            'display_status' => $displayStatus,
            'filed_at' => $o->filed_at?->toIso8601String(),
            'actor_can_delete' => $canCancel,
            'actor_can_approve' => false,
            'actor_can_reject' => false,
        ], PhPayrollReference::ruleMetaForOvertime($o->ph_ot_rule));
    }

    /**
     * @return array{per_page:int,page:int}
     */
    private function employeeOvertimePagination(Request $request): array
    {
        $allowed = [10, 15, 25, 50];
        $perPage = (int) $request->query('per_page', 15);
        if (! in_array($perPage, $allowed, true)) {
            $perPage = 15;
        }

        return [
            'per_page' => $perPage,
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }

    /**
     * @return array{status:string,from_date:?string,to_date:?string,date_filter:string,search:string}
     */
    private function employeeOvertimeFilters(Request $request): array
    {
        $status = strtolower((string) $request->query('status', 'all'));
        if (! in_array($status, ['all', Overtime::STATUS_PENDING, Overtime::STATUS_APPROVED, Overtime::STATUS_REJECTED, 'cancelled'], true)) {
            $status = 'all';
        }

        $from = $request->query('from_date');
        $to = $request->query('to_date');
        $from = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null;
        $to = is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null;
        $hasExplicitRange = $from !== null || $to !== null;

        $dateFilter = strtolower((string) $request->query('date_filter', $hasExplicitRange ? 'range' : 'recent'));
        if (! in_array($dateFilter, ['recent', 'range', 'current_month', 'last_month', 'this_year', 'all'], true)) {
            $dateFilter = $hasExplicitRange ? 'range' : 'recent';
        }

        $today = Carbon::now($this->attendanceTimezone());
        if ($dateFilter === 'current_month') {
            $from = $today->copy()->startOfMonth()->toDateString();
            $to = $today->copy()->endOfMonth()->toDateString();
        } elseif ($dateFilter === 'last_month') {
            $last = $today->copy()->subMonthNoOverflow();
            $from = $last->copy()->startOfMonth()->toDateString();
            $to = $last->copy()->endOfMonth()->toDateString();
        } elseif ($dateFilter === 'this_year') {
            $from = $today->copy()->startOfYear()->toDateString();
            $to = $today->copy()->endOfYear()->toDateString();
        } elseif ($dateFilter === 'recent') {
            $from = $today->copy()->subDays(60)->toDateString();
            $to = $today->toDateString();
        }

        $search = trim((string) $request->query('search', ''));
        if (strlen($search) < 2) {
            $search = '';
        }

        return [
            'status' => $status,
            'from_date' => $from,
            'to_date' => $to,
            'date_filter' => $dateFilter,
            'search' => $search,
        ];
    }

    private function applyEmployeeOvertimeFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('date', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('reason', 'like', $needle)
                    ->orWhere('ot_type', 'like', $needle)
                    ->orWhere('status', 'like', $needle);
            });
        }
    }

    /**
     * Cached lightweight employee request table.
     */
    public function myRequestsTable(Request $request): JsonResponse
    {
        $started = microtime(true);
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);
        $this->beginEmployeeOvertimeQueryLog();

        $paginationInput = $this->employeeOvertimePagination($request);
        $filters = $this->employeeOvertimeFilters($request);
        $filtersHash = md5(json_encode([$filters, $paginationInput], JSON_THROW_ON_ERROR));
        $version = OvertimeModuleCache::version();
        $cacheKey = "employee:overtime:list:{$user->id}:v{$version}:{$paginationInput['page']}:{$filtersHash}";
        $cacheHit = Cache::has($cacheKey);

        $payload = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($user, $paginationInput, $filters) {
            $query = Overtime::query()
                ->select([
                    'id',
                    'user_id',
                    'date',
                    'schedule_end',
                    'expected_end_time',
                    'computed_minutes',
                    'computed_hours',
                    'approved_ot_hours',
                    'actual_rendered_ot_hours',
                    'payable_ot_hours',
                    'unapproved_ot_hours',
                    'overtime_reduction_reason',
                    'ot_type',
                    'ph_ot_rule',
                    'reason',
                    'status',
                    'approval_stage',
                    'pending_approval',
                    'first_approver_id',
                    'second_approver_id',
                    'rejected_at',
                    'filed_at',
                    'filed_by',
                    'created_at',
                    'updated_at',
                ])
                ->where('user_id', $user->id)
                ->with([
                    'firstApprover:id,name,first_name,middle_name,last_name,suffix',
                    'secondApprover:id,name,first_name,middle_name,last_name,suffix',
                ]);

            $this->applyEmployeeOvertimeFilters($query, $filters);

            $paginator = $query
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(
                    $paginationInput['per_page'],
                    ['*'],
                    'page',
                    $paginationInput['page'],
                );

            $rows = $paginator->getCollection()
                ->map(fn (Overtime $overtime) => $this->mapOvertimeTableRowForEmployee($overtime, $user))
                ->values()
                ->all();

            return [
                'overtimes' => $rows,
                'rows' => $rows,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'filters' => $filters,
            ];
        });

        $payload['counts'] = $this->cachedEmployeeOvertimeCounts((int) $user->id);
        $this->logEmployeeOvertimePerformance('employee.overtime.requests', $user, $cacheHit, count($payload['rows'] ?? []), $started);

        return response()->json($payload);
    }

    /**
     * Cached aggregate status counts for employee overtime.
     *
     * @return array<string, int>
     */
    private function cachedEmployeeOvertimeCounts(int $employeeId): array
    {
        $version = OvertimeModuleCache::version();
        $key = "employee:overtime:counts:{$employeeId}:v{$version}";

        return Cache::remember($key, now()->addSeconds(60), function () use ($employeeId): array {
            $rows = Overtime::query()
                ->select('status', DB::raw('COUNT(*) as aggregate'))
                ->where('user_id', $employeeId)
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();

            return [
                'total' => array_sum($rows),
                'pending' => (int) ($rows[Overtime::STATUS_PENDING] ?? 0),
                'approved' => (int) ($rows[Overtime::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($rows[Overtime::STATUS_REJECTED] ?? 0),
                'cancelled' => (int) ($rows['cancelled'] ?? 0),
            ];
        });
    }

    public function myDetailsLite(Request $request, int $id): JsonResponse
    {
        $started = microtime(true);
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }
        $this->ensureSelfOvertimeAccess($user);
        $this->beginEmployeeOvertimeQueryLog();

        $version = OvertimeModuleCache::version();
        $key = "employee:overtime:details:{$user->id}:{$id}:v{$version}";
        $cacheHit = Cache::has($key);

        $overtime = Cache::remember($key, now()->addMinutes(3), function () use ($user, $id): array {
            $row = Overtime::query()
                ->where('user_id', $user->id)
                ->whereKey($id)
                ->with([
                    'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                    'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
                    'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                    'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                    'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                    'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
                ])
                ->firstOrFail();

            $payload = $this->mapOvertimeRowForEmployee($row, $user);
            $payload['request_summary'] = [
                'id' => (int) $row->id,
                'date' => $row->date?->toDateString(),
                'status' => $row->status,
                'requested_hours' => (float) ($row->computed_hours ?? 0),
                'approved_hours' => $row->approved_ot_hours !== null ? (float) $row->approved_ot_hours : null,
            ];
            $payload['attendance_context'] = $this->attendanceContextForOvertime($row);
            $payload['payroll_impact_summary'] = [
                'approved_ot_hours' => $row->approved_ot_hours !== null ? (float) $row->approved_ot_hours : null,
                'actual_rendered_ot_hours' => (float) ($row->actual_rendered_ot_hours ?? 0),
                'payable_ot_hours' => (float) ($row->payable_ot_hours ?? 0),
                'unapproved_ot_hours' => (float) ($row->unapproved_ot_hours ?? 0),
                'overtime_reduction_reason' => $row->overtime_reduction_reason,
            ];

            return $payload;
        });

        $this->logEmployeeOvertimePerformance('employee.overtime.details_lite', $user, $cacheHit, 1, $started);

        return response()->json(['overtime' => $overtime]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceContextForOvertime(Overtime $overtime): array
    {
        $date = $overtime->date?->toDateString();
        if (! $date) {
            return ['date' => null, 'logs' => []];
        }

        $tz = $this->attendanceTimezone();
        $start = Carbon::parse($date.' 00:00:00', $tz)->utc();
        $end = Carbon::parse($date.' 23:59:59', $tz)->utc();

        $logs = AttendanceLog::query()
            ->select(['id', 'type', 'verified_at', 'authentication_method', 'method'])
            ->where('user_id', $overtime->user_id)
            ->whereBetween('verified_at', [$start, $end])
            ->orderBy('verified_at')
            ->limit(12)
            ->get()
            ->map(fn (AttendanceLog $log): array => [
                'id' => (int) $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'method' => $log->authentication_method ?: $log->method,
            ])
            ->all();

        return [
            'date' => $date,
            'logs' => $logs,
        ];
    }

    private function logEmployeeOvertimePerformance(string $endpoint, User $user, bool $cacheHit, int $rowsReturned, float $started): void
    {
        try {
            $queryLog = DB::getQueryLog();
            $dbTimeMs = array_sum(array_map(static fn (array $query): float => (float) ($query['time'] ?? 0), $queryLog));
            Log::info('employee_overtime.performance', [
                'endpoint' => $endpoint,
                'employee_id' => (int) $user->id,
                'query_count' => count($queryLog),
                'db_time_ms' => round($dbTimeMs, 2),
                'cache_hit' => $cacheHit,
                'rows_returned' => $rowsReturned,
                'response_time_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable) {
            // Never let performance logging affect employee overtime flows.
        } finally {
            try {
                DB::disableQueryLog();
            } catch (\Throwable) {
                //
            }
        }
    }

    private function beginEmployeeOvertimeQueryLog(): void
    {
        try {
            DB::flushQueryLog();
            DB::enableQueryLog();
        } catch (\Throwable) {
            //
        }
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

        $fromRaw = $request->query('from_date');
        $toRaw = $request->query('to_date');
        $hasRange = is_string($fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)
            && is_string($toRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw);

        $perPage = (int) $request->query('per_page', 20);
        $maxPerPage = $hasRange ? 100 : 50;
        $perPage = max(1, min($maxPerPage, $perPage));
        $dashboardLite = $request->boolean('dashboard_lite');

        $query = Overtime::query()
            ->where('user_id', $user->id);

        if (! $dashboardLite) {
            $query->with([
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
            ]);
        }

        if (is_string($fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)) {
            $query->whereDate('date', '>=', $fromRaw);
        }
        if (is_string($toRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)) {
            $query->whereDate('date', '<=', $toRaw);
        }

        $paginator = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(function (Overtime $o) use ($user, $dashboardLite) {
            if (! $dashboardLite) {
                return $this->mapOvertimeRowForEmployee($o, $user);
            }

            return [
                'id' => $o->id,
                'date' => $o->date?->toDateString(),
                'status' => $o->status,
                'display_status' => $o->status,
                'schedule_end' => $o->schedule_end?->format('H:i'),
                'start_time' => $o->schedule_end?->format('H:i'),
                'end_time' => $o->expected_end_time?->format('H:i'),
                'expected_end_time' => $o->expected_end_time?->format('H:i'),
                'computed_hours' => (float) ($o->computed_hours ?? 0),
                'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
                'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
                'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
                'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
                'overtime_reduction_reason' => $o->overtime_reduction_reason,
            ];
        })->values();

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
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
            ])
            ->firstOrFail();

        return response()->json([
            'overtime' => $this->mapOvertimeRowForEmployee($overtime, $user),
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
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'ph_ot_rule' => ['nullable', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:2'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'assignment_id' => ['nullable', 'integer', 'exists:employee_organization_assignments,id'],
        ]);

        $dateYmd = $overtime->date->toDateString();
        $computed = $this->computeOvertimeRequestQuantities($dateYmd, $validated['start_time'], $validated['end_time']);
        $this->validateNoOverlappingOvertime($user, $dateYmd, $validated['start_time'], $validated['end_time'], (int) $overtime->id);

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
            'approved_ot_start' => null,
            'approved_ot_end' => null,
            'approved_ot_hours' => null,
            'actual_rendered_ot_hours' => 0,
            'payable_ot_hours' => 0,
            'unapproved_ot_hours' => 0,
            'overtime_reduction_reason' => null,
            'computed_minutes' => $computed['computed_minutes'],
            'computed_hours' => $computed['computed_hours'],
            'ph_ot_rule' => $validated['ph_ot_rule'] ?? $overtime->ph_ot_rule ?? 'ORD',
            'ot_type' => $validated['category'],
            'reason' => $validated['reason'],
            'attachment_path' => $attachmentPath,
        ]);
        $overtime->save();
        OvertimeModuleCache::flush();

        $overtime->load('approvedBy:id,name,first_name,middle_name,last_name,suffix');

        return response()->json([
            'message' => 'Overtime request updated.',
            'overtime' => $this->mapOvertimeRowForEmployee($overtime->fresh([
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
            ]), $user),
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
                'id' => ['Only pending overtime requests can be deleted.'],
            ]);
        }

        if (! $this->canDeleteOvertimeRequest($user, $overtime)) {
            throw ValidationException::withMessages([
                'id' => ['You can only delete overtime requests you created or requests filed for you.'],
            ]);
        }

        $path = $overtime->attachment_path;
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $overtime->delete();
        OvertimeModuleCache::flush();

        return response()->json([
            'message' => 'Overtime request deleted.',
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

        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'user' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $dateYmd = Carbon::parse($request->query('date'))->toDateString();
        $version = OvertimeModuleCache::version();
        $cacheKey = "employee:overtime:form_context:{$user->id}:{$dateYmd}:v{$version}";

        $payload = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($user, $dateYmd): array {
            $phOtOptions = PhPayrollReference::otMultiplierDropdownOptions();
            $defaultPhOtRule = $this->detectDefaultPhOtRule($user, $dateYmd);
            $detected = $this->otDetectionService->detectForDate($user, $dateYmd, $this->attendanceTimezone());
            $detectedSegments = $this->mapDetectedSegmentsForFiling($detected);

            return [
                'date' => $dateYmd,
                'filing_window_days' => null,
                'earliest_allowed_date' => null,
                'has_assigned_schedule' => true,
                'is_workday' => true,
                'schedule_start' => null,
                'schedule_end' => null,
                'overnight_shift' => false,
                'has_clock_in' => false,
                'has_clock_out' => false,
                'last_clock_out_at' => null,
                'mode' => 'flexible',
                'mode_label' => 'Flexible OT filing',
                'help' => 'File OT anytime using your preferred start and end time range.',
                'detected_segments' => $detectedSegments,
                'ph_ot_rule_options' => $phOtOptions,
                'default_ph_ot_rule' => $defaultPhOtRule,
                'ph_ot_rule_help' => 'Select the PH pay condition if needed. You can file regardless of schedule, rest day, or holiday.',
            ];
        });

        return response()->json($payload);
    }

    public function formContext(Request $request): JsonResponse
    {
        if (! $request->query('date')) {
            $request->query->set('date', Carbon::now($this->attendanceTimezone())->toDateString());
        }

        return $this->requestContext($request);
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

        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'user' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'selected_segments' => ['nullable', 'array', 'min:1', 'max:1'],
            'selected_segments.*' => ['string', Rule::in(['pre_shift', 'post_shift'])],
            'ph_ot_rule' => ['nullable', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:2'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $dateYmd = Carbon::parse($validated['date'])->toDateString();
        try {
            $date = Carbon::parse($dateYmd)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $user->id, $date, $date);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $detected = $this->otDetectionService->detectForDate($user, $dateYmd, $this->attendanceTimezone());
        $detectedSegments = collect($this->mapDetectedSegmentsForFiling($detected))
            ->keyBy(static fn (array $seg) => $seg['key']);

        $selectedSegments = collect($validated['selected_segments'] ?? [])
            ->map(static fn ($s) => (string) $s)
            ->filter(static fn (string $s) => $s === 'pre_shift' || $s === 'post_shift')
            ->unique()
            ->values();
        if ($selectedSegments->count() > 1) {
            throw ValidationException::withMessages([
                'selected_segments' => ['Please select only one OT segment at a time (pre-shift or post-shift).'],
            ]);
        }

        $targets = [];
        if ($selectedSegments->isNotEmpty()) {
            foreach ($selectedSegments as $segmentKey) {
                $seg = $detectedSegments->get($segmentKey);
                if (! is_array($seg) || empty($seg['start_time']) || empty($seg['end_time'])) {
                    throw ValidationException::withMessages([
                        'selected_segments' => ["Selected segment [{$segmentKey}] is no longer available for this date."],
                    ]);
                }
                $targets[] = [
                    'segment' => $segmentKey,
                    'start_time' => (string) $seg['start_time'],
                    'end_time' => (string) $seg['end_time'],
                ];
            }
        } else {
            $targets[] = [
                'segment' => null,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
            ];
        }

        $computedTargets = [];
        foreach ($targets as $target) {
            $computed = $this->computeOvertimeRequestQuantities($dateYmd, $target['start_time'], $target['end_time']);
            $computedTargets[] = array_merge($target, ['computed' => $computed]);
        }

        foreach ($computedTargets as $target) {
            $this->validateNoOverlappingOvertime($user, $dateYmd, $target['start_time'], $target['end_time']);
        }

        $selectedAssignment = $this->organizationAssignments->resolveRequestAssignment(
            $user,
            isset($validated['assignment_id']) ? (int) $validated['assignment_id'] : null,
            $dateYmd,
        );
        $assignmentContext = $this->organizationAssignments->requestContextPayload($selectedAssignment);

        \Illuminate\Support\Facades\Log::info('overtime_request: selected organization context', [
            'request_employee_id' => (int) $user->id,
            'selected_assignment_id' => $assignmentContext['assignment_id'],
            'selected_assignment_type' => $assignmentContext['assignment_type'],
            'selected_section_unit_id' => $assignmentContext['section_unit_id'],
        ]);

        $routing = $this->hrApprovalChainResolver->resolveRoutingDecision(
            $user,
            true,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $assignmentContext,
        );
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

        $stage = $this->hrApprovalChainResolver->initialApprovalStage(
            $user,
            true,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $assignmentContext,
        );
        $hrApproverId = $routing['hr_approver']?->id;
        if (! $hrApproverId) {
            throw ValidationException::withMessages([
                'approval' => ['No active Admin (HR) approver is configured.'],
            ]);
        }
        $overtimes = DB::transaction(function () use ($user, $dateYmd, $computedTargets, $validated, $attachmentPath, $stage, $hrApproverId, $assignmentContext) {
            $rows = [];
            foreach ($computedTargets as $target) {
                $computed = $target['computed'];
                $segment = $target['segment'];
                $segmentPrefix = $segment === 'pre_shift'
                    ? '[Pre-shift OT]'
                    : ($segment === 'post_shift' ? '[Post-shift OT]' : null);
                $reason = $segmentPrefix ? $segmentPrefix.' '.$validated['reason'] : $validated['reason'];

                $overtime = Overtime::create([
                    'user_id' => $user->id,
                    ...$assignmentContext,
                    'date' => $dateYmd,
                    'schedule_end' => $computed['schedule_end']->format('H:i:s'),
                    'time_out' => null,
                    'expected_end_time' => $computed['expected_end']->format('H:i:s'),
                    'approved_ot_start' => null,
                    'approved_ot_end' => null,
                    'approved_ot_hours' => null,
                    'actual_rendered_ot_hours' => 0,
                    'payable_ot_hours' => 0,
                    'unapproved_ot_hours' => 0,
                    'overtime_reduction_reason' => null,
                    'computed_minutes' => $computed['computed_minutes'],
                    'computed_hours' => $computed['computed_hours'],
                    'ph_ot_rule' => $validated['ph_ot_rule'] ?? 'ORD',
                    'ot_type' => $validated['category'],
                    'reason' => $reason,
                    'attachment_path' => $attachmentPath,
                    'status' => Overtime::STATUS_PENDING,
                    'created_by' => $user->id,
                    'approval_stage' => $stage,
                    'pending_approval' => true,
                    'first_approver_id' => null,
                    'second_approver_id' => $hrApproverId,
                    'filed_at' => now(),
                    'filed_by' => $user->id,
                ]);

                OvertimeApprovalAudit::create([
                    'overtime_id' => $overtime->id,
                    'actor_id' => $user->id,
                    'employee_id' => $user->id,
                    'action' => 'file',
                    'details' => $reason,
                    'approver_role' => $this->hrRoleResolver->resolveForApprovalSubject($user)->badgeLabel(),
                ]);
                $rows[] = $overtime;
            }

            return collect($rows);
        });

        foreach ($overtimes as $overtime) {
            $this->approvalWorkflowService->ensureRecordsForRequest(
                $overtime,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
                $user,
                $user,
            );

            $this->notificationService->notifyCurrentApprover(
                $overtime,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
                'overtime',
                'overtime.needs_approval',
                'Overtime request needs approval',
                ($user->display_name ?? $user->name ?? 'An employee').' filed an overtime request.',
                '/admin/overtime?review_id='.$overtime->id,
            );
            $this->emailTrigger->overtimeFiled($overtime);
        }
        OvertimeModuleCache::flush();

        return response()->json([
            'message' => $overtimes->count() > 1
                ? 'Overtime requests submitted successfully.'
                : 'Overtime request submitted successfully.',
            'overtimes' => $overtimes->map(fn (Overtime $overtime) => $this->mapOvertimeRowForEmployee($overtime->fresh([
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'user:id,name,first_name,middle_name,last_name,suffix,position,profile_image,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
            ]), $user))->values(),
        ], 201);
    }
}
