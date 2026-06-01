<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ProcessesBulkApproval;
use App\Enums\HrRole;
use App\Jobs\ProcessDailyPayrollJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionApproval;
use App\Models\AttendanceCorrectionAudit;
use App\Models\OrgApprovalRecord;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\ApprovalWorkflowSettingService;
use App\Services\AttendanceCorrectionDetailService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeService;
use App\Services\PresenceFilingAttendanceLogSyncService;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\BulkApproval\PresenceFilingBulkApprovalQuery;
use App\Services\PresenceFilingService;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\RequestPerformanceLogger;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PresenceFilingController extends Controller
{
    use ProcessesBulkApproval;

    public function __construct(
        private readonly PresenceFilingService $presenceFilingService,
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $approvalService,
        private readonly AttendanceCorrectionDetailService $attendanceCorrectionDetailService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PresenceFilingCorrectionFormatter $correctionFormatter,
        private readonly PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly PresenceFilingBulkApprovalQuery $bulkApprovalQuery,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly OvertimeService $overtimeService,
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
        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'user' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
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
        $hasIn = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
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
        AttendanceCorrectionModuleCache::flush();

        return response()->json([
            'message' => 'Attendance correction submitted for approval.',
            'presence_filing' => $this->correctionFormatter->format($correction, $tz, includeEmployee: true, actor: $user, includeDisplayFields: true),
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
            ]), $tz, includeEmployee: true, actor: $user, includeDisplayFields: true) : null,
            'approval_chain' => $this->correctionFormatter->chainPayload($this->approvalService->getApprovalChain($user)),
        ]);
    }

    public function attendanceDetail(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'issue_type' => ['required', 'string', 'in:missing_in,missing_out,both'],
        ]);

        $employee = User::query()
            ->whereKey($user->id)
            ->with('workingSchedule')
            ->firstOrFail();

        return response()->json($this->attendanceCorrectionDetailService->resolve(
            $employee,
            $validated['date'],
            $validated['issue_type'],
            adminContext: false
        ));
    }

    public function adminStore(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
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

        $employee = User::query()
            ->whereKey((int) $validated['employee_id'])
            ->attendanceEmployees()
            ->with('workingSchedule')
            ->firstOrFail();

        $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);

        $tz = $this->presenceFilingService->attendanceTimezone();
        $dateKey = $validated['date'];
        $kind = $validated['issue_kind'];

        try {
            $d = Carbon::parse($dateKey)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $timeInStr = $kind === 'missing_out'
            ? null
            : $this->normalizeTimeToHi($validated['time_in'] ?? null);
        $timeOutStr = $kind === 'missing_in'
            ? null
            : $this->normalizeTimeToHi($validated['time_out'] ?? null);

        $timeIn = $timeInStr ? Carbon::parse($dateKey.' '.$timeInStr, $tz) : null;
        $timeOut = $timeOutStr ? Carbon::parse($dateKey.' '.$timeOutStr, $tz) : null;
        if ($timeIn && $timeOut && $timeOut->lessThanOrEqualTo($timeIn)) {
            $scheduled = $this->presenceFilingService->resolveScheduleRegularPunches($employee, $dateKey);
            if ($scheduled && $scheduled[1]->toDateString() !== $timeIn->toDateString()) {
                $timeOut = $timeOut->copy()->addDay();
            } else {
                throw ValidationException::withMessages(['time_out' => ['Time out must be after time in.']]);
            }
        }

        $issueLabel = match ($kind) {
            'missing_in' => 'Missing Clock In',
            'missing_out' => 'Missing Clock Out',
            'both' => 'Missing Clock In and Out',
        };
        $fullRemarks = "[{$issueLabel}] ".trim((string) $validated['remarks']);

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $hasIn = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
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
                'approval' => ['This employee cannot file attendance corrections right now.'],
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

        $correction = DB::transaction(function () use ($employee, $actor, $dateKey, $timeIn, $timeOut, $fullRemarks, $existing, $initialStage, $isIncompleteRecord, $kind, $firstApproverId, $hrApproverId) {
            $correction = AttendanceCorrection::updateOrCreate(
                [
                    'user_id' => $employee->id,
                    'date' => $dateKey,
                ],
                [
                    'time_in' => $timeIn ? $timeIn->copy()->setTimezone('UTC') : null,
                    'time_out' => $timeOut ? $timeOut->copy()->setTimezone('UTC') : null,
                    'issue_kind' => $kind,
                    'remarks' => $fullRemarks,
                    'reason_code' => PresenceFilingService::REASON_FORGOT_PUNCH,
                    'pending_approval' => true,
                    'approved' => false,
                    'approved_by' => null,
                    'approved_at' => null,
                    'filed_at' => now(),
                    'filed_by' => $actor->id,
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
                'admin_id' => $actor->id,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => $existing?->time_in,
                'previous_time_out' => $existing?->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $fullRemarks,
                'action' => 'file',
            ]);

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
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
        AttendanceCorrectionModuleCache::flush();

        return response()->json([
            'message' => 'Attendance correction submitted for approval.',
            'presence_filing' => $this->correctionFormatter->format($correction, $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ], 201);
    }

    public function adminAttendanceDetail(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'issue_type' => ['required', 'string', 'in:missing_in,missing_out,both'],
        ]);

        $employee = User::query()
            ->whereKey((int) $validated['employee_id'])
            ->attendanceEmployees()
            ->with('workingSchedule')
            ->firstOrFail();

        $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);

        return response()->json($this->attendanceCorrectionDetailService->resolve(
            $employee,
            $validated['date'],
            $validated['issue_type'],
            adminContext: true
        ));
    }

    /**
     * Employee: history of attendance correction / presence filings (own records only).
     */
    public function listMine(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('employee.presence_filings.index');
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 25);

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
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if (! empty($validated['from_date'])) {
            $q->whereDate('date', '>=', $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $q->whereDate('date', '<=', $validated['to_date']);
        }

        $summary = $this->presenceFilingStatusCounts(clone $q);

        $paginator = $q->paginate($perPage)->withQueryString();
        $items = $paginator->getCollection()->map(fn (AttendanceCorrection $c) => $this->correctionFormatter->format($c, $tz, includeEmployee: true, actor: $user, includeDisplayFields: true));

        RequestPerformanceLogger::finish($perf, $request, $items->count(), [
            'scope' => 'employee',
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);

        return response()->json([
            'presence_filings' => $items->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    public function showMine(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('employee.presence_filings.show');
        $user = $request->user();
        if (! $user || (! $user->isEmployee() && ! $user->isAdmin())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tz = $this->presenceFilingService->attendanceTimezone();
        $correction = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
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
            ->firstOrFail();

        RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'employee']);

        return response()->json([
            'presence_filing' => $this->correctionFormatter->format($correction, $tz, includeEmployee: true, actor: $user, includeDisplayFields: true),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $correction = AttendanceCorrection::query()->with('filedBy')->findOrFail($id);
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return response()->json(['message' => 'Only pending attendance correction requests can be deleted.'], 422);
        }

        $actorId = (int) $actor->id;
        $canDelete = $actorId === (int) $correction->filed_by
            || $actorId === (int) $correction->user_id;
        if (! $canDelete) {
            return response()->json([
                'message' => 'You can only delete attendance correction requests you created or requests filed for you.',
            ], 403);
        }

        DB::transaction(function () use ($correction) {
            $correction->approvals()->delete();
            $correction->audits()->delete();
            $correction->delete();
        });
        ReviewRequestCache::forget('attendance_correction', (int) $correction->id);
        AttendanceCorrectionModuleCache::flush();
        AttendanceCorrectionModuleCache::flush();

        return response()->json([
            'message' => 'Attendance correction request deleted.',
        ]);
    }

    /**
     * Approvers: all items (pending, approved, rejected) for the current actor with filtering.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.presence_filings.index');
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $statusFilter = $request->query('status', 'all');
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));
        $cacheKey = $this->attendanceCorrectionListCacheKey($actor, $request, $perPage, $page);
        $cacheHit = false;
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cacheHit = true;
                RequestPerformanceLogger::finish($perf, $request, count($cached['presence_filings'] ?? []), [
                    'scope' => 'admin',
                    'per_page' => $perPage,
                    'total' => $cached['pagination']['total'] ?? null,
                    'cache' => 'hit',
                    'cache_hit' => true,
                ]);

                return response()->json($cached);
            }
        } catch (\Throwable $e) {
            Log::warning('attendance_correction.list.cache_read_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        $query = AttendanceCorrection::query()
            ->select([
                'id',
                'user_id',
                'date',
                'time_in',
                'time_out',
                'remarks',
                'issue_kind',
                'approved',
                'approved_by',
                'approved_at',
                'pending_approval',
                'filed_at',
                'filed_by',
                'rejected_at',
                'rejected_by',
                'rejection_note',
                'approval_stage',
                'first_approver_id',
                'first_approved_at',
                'second_approver_id',
                'second_approved_at',
                'is_incomplete_record',
                'created_at',
            ])
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,department_id,department',
            ])
            ->orderByDesc('filed_at');

        $requestId = $request->query('request_id');
        if ($requestId !== null && $requestId !== '' && ctype_digit((string) $requestId)) {
            $query->whereKey((int) $requestId);
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

        $this->applyPresenceFilingApprovalVisibility($actor, $query, $request);

        $includeSummary = ! $request->boolean('lite') && ! $request->boolean('skip_summary');
        $summary = $includeSummary ? $this->presenceFilingStatusCounts(clone $query) : null;

        if ($statusFilter === 'pending') {
            $query->where('pending_approval', true)
                ->where('approved', false)
                ->whereNull('rejected_at');
        } elseif ($statusFilter === 'approved') {
            $query->where('approved', true);
        } elseif ($statusFilter === 'rejected') {
            $query->whereNotNull('rejected_at');
        }

        $tz = $this->presenceFilingService->attendanceTimezone();

        $paginator = $query->paginate($perPage)->withQueryString();
        $pageRows = $paginator->getCollection();
        $currentApprovals = $this->currentAttendanceCorrectionApprovalRecords($pageRows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $items = $paginator->getCollection()
            ->map(fn (AttendanceCorrection $c) => $this->attendanceCorrectionListRow($c, $actor, $tz, $currentApprovals[(int) $c->id] ?? null));

        RequestPerformanceLogger::finish($perf, $request, $items->count(), [
            'scope' => 'admin',
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'cache' => $cacheHit ? 'hit' : 'miss',
            'cache_hit' => $cacheHit,
        ]);

        $payload = [
            'presence_filings' => $items->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $summary,
        ];

        try {
            Cache::put($cacheKey, $payload, now()->addSeconds(45));
        } catch (\Throwable $e) {
            Log::warning('attendance_correction.list.cache_write_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json($payload);
    }

    /**
     * Count each review status for the same filtered/visible result set before pagination.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<AttendanceCorrection>  $query
     * @return array{total:int,pending:int,approved:int,rejected:int}
     */
    private function presenceFilingStatusCounts($query): array
    {
        return [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)
                ->where('pending_approval', true)
                ->where('approved', false)
                ->whereNull('rejected_at')
                ->count(),
            'approved' => (clone $query)->where('approved', true)->count(),
            'rejected' => (clone $query)->whereNotNull('rejected_at')->count(),
        ];
    }

    public function counts(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.presence_filings.counts');
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $company = (string) ($request->query('company_id') ?? 'all');
        $cacheKey = 'attendance_correction:counts:'.AttendanceCorrectionModuleCache::version().':'.$actor->id.':'.$company.':'.md5(json_encode($request->query(), JSON_THROW_ON_ERROR));
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin', 'cache' => 'hit', 'cache_hit' => true]);

                return response()->json($cached);
            }
        } catch (\Throwable) {
        }

        $base = AttendanceCorrection::query()->select('id', 'user_id', 'approved', 'pending_approval', 'rejected_at', 'date');
        $this->applyPresenceFilingApprovalVisibility($actor, $base, $request);
        $today = today()->toDateString();
        $payload = [
            'pending' => (int) (clone $base)->where('pending_approval', true)->where('approved', false)->whereNull('rejected_at')->count(),
            'approved_today' => (int) (clone $base)->where('approved', true)->whereDate('approved_at', $today)->count(),
            'rejected_today' => (int) (clone $base)->whereNotNull('rejected_at')->whereDate('rejected_at', $today)->count(),
            'my_filings' => (int) (clone $base)->where('user_id', (int) $actor->id)->count(),
            'all_filings' => (int) (clone $base)->count(),
        ];

        RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin', 'cache' => 'miss', 'cache_hit' => false]);
        Cache::put($cacheKey, $payload, now()->addSeconds(45));

        return response()->json($payload);
    }

    private function attendanceCorrectionListCacheKey(User $actor, Request $request, int $perPage, int $page): string
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'company_id' => $request->query('company_id'),
            'from_date' => $request->query('from_date') ?? $request->query('date_from'),
            'to_date' => $request->query('to_date') ?? $request->query('date_to'),
            'issue_type' => $request->query('issue_type'),
            'q' => $request->query('q') ?? $request->query('search'),
            'request_id' => $request->query('request_id'),
            'page' => $page,
            'per_page' => $perPage,
        ], static fn ($value): bool => $value !== null && $value !== '');
        $company = (string) ($filters['company_id'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');

        return 'attendance_correction:list:'.$actor->id.':'.$company.':'.$status.':'.$page.':'.md5(json_encode($filters, JSON_THROW_ON_ERROR)).':v'.AttendanceCorrectionModuleCache::version();
    }

    /**
     * @param  list<int>  $requestIds
     * @return array<int, OrgApprovalRecord>
     */
    private function currentAttendanceCorrectionApprovalRecords(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return OrgApprovalRecord::query()
            ->select(['id', 'request_id', 'module_type', 'approval_label', 'approver_role', 'approver_id', 'approver_name', 'eligible_approver_ids', 'approval_status', 'sequence_order', 'remarks', 'approved_at'])
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->whereIn('request_id', $requestIds)
            ->with('approver:id,name,first_name,middle_name,last_name,suffix')
            ->orderBy('sequence_order')
            ->orderBy('id')
            ->get()
            ->unique('request_id')
            ->mapWithKeys(fn (OrgApprovalRecord $record): array => [(int) $record->request_id => $record])
            ->all();
    }

    private function attendanceCorrectionListRow(AttendanceCorrection $c, User $actor, string $tz, ?OrgApprovalRecord $currentApproval): array
    {
        $auth = $currentApproval && $c->user
            ? $this->approvalWorkflowService->authorizePendingRecord($actor, $currentApproval, $c->user, OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            : ['allowed' => false];
        $canApprove = (bool) ($auth['allowed'] ?? false);
        if ($canApprove
            && $currentApproval?->approver_role === \App\Enums\HrRole::AdminHr->value
            && ! $c->hasRequiredTimesForFinalApproval()) {
            $canApprove = false;
        }
        $status = $c->rejected_at ? 'rejected' : ($c->approved ? 'approved' : 'pending');
        $displayStatus = $status === 'approved'
            ? 'HR Approved'
            : ($status === 'rejected' ? 'Rejected' : ($currentApproval ? 'Pending '.rtrim(str_ireplace(' approval', '', $this->approvalRecordStageLabel($currentApproval))).' Approval' : 'Pending'));

        return [
            'id' => (int) $c->id,
            'request_id' => (int) $c->id,
            'correction_request_id' => (int) $c->id,
            'employee_id' => (int) $c->user_id,
            'user_id' => (int) $c->user_id,
            'employee_name' => $c->user?->display_name,
            'requested_by_name' => $c->user?->display_name,
            'employee_code' => $c->user?->employee_code,
            'company' => $c->user?->company_id,
            'department' => $c->user?->department,
            'date' => $c->date?->toDateString(),
            'attendance_date' => $c->date?->toDateString(),
            'correction_date' => $c->date?->toDateString(),
            'issue_type' => $this->normalizeIssueKind($c),
            'correction_type' => $this->normalizeIssueKind($c),
            'original_time_in' => null,
            'original_time_out' => null,
            'requested_time_in' => $c->time_in?->copy()->setTimezone($tz)->toIso8601String(),
            'requested_time_out' => $c->time_out?->copy()->setTimezone($tz)->toIso8601String(),
            'time_in' => $c->time_in?->copy()->setTimezone($tz)->toIso8601String(),
            'time_out' => $c->time_out?->copy()->setTimezone($tz)->toIso8601String(),
            'status' => $status,
            'display_status' => $displayStatus,
            'current_approver' => $currentApproval?->approver?->display_name ?? $currentApproval?->approver_name,
            'created_at' => $c->created_at?->toIso8601String(),
            'filed_at' => $c->filed_at?->toIso8601String(),
            'remarks' => $c->remarks,
            'rejection_note' => $c->rejection_note,
            'actor_can_approve' => $canApprove,
            'actor_can_reject' => $canApprove,
            'can_approve' => $canApprove,
            'can_reject' => $canApprove,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AttendanceCorrection>  $query
     */
    private function applyPresenceFilingApprovalVisibility(User $actor, $query, Request $request): void
    {
        if ($this->hrRoleResolver->isAdminHrAccount($actor)) {
            return;
        }

        $actorId = (int) $actor->id;
        $assignedRequestIds = $this->assignedPresenceFilingIdsForActor($actor);
        $hierarchyOn = app(ApprovalWorkflowSettingService::class)
            ->usesHierarchyApproval(OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION);
        $scopedEmployeeIds = $hierarchyOn
            ? $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor)
            : [];

        $query->where(function ($scope) use ($actorId, $assignedRequestIds, $scopedEmployeeIds): void {
            $scope->where('first_approver_id', $actorId)
                ->orWhere('second_approver_id', $actorId);

            if ($assignedRequestIds !== []) {
                $scope->orWhereIn('id', $assignedRequestIds);
            }

            if ($scopedEmployeeIds !== []) {
                $scope->orWhereIn('user_id', $scopedEmployeeIds);
            }
        });

    }

    /**
     * @return list<int>
     */
    private function assignedPresenceFilingIdsForActor(User $actor): array
    {
        $actorId = (int) $actor->id;
        $ids = AttendanceCorrection::query()
            ->where(function ($query) use ($actorId): void {
                $query->where('first_approver_id', $actorId)
                    ->orWhere('second_approver_id', $actorId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recordIds = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->where('approver_id', $actorId)
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$recordIds]));
    }

    private function canAccessPresenceFilingThroughApprovalScope(User $actor, AttendanceCorrection $correction): bool
    {
        if ($this->hrRoleResolver->isAdminHrAccount($actor)) {
            return true;
        }

        $actorId = (int) $actor->id;
        if ((int) $correction->user_id === $actorId
            || (int) $correction->filed_by === $actorId
            || (int) $correction->first_approver_id === $actorId
            || (int) $correction->second_approver_id === $actorId
        ) {
            return true;
        }

        if (OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->where('request_id', (int) $correction->id)
            ->where('approver_id', $actorId)
            ->exists()) {
            return true;
        }

        if (! app(ApprovalWorkflowSettingService::class)->usesHierarchyApproval(OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)) {
            return false;
        }

        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);

        return is_array($scopedEmployeeIds) && in_array((int) $correction->user_id, $scopedEmployeeIds, true);
    }

    private function wantsLiteAttendanceCorrectionMutationResponse(Request $request): bool
    {
        return $request->boolean('_bulk_approval') || str_starts_with($request->path(), 'api/attendance-corrections/');
    }

    private function isBulkApprovalRequest(Request $request): bool
    {
        return $request->boolean('_bulk_approval');
    }

    /**
     * @return array{can_approve: bool, can_reject: bool, deny_reason: ?string}
     */
    private function attendanceCorrectionActionAuthorization(User $actor, AttendanceCorrection $c, ?OrgApprovalRecord $pending): array
    {
        if (! $c->pending_approval || $c->approved || $c->rejected_at !== null) {
            return ['can_approve' => false, 'can_reject' => false, 'deny_reason' => 'request_is_not_pending'];
        }
        if (! $c->user || ! $pending) {
            return ['can_approve' => false, 'can_reject' => false, 'deny_reason' => 'no_pending_approval_step'];
        }
        $auth = $this->approvalWorkflowService->authorizePendingRecord($actor, $pending, $c->user, OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION);

        return ['can_approve' => (bool) $auth['allowed'], 'can_reject' => (bool) $auth['allowed'], 'deny_reason' => $auth['deny_reason']];
    }

    private function attendanceCorrectionReviewLiteResponse(AttendanceCorrection $c, $records, ?OrgApprovalRecord $pending, array $auth): array
    {
        $tz = $this->presenceFilingService->attendanceTimezone();
        $status = $c->rejected_at ? 'rejected' : ($c->approved ? 'approved' : 'pending');

        return [
            'id' => (int) $c->id,
            'request_id' => (int) $c->id,
            'employee_id' => (int) $c->user_id,
            'employee' => ['id' => (int) $c->user_id, 'name' => $c->user?->display_name],
            'employee_name' => $c->user?->display_name,
            'date' => $c->date?->toDateString(),
            'correction_date' => $c->date?->toDateString(),
            'issue_type' => $this->normalizeIssueKind($c),
            'correction_type' => $this->normalizeIssueKind($c),
            'original_values' => ['time_in' => null, 'time_out' => null],
            'requested_values' => [
                'time_in' => $c->time_in?->copy()->setTimezone($tz)->toIso8601String(),
                'time_out' => $c->time_out?->copy()->setTimezone($tz)->toIso8601String(),
            ],
            'requested_time_in' => $c->time_in?->copy()->setTimezone($tz)->toIso8601String(),
            'requested_time_out' => $c->time_out?->copy()->setTimezone($tz)->toIso8601String(),
            'time_in' => $c->time_in?->copy()->setTimezone($tz)->toIso8601String(),
            'time_out' => $c->time_out?->copy()->setTimezone($tz)->toIso8601String(),
            'reason' => $c->remarks,
            'remarks' => $c->remarks,
            'rejection_note' => $c->rejection_note,
            'status' => $status,
            'display_status' => $status === 'approved' ? 'HR Approved' : ($status === 'rejected' ? 'Rejected' : 'Pending'),
            'current_approver_id' => $pending?->approver_id,
            'current_approver' => $pending?->approver?->display_name ?? $pending?->approver_name,
            'approval_chain' => $this->approvalRecordSummary($records),
            'approval_progress' => $this->approvalRecordSummary($records),
            'approval_history' => $records->whereIn('approval_status', [OrgApprovalRecord::STATUS_APPROVED, OrgApprovalRecord::STATUS_REJECTED])->map(fn (OrgApprovalRecord $record): array => [
                'action' => $record->approval_status === OrgApprovalRecord::STATUS_REJECTED ? 'reject' : 'approve',
                'approver_role' => $this->approvalRecordRoleLabel($record),
                'details' => $record->remarks,
                'at' => $record->approved_at?->toIso8601String(),
                'actor_name' => $record->approver?->display_name ?? $record->approver_name,
            ])->values()->all(),
            'can_approve' => $auth['can_approve'],
            'can_reject' => $auth['can_reject'],
            'actor_can_approve' => $auth['can_approve'],
            'actor_can_reject' => $auth['can_reject'],
            'created_at' => $c->created_at?->toIso8601String(),
            'filed_at' => ($c->filed_at ?? $c->created_at)?->toIso8601String(),
        ];
    }

    private function approvalRecordStageLabel(OrgApprovalRecord $record): string
    {
        $label = trim((string) ($record->approval_label ?? ''));
        if ($label !== '') {
            return $label;
        }

        return $this->approvalRecordRoleLabel($record).' approval';
    }

    private function approvalRecordRoleLabel(OrgApprovalRecord $record): string
    {
        $role = HrRole::tryFrom((string) $record->approver_role);

        return $role?->badgeLabel() ?? (string) ($record->approver_role ?: 'Approver');
    }

    private function approvalRecordSummary($records): array
    {
        $currentMarked = false;

        return $records->map(function (OrgApprovalRecord $record) use (&$currentMarked): array {
            $status = match ($record->approval_status) {
                OrgApprovalRecord::STATUS_APPROVED => 'completed',
                OrgApprovalRecord::STATUS_REJECTED => 'rejected',
                default => $currentMarked ? 'pending' : 'current',
            };
            if ($status === 'current') {
                $currentMarked = true;
            }

            return [
                'key' => 'approval-'.$record->id,
                'label' => $this->approvalRecordStageLabel($record),
                'status' => $status,
                'approver_role_label' => $this->approvalRecordRoleLabel($record),
                'approver_name' => $record->approver?->display_name ?? $record->approver_name,
                'remarks' => $record->remarks,
                'acted_at' => $record->approved_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.presence_filings.show');
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $correction = AttendanceCorrection::query()
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
            ->findOrFail($id);

        if (! $this->canAccessPresenceFilingThroughApprovalScope($actor, $correction)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tz = $this->presenceFilingService->attendanceTimezone();
        RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin']);

        return response()->json([
            'presence_filing' => $this->correctionFormatter->format($correction, $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ]);
    }

    public function adminReview(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.presence_filings.review');
        $started = microtime(true);
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Log::info('admin.presence_filings.review_start', [
            'attendance_correction_id' => $id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'hr_role' => $this->hrRoleResolver->resolve($actor)->value,
        ]);

        $correction = AttendanceCorrection::query()
            ->select([
                'id', 'user_id', 'date', 'time_in', 'time_out', 'remarks', 'issue_kind',
                'approved', 'approved_by', 'approved_at', 'pending_approval', 'reason_code',
                'filed_at', 'filed_by', 'rejected_at', 'rejected_by', 'rejection_note',
                'approval_stage', 'first_approver_id', 'first_approved_at',
                'second_approver_id', 'second_approved_at', 'is_incomplete_record',
                'attendance_logs_synced_at', 'attendance_logs_synced_by', 'created_at', 'updated_at',
            ])
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,role,employee_code,department,department_id,branch_id,company_id,profile_image,position',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'attendanceLogsSyncedBy:id,name,first_name,middle_name,last_name,suffix',
                'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver:id,name,first_name,middle_name,last_name,suffix'),
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin:id,name,first_name,middle_name,last_name,suffix'),
            ])
            ->find($id);

        if ($correction === null) {
            Log::warning('admin.presence_filings.review_not_found', [
                'attendance_correction_id' => $id,
                'actor_id' => $actor->id,
            ]);

            return response()->json(['message' => 'Attendance correction not found.'], 404);
        }

        if (! $this->canAccessPresenceFilingThroughApprovalScope($actor, $correction)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $tz = $this->presenceFilingService->attendanceTimezone();
        $cached = ReviewRequestCache::remember('attendance_correction', $id, fn () => $this->correctionFormatter->format(
            $correction,
            $tz,
            includeEmployee: true,
            actor: $actor,
            includeDisplayFields: true
        ));

        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'mode' => 'review',
            'cache_hit' => $cached['cache_hit'],
            'query_count' => $cached['query_count'],
            'cache_error' => $cached['cache_error'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        Log::info('admin.presence_filings.review_ok', [
            'attendance_correction_id' => $id,
            'actor_id' => $actor->id,
            'cache_hit' => $cached['cache_hit'],
            'query_count' => $cached['query_count'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return response()->json(['presence_filing' => $cached['payload']]);
    }

    public function adminReviewLite(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.presence_filings.review_lite');
        $actor = $request->user();
        $started = microtime(true);
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cached = ReviewRequestCache::rememberAttendanceCorrectionReviewLite($id, (int) $actor->id, function () use ($actor, $id): array {
            $c = AttendanceCorrection::query()
                ->select(['id', 'user_id', 'date', 'time_in', 'time_out', 'remarks', 'issue_kind', 'approved', 'approved_by', 'approved_at', 'pending_approval', 'filed_at', 'filed_by', 'rejected_at', 'rejected_by', 'rejection_note', 'approval_stage', 'first_approver_id', 'second_approver_id', 'created_at'])
                ->with(['user:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,department_id,department'])
                ->findOrFail($id);
            if (! $this->canAccessPresenceFilingThroughApprovalScope($actor, $c)) {
                abort(403, 'Forbidden.');
            }
            $records = OrgApprovalRecord::query()
                ->select(['id', 'request_id', 'module_type', 'approval_label', 'approver_role', 'approver_id', 'approver_name', 'eligible_approver_ids', 'approval_status', 'remarks', 'approved_at', 'sequence_order'])
                ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
                ->where('request_id', (int) $c->id)
                ->with('approver:id,name,first_name,middle_name,last_name,suffix')
                ->orderBy('sequence_order')
                ->orderBy('id')
                ->get();
            $pending = $records->firstWhere('approval_status', OrgApprovalRecord::STATUS_PENDING);
            $auth = $this->attendanceCorrectionActionAuthorization($actor, $c, $pending);

            return $this->attendanceCorrectionReviewLiteResponse($c, $records, $pending, $auth);
        });

        $payload = $cached['payload'];
        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'mode' => 'review_lite',
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'cache_hit' => $cached['cache_hit'] ?? false,
            'query_count' => $cached['query_count'] ?? null,
            'cache_error' => $cached['cache_error'] ?? null,
        ]);

        return response()->json(['presence_filing' => $payload]);
    }

    public function bulkApprovePreview(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'filters' => ['required', 'array'],
        ]);

        $filters = $this->normalizeBulkApproveFilters($validated['filters']);
        $count = $this->bulkApprovalQuery->approvableCount($actor, $filters);

        return response()->json([
            'approvable_count' => $count,
        ]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $parsed = $this->parseBulkApproveRequest($request);

        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $skipped = 0;
        $failedItems = [];

        if ($parsed['mode'] === 'all_matching') {
            $filters = $this->normalizeBulkApproveFilters($parsed['filters']);
            $ids = $this->bulkApprovalQuery->approvableIds($actor, $filters);
        } else {
            $this->assertBulkApproveIdsPresent($parsed['ids']);
            if (count($parsed['ids']) > 500) {
                throw ValidationException::withMessages([
                    'ids' => ['Too many requests selected. Use “select all matching” or approve in smaller batches.'],
                ]);
            }
            $resolved = $this->resolveBulkApproveIds($parsed['ids'], function (int $id) use ($actor): bool {
                $correction = AttendanceCorrection::query()
                    ->with([
                        'user',
                        'filedBy',
                        'firstApprover',
                        'secondApprover',
                        'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                    ])
                    ->find($id);

                return $correction !== null && $this->bulkApprovalQuery->canBulkApprove($actor, $correction);
            });
            $ids = $resolved['ids'];
            $skipped = $resolved['skipped'];
            $failedItems = $resolved['failed_items'];
        }

        if (count($ids) === 0) {
            return $this->bulkApproveJsonResponse(0, $skipped, 0, $failedItems, 'attendance correction');
        }

        $remarks = $parsed['remarks'];
        $approved = 0;
        $failed = 0;
        $payrollDates = [];

        foreach ($ids as $id) {
            try {
                $single = $this->duplicateBulkApproveRequest($request, $remarks, ['_bulk_approval' => true]);
                $single->setUserResolver(fn () => $actor);
                $response = $this->approve($single, $id);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $approved++;
                    $body = $response->getData(true);
                    if (($body['status'] ?? null) === 'approved' && ! empty($body['date'])) {
                        $payrollDates[(string) $body['date']] = true;
                    }
                    continue;
                }

                $body = $response->getData(true);
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => (string) ($body['message'] ?? 'Attendance correction was skipped.'),
                ];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => 'Attendance correction was not found.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : ($e->getMessage() ?: 'Bulk approval failed for this attendance correction.'),
                ];
            }
        }

        if ($approved > 0) {
            foreach (array_keys($payrollDates) as $dateKey) {
                ProcessDailyPayrollJob::dispatchSync($dateKey);
            }
            AttendanceCorrectionModuleCache::flush();
        }

        return $this->bulkApproveJsonResponse($approved, $skipped, $failed, $failedItems, 'attendance correction');
    }

    public function bulkReject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $rejected = 0;
        $skipped = 0;
        $failedItems = [];
        foreach (array_values(array_unique(array_map('intval', $validated['ids']))) as $id) {
            try {
                $single = $this->duplicateBulkApproveRequest($request, null, [
                    'rejection_note' => $validated['remarks'],
                ]);
                $single->setUserResolver(fn () => $actor);
                $response = $this->reject($single, $id);
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $rejected++;
                } else {
                    $skipped++;
                    $failedItems[] = ['request_id' => $id, 'reason' => (string) ($response->getData(true)['message'] ?? 'Skipped')];
                }
            } catch (\Throwable $e) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => $e->getMessage()];
            }
        }
        AttendanceCorrectionModuleCache::flush();

        return response()->json([
            'rejected_count' => $rejected,
            'skipped_count' => $skipped,
            'failed_count' => 0,
            'failed_items' => $failedItems,
            'skipped_reasons' => $failedItems,
        ]);
    }

    public function bulkApproveFiltered(Request $request): JsonResponse
    {
        $request->merge([
            'mode' => 'all_matching',
            'filters' => $request->input('filters', []),
            'remarks' => $request->input('remarks'),
        ]);

        return $this->bulkApprove($request);
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

        $correction = AttendanceCorrection::query()->with(['user.workingSchedule', 'filedBy'])->findOrFail($id);
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

        $actorRole = $this->hrRoleResolver->resolve($actor);
        $roleLabel = $actorRole->badgeLabel();
        $currentApproval = $this->approvalWorkflowService->currentPendingRecord(
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            $employee,
            $correction->filedBy,
        );
        $isHrFinalStep = $currentApproval?->approver_role === HrRole::AdminHr->value;

        if (! $isHrFinalStep) {
            try {
                $d = Carbon::parse($dateKey)->startOfDay();
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $nextPending = DB::transaction(function () use ($correction, $actor, $validated, $employee, $dateKey, $roleLabel) {
                $nextPending = $this->approvalWorkflowService->approveCurrent(
                    $correction,
                    OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
                    $employee,
                    $actor,
                    $validated['notes'] ?? null,
                    $correction->filedBy,
                );

                if ($correction->first_approver_id === null) {
                    $correction->first_approver_id = $actor->id;
                }
                if ($nextPending?->approver_role === HrRole::AdminHr->value) {
                    $correction->first_approved_at = now();
                    $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_PENDING_SECOND;
                } else {
                    $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST;
                }
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
                    'level' => $this->approvalWorkflowService
                        ->records(OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION, (int) $correction->id)
                        ->where('approval_status', 'approved')
                        ->max('sequence_order') ?? 1,
                    'status' => 'approved',
                    'notes' => $validated['notes'] ?? null,
                    'acted_at' => now(),
                ]);

                return $nextPending;
            });

            $nextLabel = $nextPending?->approver_role
                ? (HrRole::tryFrom((string) $nextPending->approver_role)?->badgeLabel() ?? 'next approver')
                : 'next approver';

            ReviewRequestCache::forget('attendance_correction', (int) $correction->id);
            if (! $this->isBulkApprovalRequest($request)) {
                AttendanceCorrectionModuleCache::flush();
            }

            if ($this->wantsLiteAttendanceCorrectionMutationResponse($request)) {
                return response()->json([
                    'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                    'request_id' => (int) $correction->id,
                    'status' => 'pending',
                    'date' => $dateKey,
                    'approval_stage' => $correction->approval_stage,
                ]);
            }

            return response()->json([
                'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
            ]);
        }

        $timeIn = $correction->time_in ? $correction->time_in->copy()->timezone($tz) : null;
        $timeOut = $correction->time_out ? $correction->time_out->copy()->timezone($tz) : null;
        
        // Ensure issue_kind is properly set for sync service
        $issueKind = $this->normalizeIssueKind($correction);
        
        if ($issueKind === 'missing_in' && $timeIn === null) {
            return response()->json(['message' => 'Missing clock-in request requires a clock-in time.'], 422);
        }
        if ($issueKind === 'missing_out' && $timeOut === null) {
            return response()->json(['message' => 'Missing clock-out request requires a clock-out time.'], 422);
        }
        if ($issueKind === 'both' && ($timeIn === null || $timeOut === null)) {
            return response()->json(['message' => 'Both clock-in and clock-out times are required for this request.'], 422);
        }
        if ($timeIn !== null && $timeOut !== null && $timeOut->lessThanOrEqualTo($timeIn)) {
            return response()->json(['message' => 'Time out must be after time in.'], 422);
        }

        $previousIn = $correction->time_in;
        $previousOut = $correction->time_out;
        $isRequesterFinalApprover = (int) $actor->id === (int) ($correction->filed_by ?? $correction->user_id)
            && $actorRole === HrRole::AdminHr
            && $isHrFinalStep;
        $finalApprovalNote = $validated['notes']
            ?? ($isRequesterFinalApprover
                ? 'Approved by requester as Admin/HR final approver.'
                : 'Final approval (Admin HR).');

        try {
            $d = Carbon::parse($dateKey)->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        DB::transaction(function () use ($correction, $actor, $employee, $dateKey, $timeIn, $timeOut, $previousIn, $previousOut, $roleLabel, $issueKind, $finalApprovalNote) {
            $this->approvalWorkflowService->approveCurrent(
                $correction,
                OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
                $employee,
                $actor,
                $finalApprovalNote,
                $correction->filedBy,
            );

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
                'reason' => $finalApprovalNote,
                'action' => 'approve_final',
                'approver_role' => $roleLabel,
            ]);

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => 2,
                'status' => 'approved',
                'notes' => $finalApprovalNote,
                'acted_at' => now(),
            ]);

            // Date-specific sync: the approved filing only rewrites/creates punches for $dateKey.
            // Other attendance days must remain available for normal clock-in/out.
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

        $this->overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $correction->time_out, $actor);

        if (! $this->isBulkApprovalRequest($request)) {
            ProcessDailyPayrollJob::dispatchSync($dateKey);
        }
        ReviewRequestCache::forget('attendance_correction', (int) $correction->id);
        if (! $this->isBulkApprovalRequest($request)) {
            AttendanceCorrectionModuleCache::flush();
        }

        if ($this->wantsLiteAttendanceCorrectionMutationResponse($request)) {
            return response()->json([
                'message' => 'Attendance correction approved.',
                'request_id' => (int) $correction->id,
                'status' => 'approved',
                'date' => $dateKey,
                'approval_stage' => AttendanceCorrectionApprovalService::STAGE_APPROVED,
            ]);
        }

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
            $rejectedStep = $this->approvalWorkflowService->rejectCurrent(
                $correction,
                OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
                $employee,
                $actor,
                $validated['rejection_note'],
                $correction->filedBy,
            );

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

            AttendanceCorrectionApproval::create([
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => $rejectedStep?->sequence_order ?? 1,
                'status' => 'rejected',
                'notes' => $validated['rejection_note'],
                'acted_at' => now(),
            ]);
        });

        $tz = $this->presenceFilingService->attendanceTimezone();
        ReviewRequestCache::forget('attendance_correction', (int) $correction->id);
        AttendanceCorrectionModuleCache::flush();

        if ($this->wantsLiteAttendanceCorrectionMutationResponse($request)) {
            return response()->json([
                'message' => 'Attendance correction rejected.',
                'request_id' => (int) $correction->id,
                'status' => 'rejected',
                'approval_stage' => AttendanceCorrectionApprovalService::STAGE_REJECTED,
            ]);
        }

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
        ReviewRequestCache::forget('attendance_correction', (int) $correction->id);
        AttendanceCorrectionModuleCache::flush();

        return response()->json([
            'message' => 'Remark recorded.',
            'presence_filing' => $this->correctionFormatter->format($this->correctionFormatter->freshWithDisplayRelations($correction), $tz, includeEmployee: true, actor: $actor, includeDisplayFields: true),
        ]);
    }
}
