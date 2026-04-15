<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpsertStatutoryRateRequest;
use App\Models\EmployeeStatutoryContribution;
use App\Models\EmployeeTaxInfo;
use App\Models\SssBracket;
use App\Models\StatutoryContribution;
use App\Models\StatutoryRateHistory;
use App\Models\TaxTable;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\PayrollCalculatorService;
use App\Services\RemittanceService;
use App\Services\TaxComputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GovernmentContributionController extends Controller
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly DataScopeService $dataScopeService,
        private readonly RemittanceService $remittanceService,
        private readonly TaxComputationService $taxComputation,
    ) {}

    public function rates(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id');
        $rows = $this->calculator->latestRatesByCode($companyId)->values();
        $sssSchedule = [];
        if (Schema::hasTable('sss_brackets')) {
            $hasRangeStart = Schema::hasColumn('sss_brackets', 'range_start');
            $hasRangeEnd = Schema::hasColumn('sss_brackets', 'range_end');
            $hasRangeFrom = Schema::hasColumn('sss_brackets', 'range_from');
            $hasRangeTo = Schema::hasColumn('sss_brackets', 'range_to');
            $hasRangeLabel = Schema::hasColumn('sss_brackets', 'range_label');
            $hasSalaryMin = Schema::hasColumn('sss_brackets', 'salary_min');
            $hasSalaryMax = Schema::hasColumn('sss_brackets', 'salary_max');
            $hasEmployerSS = Schema::hasColumn('sss_brackets', 'employer_ss');
            $hasEmployerEC = Schema::hasColumn('sss_brackets', 'employer_ec');
            $hasEmployerTotal = Schema::hasColumn('sss_brackets', 'employer_total');
            $hasEmployeeSS = Schema::hasColumn('sss_brackets', 'employee_ss');
            $hasEmployeeTotal = Schema::hasColumn('sss_brackets', 'employee_total');
            $hasOverallTotal = Schema::hasColumn('sss_brackets', 'overall_total');
            $hasEeShare = Schema::hasColumn('sss_brackets', 'ee_share');
            $hasErShare = Schema::hasColumn('sss_brackets', 'er_share');
            $hasEcAmount = Schema::hasColumn('sss_brackets', 'ec_amount');
            $hasTotal = Schema::hasColumn('sss_brackets', 'total');

            $columns = ['id', 'msc', 'effective_from'];
            foreach ([
                'range_start' => $hasRangeStart,
                'range_end' => $hasRangeEnd,
                'range_from' => $hasRangeFrom,
                'range_to' => $hasRangeTo,
                'range_label' => $hasRangeLabel,
                'salary_min' => $hasSalaryMin,
                'salary_max' => $hasSalaryMax,
                'employer_ss' => $hasEmployerSS,
                'employer_ec' => $hasEmployerEC,
                'employer_total' => $hasEmployerTotal,
                'employee_ss' => $hasEmployeeSS,
                'employee_total' => $hasEmployeeTotal,
                'overall_total' => $hasOverallTotal,
                'ee_share' => $hasEeShare,
                'er_share' => $hasErShare,
                'ec_amount' => $hasEcAmount,
                'total' => $hasTotal,
            ] as $col => $ok) {
                if ($ok) {
                    $columns[] = $col;
                }
            }

            $query = SssBracket::query()->where('is_active', true);
            if (Schema::hasColumn('sss_brackets', 'effective_from')) {
                $latestEffective = SssBracket::query()
                    ->where('is_active', true)
                    ->max('effective_from');
                if ($latestEffective) {
                    $query->whereDate('effective_from', $latestEffective);
                }
            }
            if ($hasRangeStart) {
                $query->orderBy('range_start');
            } elseif ($hasRangeFrom) {
                $query->orderBy('range_from');
            } elseif ($hasSalaryMin) {
                $query->orderBy('salary_min');
            }
            if ($hasSalaryMin) {
                $query->orderBy('salary_min');
            }

            $rawRows = $query->get($columns);
            $sssSchedule = $rawRows->map(function ($row) use ($hasEmployerSS, $hasEmployerEC, $hasEmployerTotal, $hasEmployeeSS, $hasEmployeeTotal, $hasOverallTotal, $hasEeShare, $hasErShare, $hasEcAmount, $hasTotal) {
                $msc = (float) ($row->msc ?? 0);
                $employeeSS = $hasEmployeeSS ? (float) ($row->employee_ss ?? 0) : round($msc * 0.05, 2);
                $employerSS = $hasEmployerSS ? (float) ($row->employer_ss ?? 0) : round($msc * 0.10, 2);
                $employerEC = $hasEmployerEC ? (float) ($row->employer_ec ?? 0) : 30.0;
                $employeeTotal = $hasEmployeeTotal ? (float) ($row->employee_total ?? $employeeSS) : $employeeSS;
                $employerTotal = $hasEmployerTotal ? (float) ($row->employer_total ?? ($employerSS + $employerEC)) : round($employerSS + $employerEC, 2);
                $overallTotal = $hasOverallTotal ? (float) ($row->overall_total ?? ($employeeTotal + $employerTotal)) : round($employeeTotal + $employerTotal, 2);

                return [
                    'id' => $row->id,
                    'range_start' => $row->range_start ?? $row->range_from ?? $row->salary_min ?? null,
                    'range_end' => $row->range_end ?? $row->range_to ?? $row->salary_max ?? null,
                    'range_label' => $row->range_label ?? null,
                    'range_from' => $row->range_from ?? $row->range_start ?? $row->salary_min ?? null,
                    'range_to' => $row->range_to ?? $row->range_end ?? $row->salary_max ?? null,
                    'salary_min' => $row->salary_min ?? null,
                    'salary_max' => $row->salary_max ?? null,
                    'msc' => $msc,
                    'ee_share' => $hasEeShare ? (float) ($row->ee_share ?? $employeeSS) : $employeeSS,
                    'er_share' => $hasErShare ? (float) ($row->er_share ?? $employerSS) : $employerSS,
                    'ec_amount' => $hasEcAmount ? (float) ($row->ec_amount ?? $employerEC) : $employerEC,
                    'total' => $hasTotal ? (float) ($row->total ?? $overallTotal) : $overallTotal,
                    'employer_ss' => $employerSS,
                    'employer_ec' => $employerEC,
                    'employer_total' => $employerTotal,
                    'employee_ss' => $employeeSS,
                    'employee_total' => $employeeTotal,
                    'overall_total' => $overallTotal,
                    'effective_from' => $row->effective_from,
                ];
            })->values();
        }

        return response()->json(['rates' => $rows, 'sss_schedule' => $sssSchedule]);
    }

    public function upsertRate(UpsertStatutoryRateRequest $request, string $code): JsonResponse
    {
        $validated = $request->validated();

        $normalizedCode = strtoupper(trim($code));
        if (! in_array($normalizedCode, ['SSS', 'PHILHEALTH', 'PAGIBIG', 'EC'], true)) {
            return response()->json(['message' => 'Invalid statutory code.'], 422);
        }

        $lookup = [
            'code' => $normalizedCode,
            'effective_from' => $validated['effective_from'],
            'company_id' => $validated['company_id'] ?? null,
        ];
        $payload = [
            'name' => $validated['name'],
            'employer_rate' => $validated['employer_rate'],
            'employee_rate' => $validated['employee_rate'],
            'min_salary' => $validated['min_salary'] ?? null,
            'max_salary' => $validated['max_salary'] ?? null,
            'msc' => $validated['msc'] ?? null,
            'salary_floor' => $validated['salary_floor'] ?? null,
            'salary_ceiling' => $validated['salary_ceiling'] ?? null,
            'tier_threshold' => $validated['tier_threshold'] ?? null,
            'monthly_cap' => $validated['monthly_cap'] ?? null,
            'brackets' => $validated['brackets'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'metadata' => $validated['metadata'] ?? null,
            'compliance_reference' => $validated['compliance_reference'] ?? null,
        ];
        $existing = StatutoryContribution::query()->where($lookup)->first();
        $row = StatutoryContribution::updateOrCreate($lookup, $payload);

        $trackedFields = [
            'name', 'code', 'employer_rate', 'employee_rate', 'min_salary', 'max_salary', 'msc',
            'salary_floor', 'salary_ceiling', 'tier_threshold', 'monthly_cap', 'is_active',
            'metadata', 'compliance_reference', 'effective_from', 'company_id',
        ];
        $oldValues = $existing ? Arr::only($existing->toArray(), $trackedFields) : null;
        $newValues = Arr::only($row->toArray(), $trackedFields);
        $changedFields = [];
        if ($oldValues !== null) {
            foreach ($trackedFields as $field) {
                if (($oldValues[$field] ?? null) !== ($newValues[$field] ?? null)) {
                    $changedFields[$field] = [
                        'old' => $oldValues[$field] ?? null,
                        'new' => $newValues[$field] ?? null,
                    ];
                }
            }
        }

        if (Schema::hasTable('statutory_rate_histories')) {
            StatutoryRateHistory::query()->create([
                'statutory_contribution_id' => $row->id,
                'code' => $normalizedCode,
                'company_id' => $row->company_id,
                'effective_from' => $row->effective_from,
                'action' => $existing ? 'updated' : 'created',
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'changed_fields' => $changedFields !== [] ? $changedFields : null,
                'changed_by_user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        }

        return response()->json(['message' => 'Statutory rate saved.', 'rate' => $row]);
    }

    public function rateHistory(Request $request): JsonResponse
    {
        if (! Schema::hasTable('statutory_rate_histories')) {
            return response()->json(['history' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 20]]);
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'in:SSS,PHILHEALTH,PAGIBIG,EC'],
            'company_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $query = StatutoryRateHistory::query()
            ->with(['changedBy:id,name,email'])
            ->orderByDesc('created_at');

        if (! empty($validated['code'])) {
            $query->where('code', strtoupper((string) $validated['code']));
        }

        if (array_key_exists('company_id', $validated) && $validated['company_id'] !== null) {
            $query->where(function ($q) use ($validated) {
                $q->whereNull('company_id')->orWhere('company_id', (int) $validated['company_id']);
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn (StatutoryRateHistory $row) => [
            'id' => $row->id,
            'code' => $row->code,
            'effective_from' => optional($row->effective_from)->toDateString(),
            'action' => $row->action,
            'old_values' => $row->old_values,
            'new_values' => $row->new_values,
            'changed_fields' => $row->changed_fields,
            'changed_by' => $row->changedBy ? [
                'id' => $row->changedBy->id,
                'name' => $row->changedBy->name,
                'email' => $row->changedBy->email,
            ] : null,
            'created_at' => optional($row->created_at)->toDateTimeString(),
        ])->values();

        return response()->json([
            'history' => $rows,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'withholding_method' => ['nullable', 'string', 'in:annualized,per_period_monthly'],
            'period_type' => ['nullable', 'string', 'in:monthly,semimonthly'],
        ]);

        $employee = null;
        $basicSalary = null;
        if (isset($validated['employee_id'])) {
            $employee = User::query()->findOrFail((int) $validated['employee_id']);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
            $basicSalary = $this->calculator->resolveBasicSalaryForPayroll($employee);
        } elseif (array_key_exists('basic_salary', $validated)) {
            // JSON may send null when the client used NaN; treat null/empty as 0 for the calculator.
            $v = $validated['basic_salary'];
            $basicSalary = ($v === null || $v === '') ? 0.0 : (float) $v;
        }

        if ($basicSalary === null) {
            return response()->json(['message' => 'basic_salary or employee_id is required.'], 422);
        }

        $breakdown = $this->calculator->calculateAllStatutoryContributions($basicSalary);

        $whtParams = [
            'monthly_taxable_compensation' => $basicSalary,
            'method' => $validated['withholding_method'] ?? 'annualized',
            'period_type' => $validated['period_type'] ?? 'monthly',
        ];
        if ($employee !== null) {
            $tp = $this->taxComputation->buildTaxProfileFromEmployee($employee);
            if ($tp !== []) {
                $whtParams['tax_profile'] = $tp;
            }
        }

        $withholding = $this->calculator->calculateWithholdingTax($whtParams);

        $breakdown['withholding'] = $withholding;
        $whtMonthly = (float) ($withholding['withholding_per_month'] ?? 0);
        $combined = (float) ($breakdown['totals']['combined_remittance'] ?? 0);
        $eeDed = (float) ($breakdown['totals']['employee_deduction'] ?? 0);

        $breakdown['totals']['withholding_tax_monthly'] = round($whtMonthly, 2);
        $breakdown['totals']['employee_deduction_including_tax'] = round($eeDed + $whtMonthly, 2);
        $breakdown['totals']['grand_total_statutory_and_tax'] = round($combined + $whtMonthly, 2);

        return response()->json(['breakdown' => $breakdown]);
    }

    /**
     * Preview BIR withholding (RR 11-2018 Table A monthly, after mandatory EE contributions; TRAIN annual for 13th supplement).
     */
    public function previewWithholdingTax(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'earnings' => ['nullable', 'array'],
            'earnings.*.code' => ['required_with:earnings', 'string'],
            'earnings.*.amount' => ['required_with:earnings', 'numeric', 'min:0'],
            'earnings.*.taxable' => ['required_with:earnings', 'boolean'],
            'earnings.*.label' => ['nullable', 'string'],
            'monthly_taxable_compensation' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'string', 'in:annualized,per_period_monthly'],
            'period_type' => ['nullable', 'string', 'in:monthly,semimonthly'],
            'thirteenth_month_amount' => ['nullable', 'numeric', 'min:0'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'tax_profile' => ['nullable', 'array'],
            'tax_profile.is_mwe' => ['nullable', 'boolean'],
            'tax_profile.mwe_monthly_ceiling' => ['nullable', 'numeric', 'min:0'],
            'tax_profile.is_senior_citizen' => ['nullable', 'boolean'],
            'tax_profile.is_pwd' => ['nullable', 'boolean'],
            'tax_profile.is_solo_parent' => ['nullable', 'boolean'],
            'tax_profile.tax_regime' => ['nullable', 'string', 'max:32'],
            'tax_profile.additional_exemption_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $params = [
            'method' => $validated['method'] ?? 'annualized',
            'period_type' => $validated['period_type'] ?? 'monthly',
            'thirteenth_month_amount' => isset($validated['thirteenth_month_amount'])
                ? (float) $validated['thirteenth_month_amount']
                : 0.0,
        ];

        if (! empty($validated['tax_profile'])) {
            $params['tax_profile'] = $validated['tax_profile'];
        }

        if (! empty($validated['earnings'])) {
            $params['earnings'] = $validated['earnings'];
        } elseif (isset($validated['employee_id'])) {
            $employee = User::query()->findOrFail((int) $validated['employee_id']);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
            $base = $this->calculator->resolveBasicSalaryForPayroll($employee);
            $params['monthly_taxable_compensation'] = $base;
            if (Schema::hasTable('employee_tax_info')) {
                $info = EmployeeTaxInfo::query()->where('user_id', $employee->id)->first();
                if ($info) {
                    $params['method'] = $info->withholding_method ?: $params['method'];
                    $params['period_type'] = $info->period_type ?: $params['period_type'];
                }
            }
            $merged = $this->taxComputation->buildTaxProfileFromEmployee($employee);
            if ($merged !== []) {
                $params['tax_profile'] = array_merge($merged, $params['tax_profile'] ?? []);
            }
        } elseif (isset($validated['monthly_taxable_compensation'])) {
            $params['monthly_taxable_compensation'] = (float) $validated['monthly_taxable_compensation'];
        } else {
            return response()->json([
                'message' => 'Provide earnings[], monthly_taxable_compensation, or employee_id.',
            ], 422);
        }

        $result = $this->calculator->calculateWithholdingTax($params);

        return response()->json(['withholding' => $result]);
    }

    /**
     * Classify pay component lines into taxable vs non-taxable totals (same engine as payroll).
     */
    public function classifyEarnings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'earnings' => ['required', 'array', 'min:1'],
            'earnings.*.code' => ['required', 'string'],
            'earnings.*.amount' => ['required', 'numeric', 'min:0'],
            'earnings.*.taxable' => ['required', 'boolean'],
            'earnings.*.label' => ['nullable', 'string'],
        ]);

        $out = $this->taxComputation->classifyEarnings($validated['earnings']);

        return response()->json(['classification' => $out]);
    }

    /**
     * Year-end true-up: annual tax due vs withholding YTD.
     */
    public function yearEndAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'annual_taxable_income' => ['required', 'numeric', 'min:0'],
            'withholding_tax_ytd' => ['required', 'numeric', 'min:0'],
            'calendar_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'log_audit' => ['nullable', 'boolean'],
        ]);

        $result = $this->taxComputation->calculateYearEndAdjustment($validated);

        if (! empty($validated['log_audit'])) {
            $this->taxComputation->logCalculation(
                'year_end_adjustment',
                null,
                null,
                $validated,
                $result,
                $request->ip(),
                $request->user()?->id
            );
        }

        return response()->json(['year_end' => $result]);
    }

    /**
     * Retroactive recalculation guidance (full deltas require posted payroll history).
     */
    public function retroactiveTaxPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        if (isset($validated['employee_id'])) {
            $employee = User::query()->findOrFail((int) $validated['employee_id']);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        }

        $result = $this->taxComputation->recalculateRetroactiveTax(
            isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            $validated['from_date'],
            $validated['to_date']
        );

        return response()->json(['retroactive' => $result]);
    }

    /**
     * Active TRAIN / BIR table rows stored for audit (rates still computed in {@see PayrollCalculatorService}).
     */
    public function taxTables(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        if (! Schema::hasTable('tax_tables')) {
            return response()->json(['tax_tables' => []]);
        }

        $year = (int) ($validated['year'] ?? date('Y'));
        $rows = TaxTable::query()
            ->where('calendar_year', $year)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json([
            'tax_tables' => $rows->map(fn (TaxTable $t) => [
                'id' => $t->id,
                'calendar_year' => $t->calendar_year,
                'code' => $t->code,
                'label' => $t->label,
                'effective_from' => optional($t->effective_from)->toDateString(),
                'effective_to' => optional($t->effective_to)->toDateString(),
                'payload' => $t->payload,
                'source_reference' => $t->source_reference,
            ])->values(),
        ]);
    }

    public function showEmployeeTaxProfile(Request $request, int $userId): JsonResponse
    {
        $employee = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        if (! Schema::hasTable('employee_tax_info')) {
            return response()->json(['tax_profile' => null]);
        }

        $info = EmployeeTaxInfo::query()->where('user_id', $employee->id)->first();

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'tax_profile' => $info,
        ]);
    }

    public function upsertEmployeeTaxProfile(Request $request, int $userId): JsonResponse
    {
        $employee = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        if (! Schema::hasTable('employee_tax_info')) {
            return response()->json(['message' => 'Tax profile storage is not available.'], 503);
        }

        $validated = $request->validate([
            'withholding_method' => ['nullable', 'string', 'in:annualized,per_period_monthly'],
            'period_type' => ['nullable', 'string', 'in:monthly,semimonthly'],
            'tax_table_version' => ['nullable', 'string', 'max:32'],
            'dependents' => ['nullable', 'integer', 'min:0', 'max:99'],
            'is_mwe' => ['nullable', 'boolean'],
            'mwe_monthly_ceiling' => ['nullable', 'numeric', 'min:0'],
            'is_senior_citizen' => ['nullable', 'boolean'],
            'is_pwd' => ['nullable', 'boolean'],
            'is_solo_parent' => ['nullable', 'boolean'],
            'tax_regime' => ['nullable', 'string', 'max:32'],
            'additional_exemption_amount' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $row = EmployeeTaxInfo::query()->updateOrCreate(
            ['user_id' => $employee->id],
            array_merge(['user_id' => $employee->id], $validated)
        );

        return response()->json(['tax_profile' => $row]);
    }

    public function history(Request $request, int $userId): JsonResponse
    {
        $employee = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        $rows = EmployeeStatutoryContribution::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('type')
            ->get();

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'contributions' => $rows,
        ]);
    }

    /**
     * Hub dashboard: estimated org-wide statutory + WHT; pending remittance batch counts.
     */
    public function dashboardSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
        ]);

        $summary = $this->remittanceService->buildDashboardSummary(
            $request->user(),
            $validated['company_id'] ?? null
        );

        return response()->json($summary);
    }

    public function remittances(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agency' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'year' => ['nullable', 'integer'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'company_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->remittanceService->listRemittances($validated);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($row) => [
                'id' => $row->id,
                'company_id' => $row->company_id,
                'period_year' => $row->period_year,
                'period_month' => $row->period_month,
                'agency' => $row->agency,
                'report_kind' => $row->report_kind,
                'status' => $row->status,
                'file_name' => $row->file_name,
                'total_employee_amount' => $row->total_employee_amount,
                'total_employer_amount' => $row->total_employer_amount,
                'generated_by_user_id' => $row->generated_by_user_id,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function generateRemittance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agency' => ['required', 'string', 'in:SSS,PHILHEALTH,PAGIBIG,BIR'],
            'report_kind' => ['required', 'string', 'max:64'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $result = $this->remittanceService->generateRemittanceBatch(
            $request->user(),
            $validated['agency'],
            $validated['report_kind'],
            (int) $validated['period_year'],
            (int) $validated['period_month'],
            $validated['company_id'] ?? null
        );

        Log::info('statutory_remittance.generated', [
            'remittance_id' => $result['remittance']->id,
            'agency' => $validated['agency'],
            'report_kind' => $validated['report_kind'],
            'period' => $validated['period_year'].'-'.$validated['period_month'],
            'row_count' => count($result['rows']),
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'remittance' => [
                'id' => $result['remittance']->id,
                'agency' => $result['remittance']->agency,
                'report_kind' => $result['remittance']->report_kind,
                'status' => $result['remittance']->status, // pending until filed/paid in agency workflow
                'period_year' => $result['remittance']->period_year,
                'period_month' => $result['remittance']->period_month,
                'total_employee_amount' => $result['remittance']->total_employee_amount,
                'total_employer_amount' => $result['remittance']->total_employer_amount,
            ],
            'notes' => $result['notes'],
            'row_count' => count($result['rows']),
        ], 201);
    }
}
