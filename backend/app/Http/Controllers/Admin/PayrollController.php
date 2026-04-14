<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayCycle;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\PayCycleService;
use App\Services\PayrollComputationService;
use App\Services\PayrollPersistService;
use App\Services\PayrollRulesEngineService;
use App\Support\PhPayrollReference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollComputationService $payroll,
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly DataScopeService $dataScopeService,
        private readonly PayCycleService $payCycleService,
        private readonly PayrollPersistService $payrollPersistService,
    ) {}

    /**
     * Phase 1 MVP: Classify attendance (NO PAY).
     * Returns: regular_hours, overtime_hours, night_hours, is_rest_day, holiday_type per day.
     */
    public function classify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
        ]);

        $user = User::query()->findOrFail($validated['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $user);
        [$from, $to, $cyclePreview] = $this->resolvePeriodContext($user, $validated);

        $result = $this->rulesEngine->classifyRange($user, $from, $to);
        $result['pay_cycle_preview'] = $cyclePreview;

        return response()->json($result);
    }

    /**
     * Compute payroll for an employee (preview, no save).
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'daily_rate_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = User::query()->findOrFail($validated['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $user);
        [$from, $to, $cyclePreview] = $this->resolvePeriodContext($user, $validated);

        $dailyRateOverride = isset($validated['daily_rate_override']) && $validated['daily_rate_override'] > 0
            ? (float) $validated['daily_rate_override']
            : null;

        $result = $this->payroll->computeEmployeePayroll($user, $from, $to, $dailyRateOverride);
        $result['pay_cycle_preview'] = $cyclePreview;

        return response()->json($result);
    }

    /**
     * Compute and optionally save payroll for a period (audit-grade breakdown).
     */
    public function compute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'daily_rate_override' => ['nullable', 'numeric', 'min:0'],
            'save' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->findOrFail($validated['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $user);
        [$from, $to, $cyclePreview, $cycle] = $this->resolvePeriodContext($user, $validated, true);
        $save = (bool) ($validated['save'] ?? false);

        $dailyRateOverride = isset($validated['daily_rate_override']) && $validated['daily_rate_override'] > 0
            ? (float) $validated['daily_rate_override']
            : null;

        $result = $this->payroll->computeEmployeePayroll($user, $from, $to, $dailyRateOverride);
        $result['pay_cycle_preview'] = $cyclePreview;

        if ($save) {
            $period = $this->payrollPersistService->persistComputedPayroll($user, $from, $to, $result, $cyclePreview, $cycle);
            if ($period) {
                $result['payroll_period_id'] = $period->id;
            }
        }

        return response()->json($result);
    }

    /**
     * List payroll periods (for admin).
     */
    public function periods(Request $request): JsonResponse
    {
        $perPage = max(1, min(20, (int) $request->integer('per_page', 6)));
        $query = PayrollPeriod::query()
            // Keep list endpoint lightweight: avoid selecting large JSON/audit blobs.
            ->select([
                'id',
                'user_id',
                'from_date',
                'to_date',
                'pay_date',
                'cycle_label',
                'status',
                'net_pay',
                'created_at',
            ])
            ->with('user:id,name,employee_code,department');

        $scope = User::query()->where('role', User::ROLE_EMPLOYEE);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $scope);
        $query->whereIn('user_id', $scope->select('users.id'));

        if ($request->has('employee_id')) {
            $query->where('user_id', $request->integer('employee_id'));
        }
        if ($request->has('from_date')) {
            $query->where('to_date', '>=', $request->string('from_date'));
        }
        if ($request->has('to_date')) {
            $query->where('from_date', '<=', $request->string('to_date'));
        }

        $periods = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($periods);
    }

    /**
     * Get a payroll period with full audit breakdown.
     */
    public function showPeriod(Request $request, int $id): JsonResponse
    {
        $period = PayrollPeriod::query()
            ->with(['user:id,name,employee_code,department,daily_rate', 'breakdowns'])
            ->findOrFail($id);

        if ($period->user) {
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $period->user);
        }

        return response()->json($period);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon, 2: array<string, mixed>|null, 3?: ?PayCycle}
     */
    private function resolvePeriodContext(User $user, array $validated, bool $includeCycle = false): array
    {
        if (! empty($validated['from_date']) && ! empty($validated['to_date'])) {
            $from = Carbon::parse($validated['from_date'])->startOfDay();
            $to = Carbon::parse($validated['to_date'])->endOfDay();
            $cycle = null;
            if (! empty($validated['pay_cycle_id'])) {
                $cycle = PayCycle::query()->find((int) $validated['pay_cycle_id']);
            }
            $preview = $cycle ? $this->payCycleService->buildCyclePreview($cycle, $validated['reference_date'] ?? $validated['from_date']) : null;

            return $includeCycle ? [$from, $to, $preview, $cycle] : [$from, $to, $preview];
        }

        $referenceDate = (string) ($validated['reference_date'] ?? now()->toDateString());
        $cycle = ! empty($validated['pay_cycle_id'])
            ? PayCycle::query()->find((int) $validated['pay_cycle_id'])
            : $this->payCycleService->resolveForUser($user);
        $preview = $cycle ? $this->payCycleService->buildCyclePreview($cycle, $referenceDate) : null;
        $from = Carbon::parse($preview['cut_off_start_date'] ?? $referenceDate)->startOfDay();
        $to = Carbon::parse($preview['cut_off_end_date'] ?? $referenceDate)->endOfDay();

        return $includeCycle ? [$from, $to, $preview, $cycle] : [$from, $to, $preview];
    }

    /**
     * Daily computation logs for admin UI: per employee per date with PH rules engine
     * (holiday, rest day, OT, ND) and OT approval flags.
     */
    public function dailyLogs(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        Log::info('Payroll dailyLogs request start', [
            'actor_user_id' => (int) $request->user()->id,
            'query' => [
                'from_date' => $request->query('from_date'),
                'to_date' => $request->query('to_date'),
                'page' => $request->query('page'),
                'per_page' => $request->query('per_page'),
                'status' => $request->query('status'),
                'company_id' => $request->query('company_id'),
                'search' => $request->query('search') !== null,
            ],
        ]);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'in:all,valid,needs_review,flagged'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $fromInput = (string) ($validated['from_date'] ?? $validated['start_date'] ?? '');
        $toInput = (string) ($validated['to_date'] ?? $validated['end_date'] ?? '');
        if ($fromInput === '' || $toInput === '') {
            return response()->json(['message' => 'from_date/to_date (or start_date/end_date) are required.'], 422);
        }
        if (Carbon::parse($toInput)->lt(Carbon::parse($fromInput))) {
            return response()->json(['message' => 'to_date/end_date must be after or equal to from_date/start_date.'], 422);
        }

        // Calendar dates must be interpreted in the attendance timezone (e.g. Asia/Manila), not UTC,
        // so Y-m-d ranges include the full local day and match getTimesForDate / session logic.
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $from = Carbon::createFromFormat('Y-m-d', $fromInput, $tz)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $toInput, $tz)->startOfDay();
        if ($from->diffInDays($to) > 62) {
            return response()->json([
                'message' => 'Date range cannot exceed 62 days.',
            ], 422);
        }

        $result = $this->payroll->dailyComputationLogsForAdmin(
            $request->user(),
            $from,
            $to,
            isset($validated['search']) ? trim((string) $validated['search']) : null,
            ($validated['status'] ?? 'all') === 'all' ? null : (string) ($validated['status'] ?? 'all'),
            isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 15),
        );

        Log::info('Payroll dailyLogs request completed', [
            'actor_user_id' => (int) $request->user()->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 15),
            'rows_returned' => is_array($result['data'] ?? null) ? count($result['data']) : 0,
            'total_rows' => (int) (($result['meta']['total'] ?? 0)),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($result);
    }

    /**
     * Single JSON snapshot of PH payroll policy factors, matrices, and implementing modules.
     * Aligns HR manual §§6–9 with runtime config (`payroll.rules`) and services.
     */
    public function policyReference(): JsonResponse
    {
        return response()->json(PhPayrollReference::policyEngineReference());
    }
}
