<?php

namespace App\Http\Controllers;

use App\Enums\HrRole;
use App\Jobs\ProcessDailyPayrollJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionApproval;
use App\Models\AttendanceCorrectionAudit;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\PresenceFilingAttendanceLogSyncService;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\PresenceFilingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PresenceFilingController extends Controller
{
    public function __construct(
        private readonly PresenceFilingService $presenceFilingService,
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $approvalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PresenceFilingCorrectionFormatter $correctionFormatter,
        private readonly PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    private function normalizeTimeToHi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        if (strlen($v) >= 5 && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        return $v;
    }

    private function normalizeIssueKind(AttendanceCorrection $correction): string
    {
        $stored = is_string($correction->issue_kind) ? trim($correction->issue_kind) : '';
        if (in_array($stored, ['missing_in', 'missing_out', 'both'], true)) {
            return $stored;
        }

        $hasIn = $correction->time_in !== null;
        $hasOut = $correction->time_out !== null;
        if (! $hasIn && ! $hasOut) {
            return 'both';
        }
        if (! $hasIn) {
            return 'missing_in';
        }
        if (! $hasOut) {
            return 'missing_out';
        }

        return 'both';
    }

    /**
     * Employee (including org heads): submit or update manual attendance / presence filing for any past date.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $chain = $this->approvalService->getApprovalChain($user);
        if ($chain === null) {
            return response()->json(['message' => 'Your role cannot file attendance corrections.'], 403);
        }

        $tz = $this->presenceFilingService->attendanceTimezone();

        $validated = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'issue_kind' => ['required', 'string', 'in:missing_in,missing_out,both'],
            'remarks' => ['required', 'string', 'min:1', 'max:65535'],
            'time_in' => [
                Rule::requiredIf(fn () => in_array($request->input('issue_kind'), ['missing_in', 'both'], true)),
                'nullable',
                'date_format:H:i',
            ],
            'time_out' => [
                Rule::requiredIf(fn () => in_array($request->input('issue_kind'), ['missing_out', 'both'], true)),
                'nullable',
                'date_format:H:i',
            ],
        ]);

        $dateKey = $validated['date'];
        $kind = $validated['issue_kind'];

        try {
            $d = Carbon::parse($dateKey)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $user->id, $d, $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $employee = User::query()
            ->whereKey($user->id)
            ->with('workingSchedule')
            ->firstOrFail();

        $timeInStr = $kind === 'missing_out'
            ? null
            : $this->normalizeTimeToHi($validated['time_in'] ?? null);
        $timeOutStr = $kind === 'missing_in'
            ? null
            : $this->normalizeTimeToHi($validated['time_out'] ?? null);

        $timeIn = null;
        $timeOut = null;
        if ($timeInStr) {
            $timeIn = Carbon::parse($dateKey.' '.$timeInStr, $tz);
        }
        if ($timeOutStr) {
            $timeOut = Carbon::parse($dateKey.' '.$timeOutStr, $tz);
        }
        if ($timeIn && $timeOut && $timeOut->lessThanOrEqualTo($timeIn)) {
            $scheduled = $this->presenceFilingService->resolveScheduleRegularPunches($employee, $dateKey);
            if ($scheduled && $scheduled[1]->toDateString() !== $timeIn->toDateString()) {
                $timeOut = $timeOut->copy()->addDay();
            } elseif ($timeOut->lessThanOrEqualTo($timeIn)) {
                throw ValidationException::withMessages(['time_out' => ['Time out must be after time in.']]);
            }
        }

        $timeInUtc = $timeIn ? $timeIn->copy()->setTimezone('UTC') : null;
        $timeOutUtc = $timeOut ? $timeOut->copy()->setTimezone('UTC') : null;

        $issueLabel = match ($kind) {
            'missing_in' => 'Missing Clock In',
            'missing_out' => 'Missing Clock Out',
            'both' => 'Missing Clock In and Out',
        };
        $fullRemarks = "[{$issueLabel}] ".trim((string) $validated['remarks']);

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $hasIn = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('created_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('created_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();
        $isIncompleteRecord = ($hasIn xor $hasOut) || (! $hasIn && ! $hasOut);

        $existing = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->first();

        $routing = $this->approvalService->resolveRoutingDecision($employee);
        if (($routing['chain'] ?? null) === null) {
            throw ValidationException::withMessages([
                'approval' => ['Your account cannot file attendance corrections right now.'],
            ]);
        }
        $initialStage = $this->approvalService->initialApprovalStage($employee);
        $firstApproverId = $initialStage === AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST
            ? ($routing['first_level_approver']?->id)
            : null;
        $hrApproverId = $routing['hr_approver']?->id;
        if (! $hrApproverId) {
            throw ValidationException::withMessages([
                'approval' => ['No active Admin (HR) approver is configured.'],
            ]);
        }
        $auditReason = $fullRemarks;

        $correction = DB::transaction(function () use ($employee, $dateKey, $timeInUtc, $timeOutUtc, $fullRemarks, $existing, $initialStage, $isIncompleteRecord, $kind, $firstApproverId, $hrApproverId, $auditReason) {
            $correction = AttendanceCorrection::updateOrCreate(
                [
                    'user_id' => $employee->id,
                    'date' => $dateKey,
                ],
                [
                    'time_in' => $timeInUtc,
                    'time_out' => $timeOutUtc,
                    'issue_kind' => $kind,
                    'remarks' => $fullRemarks,
                    'reason_code' => PresenceFilingService::REASON_FORGOT_PUNCH,
                    'pending_approval' => true,
                    'approved' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                    'filed_at' => now(),
                    'filed_by' => $employee->id,
                    'rejected_at' => null,
                    'rejected_by' => null,
                    'rejection_note' => null,
                    'manual_presence_reason' => null,
                    'approval_stage' => $initialStage,
                    'first_approver_id' => $firstApproverId,
                    'first_approved_at' => null,
                    'second_approver_id' => $hrApproverId,
                    'second_approved_at' => null,
                    'is_incomplete_record' => $isIncompleteRecord,
                ]
            );

            AttendanceCorrectionAudit::create([
                'attendance_correction_id' => $correction->id,
                'admin_id' => null,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => $existing?->time_in,
                'previous_time_out' => $existing?->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $auditReason,
                'action' => 'file',
            ]);

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $employee->id,
                'level' => 0,
                'status' => 'submitted',
                'notes' => $fullRemarks,
                'acted_at' => now(),
            ]);

            return $correction;
        });

        $correction->load([
            'user',
            'filedBy',
            'firstApprover',
            'secondApprover',
            'attendanceLogsSyncedBy',
            'rejectedBy',
            'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
            'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
        ]);

        return response()->json([
            'message' => 'Attendance correction submitted for approval.',
            'presence_filing' => $this->correctionFormatter->format($correction, $tz, includeEmployee: true, actor: null, includeDisplayFields: true),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $tz = $this->presenceFilingService->attendanceTimezone();
        $today = Carbon::now($tz)->toDateString();

        $c = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        return response()->json([
            'date' => $today,
            'presence_filing' => $c ? $this->correctionFormatter->format($c->loadMissing([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'attendanceLogsSyncedBy',
                'rejectedBy',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
            ]), $tz, includeEmployee: true, actor: null, includeDisplayFields: true) : null,
            'approval_chain' => $this->correctionFormatter->chainPayload($this->approvalService->getApprovalChain($user)),
        ]);
    }

    /**
     * Employee: history of attendance correction / presence filings (own records only).
     */
    public function listMine(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $tz = $this->presenceFilingService->attendanceTimezone();
        $q = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->where(function ($sub) {
                $sub->whereNotNull('filed_at')->orWhereNotNull('reason_code');
            })
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'attendanceLogsSyncedBy',
                'rejectedBy',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if (! empty($validated['from_date'])) {
            $q->whereDate('date', '>=', $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $q->whereDate('date', '<=', $validated['to_date']);
        }

        $items = $q->limit(200)->get()->map(fn (AttendanceCorrection $c) => $this->correctionFormatter->format($c, $tz, includeEmployee: true, actor: null, includeDisplayFields: true));

        return response()->json(['presence_filings' => $items]);
    }

    /**
     * Approvers: all items (pending, approved, rejected) for the current actor with filtering.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $statusFilter = $request->query('status', 'all');

        $query = AttendanceCorrection::query()
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'attendanceLogsSyncedBy',
                'rejectedBy',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
            ])
            ->orderByDesc('filed_at');

        if ($statusFilter === 'pending') {
            $query->where('pending_approval', true)
                ->where('approved', false)
                ->whereNull('rejected_at');
        } elseif ($statusFilter === 'approved') {
            $query->where('approved', true);
        } elseif ($statusFilter === 'rejected') {
            $query->whereNotNull('rejected_at');
        }

        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');
        if (is_string($fromDate) && $fromDate !== '') {
            $query->whereDate('date', '>=', $fromDate);
        }
        if (is_string($toDate) && $toDate !== '') {
            $query->whereDate('date', '<=', $toDate);
        }

        $issueType = $request->query('issue_type');
        if (is_string($issueType) && $issueType !== '' && $issueType !== 'all') {
            if ($issueType === 'missing_in') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'missing_in')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_in')->whereNotNull('time_out');
                        });
                });
            } elseif ($issueType === 'missing_out') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'missing_out')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_out')->whereNotNull('time_in');
                        });
                });
            } elseif ($issueType === 'both') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'both')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_in')->whereNull('time_out');
                        });
                });
            }
        }

        $searchQ = $request->query('q');
        if (is_string($searchQ) && trim($searchQ) !== '') {
            $raw = trim($searchQ);
            $term = '%'.$raw.'%';
            $query->where(function ($q) use ($term, $raw) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('filedBy', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('user', fn ($u) => $u->where('employee_code', 'like', $term));
                $idProbe = ltrim($raw, '#');
                if (ctype_digit($idProbe)) {
                    $q->orWhere('id', (int) $idProbe);
                }
            });
        }

        $this->dataScopeService->restrictAttendanceCorrectionsQuery($actor, $query);

        $tz = $this->presenceFilingService->attendanceTimezone();

        $items = $query->get()
            ->map(fn (AttendanceCorrection $c) => $this->correctionFormatter->format(
                $c,
                $tz,
                includeEmployee: true,
                actor: $actor,
                includeDisplayFields: true
            ));

        return response()->json(['presence_filings' => $items]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $correction = AttendanceCorrection::query()->with('user.workingSchedule')->findOrFail($id);
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return response()->json(['message' => 'This filing cannot be approved.'], 422);
        }

        $employee = User::query()
            ->whereKey($correction->user_id)
            ->with('workingSchedule')
            ->firstOrFail();

        $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);

        if (! $this->approvalService->canApprove($actor, $correction)) {
            return response()->json(['message' => 'You are not authorized to approve at this stage.'], 403);
        }

        $tz = $this->presenceFilingService->attendanceTimezone();
        $dateKey = $correction->date->toDateString();

        $stage = $correction->approval_stage ?? AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST;
        $actorRole = $this->hrRoleResolver->resolve($actor);
        $roleLabel = $actorRole->badgeLabel();

        if ($stage === AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST) {
            try {
                $d = Carbon::parse($dateKey)->startOfDay();
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            DB::transaction(function () use ($correction, $actor, $validated, $employee, $dateKey, $roleLabel) {
                $correction->first_approver_id = $actor->id;
                $correction->first_approved_at = now();
                $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_PENDING_SECOND;
                $correction->save();

                AttendanceCorrectionAudit::create([
                    'attendance_correction_id' => $correction->id,
                    'admin_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'date' => $dateKey,
                    'previous_time_in' => $correction->time_in,
                    'previous_time_out' => $correction->time_out,
                    'new_time_in' => $correction->time_in,
                    'new_time_out' => $correction->time_out,
                    'reason' => $validated['notes'] ?? 'First approval.',
                    'action' => 'approve_first',
                    'approver_role' => $roleLabel,
                ]);

                AttendanceCorrectionApproval::create([
                    'attendance_correction_id' => $correction->id,
                    'approver_id' => $actor->id,
                    'level' => 1,
                    'status' => 'approved',
                    'notes' => $validated['notes'] ?? null,
                    'acted_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'First approval recorded. Pending Admin (HR) final approval.',
                'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
            ]);
        }

        $chain = $this->approvalService->getApprovalChain($employee);
        if ($chain !== null && count($chain) >= 2 && $correction->first_approver_id === null) {
            return response()->json(['message' => 'First-level approval is required before HR can finalize.'], 422);
        }

        $timeIn = $correction->time_in ? $correction->time_in->copy()->timezone($tz) : null;
        $timeOut = $correction->time_out ? $correction->time_out->copy()->timezone($tz) : null;
        
        // Ensure issue_kind is properly set for sync service
        $issueKind = $this->normalizeIssueKind($correction);
        
        // Validate that required times are present based on issue kind
        if ($issueKind === 'missing_in' && $timeIn === null) {
            throw ValidationException::withMessages([
                'time_in' => ['Missing clock-in request requires a clock-in time.'],
            ]);
        }
        if ($issueKind === 'missing_out' && $timeOut === null) {
            throw ValidationException::withMessages([
                'time_out' => ['Missing clock-out request requires a clock-out time.'],
            ]);
        }
        if ($issueKind === 'both' && ($timeIn === null || $timeOut === null)) {
            throw ValidationException::withMessages([
                'time' => ['Both clock-in and clock-out times are required for this request.'],
            ]);
        }
        if ($timeIn !== null && $timeOut !== null && $timeOut->lessThanOrEqualTo($timeIn)) {
            throw ValidationException::withMessages([
                'time_out' => ['Time out must be after time in.'],
            ]);
        }

        $previousIn = $correction->time_in;
        $previousOut = $correction->time_out;

        try {
            $d = Carbon::parse($dateKey)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        DB::transaction(function () use ($correction, $actor, $validated, $employee, $dateKey, $timeIn, $timeOut, $previousIn, $previousOut, $roleLabel, $issueKind) {
            // Update correction record with approved times (convert to UTC for storage)
            $correction->time_in = $timeIn?->copy()->setTimezone('UTC');
            $correction->time_out = $timeOut?->copy()->setTimezone('UTC');
            $correction->pending_approval = false;
            $correction->approved = true;
            $correction->approved_by = $actor->id;
            $correction->approved_at = now();
            $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_APPROVED;
            $correction->second_approver_id = $actor->id;
            $correction->second_approved_at = now();
            $correction->save();

            AttendanceCorrectionAudit::create([
                'attendance_correction_id' => $correction->id,
                'admin_id' => $actor->id,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => $previousIn,
                'previous_time_out' => $previousOut,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $validated['notes'] ?? 'Final approval (Admin HR).',
                'action' => 'approve_final',
                'approver_role' => $roleLabel,
            ]);

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => 2,
                'status' => 'approved',
                'notes' => $validated['notes'] ?? null,
                'acted_at' => now(),
            ]);

            // Sync approved correction times to attendance_logs so kiosk/recent views show corrected times
            $syncResult = $this->attendanceLogSyncService->syncApprovedCorrectionToLogs(
                $employee,
                $dateKey,
                $correction->time_in,
                $correction->time_out,
                $actor,
                $correction->id,
                $roleLabel,
                $issueKind
            );

            // Mark sync as complete so deduplication logic in kiosk view works correctly
            $correction->is_incomplete_record = ! ($syncResult['applied_time_in'] && $syncResult['applied_time_out']);
            $correction->attendance_logs_synced_at = now();
            $correction->attendance_logs_synced_by = $actor->id;
            $correction->save();
        });

        ProcessDailyPayrollJob::dispatchSync($dateKey);

        return response()->json([
            'message' => 'Attendance correction approved and applied.',
            'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'rejection_note' => ['required', 'string', 'max:2000'],
        ]);

        $correction = AttendanceCorrection::query()->findOrFail($id);
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return response()->json(['message' => 'This filing cannot be rejected.'], 422);
        }

        $employee = User::query()
            ->whereKey($correction->user_id)
            ->firstOrFail();

        $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);

        if (! $this->approvalService->canReject($actor, $correction)) {
            return response()->json(['message' => 'You are not authorized to reject this filing.'], 403);
        }

        $actorRole = $this->hrRoleResolver->resolve($actor);

        DB::transaction(function () use ($correction, $actor, $validated, $employee, $actorRole) {
            $correction->pending_approval = false;
            $correction->approved = false;
            $correction->rejected_at = now();
            $correction->rejected_by = $actor->id;
            $correction->rejection_note = $validated['rejection_note'];
            $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_REJECTED;
            $correction->save();

            AttendanceCorrectionAudit::create([
                'attendance_correction_id' => $correction->id,
                'admin_id' => $actor->id,
                'employee_id' => $employee->id,
                'date' => $correction->date->toDateString(),
                'previous_time_in' => $correction->time_in,
                'previous_time_out' => $correction->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $validated['rejection_note'],
                'action' => 'reject',
                'approver_role' => $actorRole->badgeLabel(),
            ]);

            // Determine rejection level based on current approval stage.
            $level = ($correction->approval_stage ?? AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST) === AttendanceCorrectionApprovalService::STAGE_PENDING_SECOND
                ? 2
                : 1;
            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => $level,
                'status' => 'rejected',
                'notes' => $validated['rejection_note'],
                'acted_at' => now(),
            ]);
        });

        $tz = $this->presenceFilingService->attendanceTimezone();

        return response()->json([
            'message' => 'Attendance correction rejected.',
            'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ]);
    }

    /**
     * Admin (HR): add an internal remark on a pending filing without approving/rejecting (audit trail).
     */
    public function addHrNote(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->hrRoleResolver->resolve($actor) !== HrRole::AdminHr) {
            return response()->json(['message' => 'Only Admin (HR) can add internal remarks.'], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $correction = AttendanceCorrection::query()->with('user.workingSchedule')->findOrFail($id);
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return response()->json(['message' => 'Cannot add remarks to this filing.'], 422);
        }

        $employee = User::query()
            ->whereKey($correction->user_id)
            ->with('workingSchedule')
            ->firstOrFail();

        $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);

        $tz = $this->presenceFilingService->attendanceTimezone();
        $dateKey = $correction->date->toDateString();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();

        DB::transaction(function () use ($correction, $actor, $validated, $employee, $dateKey, $roleLabel) {
            AttendanceCorrectionAudit::create([
                'attendance_correction_id' => $correction->id,
                'admin_id' => $actor->id,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => $correction->time_in,
                'previous_time_out' => $correction->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $validated['notes'],
                'action' => 'hr_remark',
                'approver_role' => $roleLabel,
            ]);

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => 2,
                'status' => 'remark',
                'notes' => $validated['notes'],
                'acted_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Remark recorded.',
            'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ]);
    }
}
