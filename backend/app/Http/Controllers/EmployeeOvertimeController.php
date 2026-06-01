<?php

namespace App\Http\Controllers;

use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OtDetectionService;
use App\Services\OvertimeApprovalService;
use App\Support\OvertimeModuleCache;
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
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly OvertimeApprovalService $overtimeApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OtDetectionService $otDetectionService,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
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

        $phOtOptions = PhPayrollReference::otMultiplierDropdownOptions();

        $defaultPhOtRule = 'ORD';
        $detected = $this->otDetectionService->detectForDate($user, $dateYmd, $this->attendanceTimezone());
        $detectedSegments = $this->mapDetectedSegmentsForFiling($detected);

        return response()->json([
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
