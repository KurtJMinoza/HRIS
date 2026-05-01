<?php

namespace App\Http\Controllers;

use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\OvertimeApprovalService;
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

        $fromRaw = $request->query('from_date');
        $toRaw = $request->query('to_date');
        $hasRange = is_string($fromRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw)
            && is_string($toRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw);

        $perPage = (int) $request->query('per_page', 20);
        $maxPerPage = $hasRange ? 100 : 50;
        $perPage = max(1, min($maxPerPage, $perPage));

        $query = Overtime::query()
            ->where('user_id', $user->id)
            ->with([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ]);

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
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'ph_ot_rule' => ['nullable', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:2'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $dateYmd = $overtime->date->toDateString();
        $computed = $this->computeOvertimeRequestQuantities($dateYmd, $validated['start_time'], $validated['end_time']);

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
            'ph_ot_rule' => $validated['ph_ot_rule'] ?? $overtime->ph_ot_rule ?? 'ORD',
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

        $phOtOptions = PhPayrollReference::otMultiplierDropdownOptions();

        $defaultPhOtRule = 'ORD';

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

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => ['Account is deactivated.'],
            ]);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'ph_ot_rule' => ['nullable', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:2'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $dateYmd = Carbon::parse($validated['date'])->toDateString();

        $existing = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateYmd)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'date' => ['You already have an overtime record for this date.'],
            ]);
        }

        $computed = $this->computeOvertimeRequestQuantities($dateYmd, $validated['start_time'], $validated['end_time']);

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
                'ph_ot_rule' => $validated['ph_ot_rule'] ?? 'ORD',
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
