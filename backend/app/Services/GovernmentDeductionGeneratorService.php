<?php

namespace App\Services;

use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeGovernmentId;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\StatutoryRemittance;
use App\Models\User;
use App\Support\BulkPayrollDraftContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-employee government + loan deduction roster for a company pay period (same engine as payroll).
 */
class GovernmentDeductionGeneratorService
{
    public function __construct(
        private readonly PayrollEmployeeEligibilityService $eligibility,
        private readonly PayrollComputationService $payrollComputation,
        private readonly PayrollCalculatorService $payrollCalculator,
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * @param  array{
     *   company_id: int,
     *   branch_id?: int|null,
     *   department_id?: int|null,
     *   from_date: string,
     *   to_date: string,
     *   pay_cycle_id?: int|null,
     *   reference_date?: string|null,
     *   search?: string|null,
     *   missing_only?: bool,
     *   page?: int,
     *   per_page?: int,
     *   return_all?: bool,
     * }  $input
     * @return array<string, mixed>
     */
    public function generate(User $actor, array $input): array
    {
        $companyId = (int) $input['company_id'];
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        $departmentId = isset($input['department_id']) ? (int) $input['department_id'] : null;
        $from = Carbon::parse((string) $input['from_date'])->startOfDay();
        $to = Carbon::parse((string) $input['to_date'])->startOfDay();
        $search = trim((string) ($input['search'] ?? ''));
        $missingOnly = (bool) ($input['missing_only'] ?? false);
        $returnAll = (bool) ($input['return_all'] ?? false);
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(500, max(5, (int) ($input['per_page'] ?? 25)));

        $employees = $this->loadEligibleEmployees(
            $companyId,
            $branchId,
            $departmentId,
            $from,
            $to,
            $search,
            $actor,
        );
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
        $payslipsByUserId = $this->loadPayslipSnapshotsForPeriod($companyId, $branchId, $departmentId, $from, $to, $employeeIds);
        $needsLiveCompute = $employees->filter(
            fn ($employee) => $employee instanceof User
                && ! isset($payslipsByUserId[(int) $employee->id])
        )->values();

        if ($needsLiveCompute->isNotEmpty()) {
            $liveIds = $needsLiveCompute->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->payrollComputation->beginBulkPayrollAttendancePrefetch($liveIds, $from, $to, $companyId);
        }

        BulkPayrollDraftContext::$active = true;
        $rows = [];
        $totals = [
            'sss' => 0.0,
            'philhealth' => 0.0,
            'pagibig' => 0.0,
            'withholding_tax' => 0.0,
            'custom_deductions' => 0.0,
            'employee_statutory' => 0.0,
        ];
        $missingCounts = [
            'sss_number' => 0,
            'philhealth_number' => 0,
            'pagibig_number' => 0,
            'tin_number' => 0,
            'basic_salary' => 0,
            'any' => 0,
        ];

        try {
            foreach ($needsLiveCompute as $employee) {
                if (! $employee instanceof User) {
                    continue;
                }
                $this->payrollCalculator->buildEmployeeCompensationSummary($employee, [
                    'as_of_date' => $to->toDateString(),
                    'include_deduction_schedule_catalog' => false,
                    'cache' => true,
                ]);
            }

            foreach ($employees as $employee) {
                if (! $employee instanceof User) {
                    continue;
                }

                $payslip = $payslipsByUserId[(int) $employee->id] ?? null;
                $row = $payslip instanceof Payslip
                    ? $this->buildEmployeeRowFromPayslipSnapshot($employee, $payslip, $from, $to, $companyId, $input)
                    : $this->buildEmployeeRow($employee, $from, $to, $companyId, $input);
                if ($missingOnly && ($row['missing_info'] ?? []) === []) {
                    continue;
                }

                $rows[] = $row;
                $totals['sss'] += (float) ($row['deductions']['sss'] ?? 0);
                $totals['philhealth'] += (float) ($row['deductions']['philhealth'] ?? 0);
                $totals['pagibig'] += (float) ($row['deductions']['pagibig'] ?? 0);
                $totals['withholding_tax'] += (float) ($row['deductions']['withholding_tax'] ?? 0);
                $totals['custom_deductions'] += (float) ($row['deductions']['custom_deductions'] ?? 0);
                $totals['employee_statutory'] += (float) ($row['deductions']['employee_statutory'] ?? 0);

                foreach ($row['missing_info'] ?? [] as $miss) {
                    $field = (string) ($miss['field'] ?? '');
                    if (array_key_exists($field, $missingCounts)) {
                        $missingCounts[$field]++;
                    }
                }
                if (($row['missing_info'] ?? []) !== []) {
                    $missingCounts['any']++;
                }
            }
        } finally {
            BulkPayrollDraftContext::$active = false;
            if ($needsLiveCompute->isNotEmpty()) {
                $this->payrollComputation->endBulkPayrollAttendancePrefetch();
            }
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($returnAll ? 0 : ($page - 1) * $perPage);
        $sliceLength = $returnAll ? $total : $perPage;
        $pageRows = array_values(array_slice($rows, $offset, $sliceLength));

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }

        return [
            'scope' => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'pay_cycle_id' => $input['pay_cycle_id'] ?? null,
                'reference_date' => $input['reference_date'] ?? null,
            ],
            'summary' => [
                'employee_count' => $total,
                'employees_with_missing_info' => $missingCounts['any'],
                'missing_counts' => $missingCounts,
                'totals' => $totals,
            ],
            'data' => $pageRows,
            'meta' => [
                'total' => $total,
                'page' => $returnAll ? 1 : $page,
                'per_page' => $returnAll ? $total : $perPage,
                'last_page' => $returnAll ? 1 : $lastPage,
                'returned_all' => $returnAll,
                'from_payslip_snapshots' => count($payslipsByUserId),
                'live_computed' => $needsLiveCompute->count(),
            ],
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, Payslip>
     */
    private function loadPayslipSnapshotsForPeriod(
        int $companyId,
        ?int $branchId,
        ?int $departmentId,
        Carbon $from,
        Carbon $to,
        array $employeeIds,
    ): array {
        if ($employeeIds === [] || ! Schema::hasTable('payslips')) {
            return [];
        }

        $query = Payslip::query()
            ->select(['id', 'user_id', 'snapshot', 'status'])
            ->whereIn('user_id', $employeeIds)
            ->whereDate('pay_period_start', $from->toDateString())
            ->whereDate('pay_period_end', $to->toDateString())
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->whereNotNull('snapshot')
            ->orderByDesc('id');

        if ($companyId > 0) {
            $query->where(function ($q) use ($companyId): void {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        }

        $out = [];
        foreach ($query->get() as $payslip) {
            if (! $payslip instanceof Payslip) {
                continue;
            }
            $uid = (int) $payslip->user_id;
            if ($uid <= 0 || isset($out[$uid])) {
                continue;
            }
            $snapshot = is_array($payslip->snapshot) ? $payslip->snapshot : [];
            if (! is_array($snapshot['summary'] ?? null)) {
                continue;
            }
            $out[$uid] = $payslip;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function buildEmployeeRowFromPayslipSnapshot(
        User $employee,
        Payslip $payslip,
        Carbon $from,
        Carbon $to,
        int $companyId,
        array $input,
    ): array {
        $snapshot = is_array($payslip->snapshot) ? $payslip->snapshot : [];
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $computed = [
            'summary' => $summary,
            'basic_salary_used' => (float) ($snapshot['basic_salary_used'] ?? $summary['basic_pay_this_period'] ?? 0),
        ];

        return $this->assembleEmployeeRow($employee, $summary, $computed);
    }

    /**
     * Persist a full employee deduction roster snapshot (review / audit / re-open later).
     *
     * @param  array<string, mixed>  $input
     * @return array{remittance: array<string, mixed>, row_count: int}
     */
    public function save(User $actor, array $input): array
    {
        if (! Schema::hasTable('statutory_remittances')) {
            throw new \RuntimeException('Statutory remittances table is not available.');
        }

        $payload = null;
        if (is_array($input['payload'] ?? null) && ($input['payload']['data'] ?? null) !== null) {
            $payload = $this->normalizeSavedPayload($input['payload'], $input);
        }
        if ($payload === null) {
            $payload = $this->generate($actor, array_merge($input, [
                'return_all' => true,
                'page' => 1,
                'missing_only' => false,
            ]));
        }

        $from = Carbon::parse((string) ($payload['scope']['from_date'] ?? now()->toDateString()));
        $totals = is_array($payload['summary']['totals'] ?? null) ? $payload['summary']['totals'] : [];
        $employeeTotal = round(
            (float) ($totals['employee_statutory'] ?? 0)
            + (float) ($totals['withholding_tax'] ?? 0)
            + (float) ($totals['custom_deductions'] ?? 0),
            2
        );

        $remittance = StatutoryRemittance::query()->create([
            'company_id' => (int) ($payload['scope']['company_id'] ?? 0),
            'period_year' => (int) $from->format('Y'),
            'period_month' => (int) $from->format('n'),
            'agency' => 'DEDUCTION_ROSTER',
            'report_kind' => 'employee_payroll_deductions',
            'status' => 'generated',
            'payload' => $payload,
            'total_employee_amount' => $employeeTotal,
            'total_employer_amount' => 0,
            'generated_by_user_id' => $actor->id,
        ]);

        return [
            'remittance' => [
                'id' => $remittance->id,
                'agency' => $remittance->agency,
                'report_kind' => $remittance->report_kind,
                'status' => $remittance->status,
                'period_year' => $remittance->period_year,
                'period_month' => $remittance->period_month,
                'total_employee_amount' => $remittance->total_employee_amount,
                'created_at' => optional($remittance->created_at)->toIso8601String(),
            ],
            'row_count' => count($payload['data'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(User $employee, Carbon $from, Carbon $to, int $companyId, array $input): array
    {
        $periodContext = [
            'company_id' => $companyId,
            'pay_period_start' => $from->toDateString(),
            'pay_period_end' => $to->toDateString(),
        ];
        if (! empty($input['pay_cycle_id'])) {
            $periodContext['pay_cycle_id'] = (int) $input['pay_cycle_id'];
        }
        if (! empty($input['reference_date'])) {
            $periodContext['selected_pay_date'] = (string) $input['reference_date'];
        }
        $periodContext['skip_standing_ot_sync'] = true;
        $periodContext['deduction_roster_mode'] = true;

        $computed = $this->payrollComputation->computeEmployeePayroll($employee, $from, $to, null, $periodContext);
        $summary = is_array($computed['summary'] ?? null) ? $computed['summary'] : [];

        return $this->assembleEmployeeRow($employee, $summary, $computed);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $computed
     * @return array<string, mixed>
     */
    private function assembleEmployeeRow(User $employee, array $summary, array $computed): array
    {
        $deductionSchedule = is_array($summary['deduction_schedule'] ?? null) ? $summary['deduction_schedule'] : [];
        $gov = is_array($deductionSchedule['government'] ?? null) ? $deductionSchedule['government'] : [];
        $statutory = is_array($summary['statutory_breakdown'] ?? null) ? $summary['statutory_breakdown'] : [];

        $sss = $this->govLineAmount($gov, DeductionScheduleSetting::GOV_SSS);
        $philhealth = $this->govLineAmount($gov, DeductionScheduleSetting::GOV_PHILHEALTH);
        $pagibig = $this->govLineAmount($gov, DeductionScheduleSetting::GOV_PAGIBIG);
        $withholding = (float) ($summary['withholding_tax_this_period_estimate'] ?? 0);
        $customDeductions = (float) ($summary['custom_deductions_this_period'] ?? 0);
        $employeeStatutory = (float) ($summary['employee_statutory_this_period'] ?? ($sss + $philhealth + $pagibig));

        $govIds = $employee->governmentIds;
        $governmentIds = $this->formatGovernmentIds($govIds);
        $missingInfo = $this->assessMissingInfo($employee, $computed, $govIds, $withholding);

        $exemption = is_array($summary['government_deduction_exemption'] ?? null)
            ? $summary['government_deduction_exemption']
            : [];

        $customScheduleLines = is_array($deductionSchedule['custom_lines'] ?? null)
            ? $deductionSchedule['custom_lines']
            : [];
        $payslipCustomLines = is_array($summary['payslip_custom_deduction_lines'] ?? null)
            ? $summary['payslip_custom_deduction_lines']
            : [];
        $loanLines = $this->normalizeLoanLines($customScheduleLines, $payslipCustomLines);

        return [
            'user_id' => (int) $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->display_name ?? $employee->name,
            'profile_image' => $employee->profile_image,
            'profile_image_url' => $employee->profile_image_url,
            'department' => $employee->departmentRelation?->name ?? $employee->department,
            'company' => $employee->company?->name,
            'basic_salary_used' => round((float) ($computed['basic_salary_used'] ?? 0), 2),
            'gross_pay_this_period' => round((float) ($summary['gross_pay_this_period'] ?? 0), 2),
            'government_ids' => $governmentIds,
            'deductions' => [
                'sss' => round($sss, 2),
                'philhealth' => round($philhealth, 2),
                'pagibig' => round($pagibig, 2),
                'withholding_tax' => round($withholding, 2),
                'custom_deductions' => round($customDeductions, 2),
                'employee_statutory' => round($employeeStatutory, 2),
            ],
            'deduction_lines' => [
                'government' => $gov['lines'] ?? [],
                'custom' => $payslipCustomLines,
            ],
            'loan_lines' => $loanLines,
            'statutory_monthly' => [
                'sss' => round((float) data_get($statutory, 'sss.employee_amount', 0), 2),
                'philhealth' => round((float) data_get($statutory, 'philhealth.employee_amount', 0), 2),
                'pagibig' => round((float) data_get($statutory, 'pagibig.employee_amount', 0), 2),
                'withholding_tax' => round((float) ($summary['withholding_tax_monthly_estimate'] ?? 0), 2),
            ],
            'exemptions' => $exemption,
            'missing_info' => $missingInfo,
            'has_missing_info' => $missingInfo !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $gov
     */
    private function govLineAmount(array $gov, string $key): float
    {
        foreach ($gov['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            if ((string) ($line['key'] ?? '') === $key) {
                return (float) ($line['this_period_employee'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * @return array<string, string|null>
     */
    private function formatGovernmentIds(?EmployeeGovernmentId $govIds): array
    {
        return [
            'sss_number' => $this->presentId($govIds?->sss_number),
            'philhealth_number' => $this->presentId($govIds?->philhealth_number),
            'pagibig_number' => $this->presentId($govIds?->pagibig_number),
            'tin_number' => $this->presentId($govIds?->tin_number),
        ];
    }

    private function presentId(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $computed
     * @return list<array{field: string, label: string, severity: string}>
     */
    private function assessMissingInfo(User $employee, array $computed, ?EmployeeGovernmentId $govIds, float $withholdingThisPeriod): array
    {
        $missing = [];
        $exemption = is_array(data_get($computed, 'summary.government_deduction_exemption'))
            ? data_get($computed, 'summary.government_deduction_exemption')
            : [];

        $requiresSss = ! (bool) data_get($exemption, 'exempt_sss', false);
        $requiresPh = ! (bool) data_get($exemption, 'exempt_philhealth', false);
        $requiresPag = ! (bool) data_get($exemption, 'exempt_pagibig', false);
        $requiresTin = ! (bool) data_get($exemption, 'exempt_withholding_tax', false) && $withholdingThisPeriod > 0.0001;

        if ($requiresSss && $this->presentId($govIds?->sss_number) === null) {
            $missing[] = ['field' => 'sss_number', 'label' => 'SSS number', 'severity' => 'error'];
        }
        if ($requiresPh && $this->presentId($govIds?->philhealth_number) === null) {
            $missing[] = ['field' => 'philhealth_number', 'label' => 'PhilHealth number', 'severity' => 'error'];
        }
        if ($requiresPag && $this->presentId($govIds?->pagibig_number) === null) {
            $missing[] = ['field' => 'pagibig_number', 'label' => 'Pag-IBIG number', 'severity' => 'error'];
        }
        if ($requiresTin && $this->presentId($govIds?->tin_number) === null) {
            $missing[] = ['field' => 'tin_number', 'label' => 'TIN', 'severity' => 'error'];
        }

        $basicSalary = (float) ($computed['basic_salary_used'] ?? 0);
        if ($basicSalary <= 0.0001) {
            $missing[] = ['field' => 'basic_salary', 'label' => 'Basic salary / compensation', 'severity' => 'warning'];
        }

        if ($employee->employee_code === null || trim((string) $employee->employee_code) === '') {
            $missing[] = ['field' => 'employee_code', 'label' => 'Employee number', 'severity' => 'warning'];
        }

        return $missing;
    }

    /**
     * @param  list<array<string, mixed>>  $customScheduleLines
     * @param  list<array<string, mixed>>  $payslipLines
     * @return list<array<string, mixed>>
     */
    private function normalizeLoanLines(array $customScheduleLines, array $payslipLines): array
    {
        $lines = $payslipLines !== [] ? $payslipLines : [];
        if ($lines === [] && $customScheduleLines !== []) {
            foreach ($customScheduleLines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $amount = (float) ($line['scheduled_this_period'] ?? $line['applied_this_period'] ?? 0);
                if (abs($amount) < 0.005) {
                    continue;
                }
                $name = trim((string) ($line['name'] ?? $line['code'] ?? 'Deduction'));
                $lines[] = [
                    'key' => 'deduction:'.md5($name),
                    'label' => $name,
                    'component_code' => (string) ($line['code'] ?? ''),
                    'amount' => round($amount, 2),
                    'configured_amount' => round((float) ($line['original_amount'] ?? $amount), 2),
                    'deduction_schedule_type' => $line['deduction_schedule_type'] ?? null,
                    'pay_component_id' => $line['pay_component_id'] ?? $line['id'] ?? null,
                    'employee_deduction_id' => $line['employee_deduction_id'] ?? null,
                    'metadata' => is_array($line['metadata'] ?? null) ? $line['metadata'] : [],
                ];
            }
        }

        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $amount = (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0);
            if (abs($amount) < 0.005) {
                continue;
            }
            $name = trim((string) ($line['label'] ?? $line['name'] ?? $line['code'] ?? 'Deduction'));
            $meta = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
            $remaining = $meta['remaining_balance'] ?? $meta['balance'] ?? $line['remaining_balance'] ?? null;
            $out[] = [
                'pay_component_id' => isset($line['pay_component_id']) ? (int) $line['pay_component_id'] : null,
                'employee_deduction_id' => isset($line['employee_deduction_id']) ? (int) $line['employee_deduction_id'] : null,
                'code' => (string) ($line['component_code'] ?? $line['code'] ?? ''),
                'name' => $name,
                'category' => $this->inferLoanCategory($name, (string) ($line['component_code'] ?? $line['code'] ?? ''), $meta),
                'amount_this_period' => round($amount, 2),
                'configured_amount' => round((float) ($line['configured_amount'] ?? $line['component_amount'] ?? $amount), 2),
                'remaining_balance' => $remaining !== null ? round((float) $remaining, 2) : null,
                'schedule_type' => $line['deduction_schedule_type'] ?? $line['resolved_schedule'] ?? null,
                'display' => $line['display'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function inferLoanCategory(string $name, string $code, array $meta): string
    {
        $category = strtolower(trim((string) ($meta['category'] ?? $meta['deduction_type'] ?? '')));
        if ($category !== '') {
            return Str::title(str_replace('_', ' ', $category));
        }

        $haystack = strtolower($name.' '.$code);
        if (str_contains($haystack, 'loan')) {
            return 'Loan';
        }
        if (str_contains($haystack, 'cash advance') || str_contains($haystack, 'advance')) {
            return 'Cash Advance';
        }
        if (str_contains($haystack, 'garnish')) {
            return 'Garnishment';
        }

        return 'Deduction';
    }

    /**
     * Accept a client-generated roster snapshot when scope matches (avoids re-running payroll on save).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function normalizeSavedPayload(array $payload, array $input): ?array
    {
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $expected = [
            'company_id' => (int) ($input['company_id'] ?? 0),
            'branch_id' => isset($input['branch_id']) ? (int) $input['branch_id'] : null,
            'department_id' => isset($input['department_id']) ? (int) $input['department_id'] : null,
            'from_date' => Carbon::parse((string) ($input['from_date'] ?? ''))->toDateString(),
            'to_date' => Carbon::parse((string) ($input['to_date'] ?? ''))->toDateString(),
        ];

        foreach (['company_id', 'from_date', 'to_date'] as $key) {
            if ((string) ($scope[$key] ?? '') !== (string) $expected[$key]) {
                return null;
            }
        }
        foreach (['branch_id', 'department_id'] as $key) {
            $a = $scope[$key] ?? null;
            $b = $expected[$key];
            if ($a !== null && $b !== null && (int) $a !== (int) $b) {
                return null;
            }
        }

        if (! is_array($payload['data'] ?? null) || $payload['data'] === []) {
            return null;
        }

        return $payload;
    }

    /**
     * Regular + consultant payroll employees for gov deduction roster (standard query excludes consultants).
     *
     * @return Collection<int, User>
     */
    private function loadEligibleEmployees(
        int $companyId,
        ?int $branchId,
        ?int $departmentId,
        Carbon $from,
        Carbon $to,
        string $search,
        User $actor,
    ): Collection {
        $scopeBranchId = $branchId > 0 ? $branchId : null;
        $scopeDepartmentId = $departmentId > 0 ? $departmentId : null;

        $eligibleIds = collect([
            $this->eligibility->query(
                $companyId,
                $scopeBranchId,
                $scopeDepartmentId,
                $from,
                $to,
                $actor,
                $this->dataScopeService,
                PayrollBatchRun::MODULE_STANDARD,
            )->pluck('users.id'),
            $this->eligibility->query(
                $companyId,
                $scopeBranchId,
                $scopeDepartmentId,
                $from,
                $to,
                $actor,
                $this->dataScopeService,
                PayrollBatchRun::MODULE_CONSULTANT,
            )->pluck('users.id'),
        ])
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($eligibleIds === []) {
            return collect();
        }

        $query = User::query()
            ->whereIn('id', $eligibleIds)
            ->with(['governmentIds', 'company', 'departmentRelation', 'branch']);

        if ($search !== '') {
            $needle = '%'.Str::lower($search).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(COALESCE(users.name, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(users.employee_code, \'\')) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(users.email, \'\')) LIKE ?', [$needle]);
            });
        }

        return $query->orderByLastName()->get();
    }
}
