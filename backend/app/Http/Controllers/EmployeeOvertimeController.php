<?php

namespace App\Http\Controllers;

use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\OtDetectionService;
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
        private readonly OtDetectionService $otDetectionService,
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
            'selected_segments' => ['nullable', 'array', 'min:1', 'max:1'],
            'selected_segments.*' => ['string', Rule::in(['pre_shift', 'post_shift'])],
            'ph_ot_rule' => ['nullable', 'string', Rule::in(PhPayrollReference::OT_RULE_CODES)],
            'reason' => ['required', 'string', 'min:2'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $dateYmd = Carbon::parse($validated['date'])->toDateString();

        $existingForDate = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateYmd)
            ->get();

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
            $newStart = $target['start_time'];
            $newEnd = $target['end_time'];
            foreach ($existingForDate as $existing) {
                $existingStart = $existing->schedule_end?->format('H:i');
                $existingEnd = $existing->expected_end_time?->format('H:i');
                if ($existingStart === $newStart && $existingEnd === $newEnd) {
                    throw ValidationException::withMessages([
                        'selected_segments' => ['You already filed this OT time segment for the selected date.'],
                    ]);
                }
            }
        }

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
        $overtimes = DB::transaction(function () use ($user, $dateYmd, $computedTargets, $validated, $attachmentPath, $stage, $firstApproverId, $hrApproverId) {
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
                    'date' => $dateYmd,
                    'schedule_end' => $computed['schedule_end']->format('H:i:s'),
                    'time_out' => null,
                    'expected_end_time' => $computed['expected_end']->format('H:i:s'),
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
                    'details' => $reason,
                    'approver_role' => $this->hrRoleResolver->resolveForApprovalSubject($user)->badgeLabel(),
                ]);
                $rows[] = $overtime;
            }

            return collect($rows);
        });

        return response()->json([
            'message' => $overtimes->count() > 1
                ? 'Overtime requests submitted successfully.'
                : 'Overtime request submitted successfully.',
            'overtimes' => $overtimes->map(fn (Overtime $overtime) => $this->mapOvertimeRowForEmployee($overtime->fresh([
                'approvedBy:id,name',
                'user:id,name,position,profile_image,department_id,department,branch_id,company_id',
                'filedBy:id,name,profile_image',
                'firstApprover:id,name,profile_image',
                'secondApprover:id,name,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name')->orderBy('created_at'),
            ])))->values(),
        ], 201);
    }
}
