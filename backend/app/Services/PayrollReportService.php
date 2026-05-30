<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PayrollReportService
{
    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    /**
     * Resolve which company a payroll report belongs to when the batch run has no company_id
     * (e.g. EXECOM generated for all companies).
     */
    public function resolveReportCompany(PayrollBatchRun $run, ?int $requestedCompanyId = null): Company
    {
        if ($requestedCompanyId !== null) {
            $company = Company::query()->find($requestedCompanyId);
            if (! $company) {
                throw new \RuntimeException('Company not found.');
            }
            if ($run->company_id !== null && (int) $run->company_id !== (int) $company->id) {
                throw new \RuntimeException('Company does not match this payroll batch.');
            }

            return $company;
        }

        if ($run->company_id !== null) {
            return Company::query()->findOrFail((int) $run->company_id);
        }

        $companyIds = $this->distinctPayslipCompanyIdsForRun($run);
        if ($companyIds->count() === 1) {
            $companyId = (int) $companyIds->first();
            PayrollBatchRun::query()
                ->whereKey($run->id)
                ->whereNull('company_id')
                ->update(['company_id' => $companyId]);

            return Company::query()->findOrFail($companyId);
        }

        if ($companyIds->count() > 1) {
            throw new \RuntimeException(
                'This EXECOM batch spans multiple companies. Pass company_id when downloading the report, or regenerate the batch with a company filter.'
            );
        }

        throw new \RuntimeException('Company not found for this EXECOM batch.');
    }

    /**
     * @return Collection<int, int>
     */
    private function distinctPayslipCompanyIdsForRun(PayrollBatchRun $run): Collection
    {
        $query = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->whereNull('voided_at')
            ->whereNotNull('company_id')
            ->whereIn('status', Payslip::lockingStatuses());

        $module = trim((string) ($run->payroll_module ?? ''));
        if ($module !== '') {
            $query->where('payroll_module', $module);
        }

        return $query->distinct()->pluck('company_id')->map(fn ($id): int => (int) $id)->values();
    }

    /**
     * @return array{pdf:\Barryvdh\DomPDF\PDF, filename:string, employee_count:int}
     */
    public function pdfForRunCompany(PayrollBatchRun $run, Company $company, User $actor): array
    {
        $payload = $this->buildReportPayload($run, $company, $actor);
        $pdf = Pdf::loadView('reports.payroll_report_pdf', $payload)
            ->setPaper($payload['layout']['paper_size'], $payload['layout']['orientation']);

        return [
            'pdf' => $pdf,
            'filename' => $this->filename($company, $run),
            'employee_count' => count($payload['rows']),
        ];
    }

    /**
     * @return array{pdf:\Barryvdh\DomPDF\PDF, filename:string, employee_count:int}
     */
    public function pdfForRun(PayrollBatchRun $run, User $actor): array
    {
        $payload = $this->buildReportPayloadForRun($run, $actor);
        $pdf = Pdf::loadView('reports.payroll_report_pdf', $payload)
            ->setPaper($payload['layout']['paper_size'], $payload['layout']['orientation']);

        return [
            'pdf' => $pdf,
            'filename' => $this->runFilename($run),
            'employee_count' => count($payload['rows']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReportPayload(PayrollBatchRun $run, Company $company, User $actor): array
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Payroll Report PDF is only available for finalized payroll runs.');
        }

        $payslips = $this->finalizedPayslipsForRunCompany($run, $company);
        if ($payslips->isEmpty()) {
            throw new \RuntimeException('No finalized payslips were found for this company and payroll run.');
        }

        return $this->buildReportPayloadFromPayslips($run, $company, $actor, $payslips);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReportPayloadForRun(PayrollBatchRun $run, User $actor): array
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Payroll Report PDF is only available for finalized payroll runs.');
        }

        $payslips = $this->finalizedPayslipsForRun($run);
        if ($payslips->isEmpty()) {
            throw new \RuntimeException('No finalized payslips were found for this payroll run.');
        }

        return $this->buildReportPayloadFromPayslips($run, null, $actor, $payslips);
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @return array<string, mixed>
     */
    private function buildReportPayloadFromPayslips(PayrollBatchRun $run, ?Company $company, User $actor, Collection $payslips): array
    {
        $rows = $payslips
            ->map(fn (Payslip $payslip): array => $this->rowForPayslip($payslip))
            ->sortBy('employee_sort_key')
            ->values()
            ->map(function (array $row, int $index): array {
                $row['row_number'] = $index + 1;

                return $row;
            })
            ->all();

        $dynamicColumns = [
            'holiday_pay' => collect($rows)->sum('holiday_pay') > 0.004,
            'overtime_pay' => collect($rows)->sum('overtime_pay') > 0.004,
            'night_differential' => collect($rows)->sum('night_differential') > 0.004,
            'paid_leave' => collect($rows)->sum('paid_leave') > 0.004,
            'other_earnings' => collect($rows)->sum('other_earnings') > 0.004,
        ];

        $totals = [];
        foreach ([
            'regular_basic_pay',
            'holiday_pay',
            'overtime_pay',
            'night_differential',
            'paid_leave',
            'other_earnings',
            'allowance',
            'sss',
            'philhealth',
            'pagibig',
            'withholding_tax',
            'other_deductions',
            'gross_earnings',
            'total_deductions',
            'net_pay',
        ] as $key) {
            $totals[$key] = round(collect($rows)->sum($key), 2);
        }
        $columns = $this->reportColumns($dynamicColumns);
        $layout = $this->layoutForColumnCount(count($columns));

        $isExecom = strtolower(trim((string) ($run->payroll_module ?? ''))) === PayrollBatchRun::MODULE_EXECOM;

        return [
            'company' => $company,
            'reportCompanyName' => $isExecom ? 'Execom' : ($company?->name ?? 'Company'),
            'reportCompanyAddress' => $isExecom ? null : $company?->address,
            'isExecomPayroll' => $isExecom,
            'run' => $run,
            'rows' => $rows,
            'columns' => $columns,
            'dynamicColumns' => $dynamicColumns,
            'layout' => $layout,
            'totals' => $totals,
            'reportPayDate' => $payslips->first()?->pay_date ?? $run->reference_date,
            'generatedAt' => now(),
            'generatedBy' => $actor->name ?? $actor->email ?? 'System',
            'logoLocalPath' => $isExecom || ! $company ? null : $this->logoLocalPath($company),
        ];
    }

    /**
     * @param  array<string, bool>  $dynamicColumns
     * @return list<array{key:string,label:string,group:string,class:string}>
     */
    private function reportColumns(array $dynamicColumns): array
    {
        $columns = [
            ['key' => 'row_number', 'label' => 'No.', 'group' => '#', 'class' => 'num row-number'],
            ['key' => 'employee_name', 'label' => 'Employee', 'group' => 'Employee', 'class' => 'employee'],
            ['key' => 'regular_basic_pay', 'label' => 'Basic Pay', 'group' => 'Earnings', 'class' => 'num'],
        ];

        foreach ([
            'holiday_pay' => 'Holiday',
            'overtime_pay' => 'OT',
            'night_differential' => 'Night Diff',
            'paid_leave' => 'Leave',
            'other_earnings' => 'Other Earn',
        ] as $key => $label) {
            if ($dynamicColumns[$key] ?? false) {
                $columns[] = ['key' => $key, 'label' => $label, 'group' => 'Earnings', 'class' => 'num'];
            }
        }

        return array_merge($columns, [
            ['key' => 'allowance', 'label' => 'Allowance', 'group' => 'Allowance', 'class' => 'num'],
            ['key' => 'sss', 'label' => 'SSS', 'group' => 'Govt. Deductions', 'class' => 'num'],
            ['key' => 'philhealth', 'label' => 'PHIC', 'group' => 'Govt. Deductions', 'class' => 'num'],
            ['key' => 'pagibig', 'label' => 'HDMF', 'group' => 'Govt. Deductions', 'class' => 'num'],
            ['key' => 'withholding_tax', 'label' => 'WHT', 'group' => 'Govt. Deductions', 'class' => 'num'],
            ['key' => 'other_deductions', 'label' => 'Other Ded.', 'group' => 'Other Ded.', 'class' => 'num deductions'],
            ['key' => 'gross_earnings', 'label' => 'Gross', 'group' => 'Totals', 'class' => 'num gross'],
            ['key' => 'total_deductions', 'label' => 'Deduct.', 'group' => 'Totals', 'class' => 'num deductions'],
            ['key' => 'net_pay', 'label' => 'Net', 'group' => 'Totals', 'class' => 'num net'],
        ]);
    }

    /**
     * @return array{paper_size:string,orientation:string,body_font:string,header_font:string,cell_padding:string,row_number_width:float,employee_width:float,numeric_width:float,content_width:string,table_width:string}
     */
    private function layoutForColumnCount(int $columnCount): array
    {
        // Landscape gives payroll reports enough room for readable totals without
        // pushing the table all the way to the paper edge.
        $rowNumberWidth = 3.8;
        $employeeWidth = match (true) {
            $columnCount >= 21 => 22.0,
            $columnCount >= 17 => 24.0,
            $columnCount >= 13 => 27.0,
            $columnCount >= 10 => 30.0,
            default => 36.0,
        };
        $numericWidth = round((100.0 - $rowNumberWidth - $employeeWidth) / max(1, $columnCount - 2), 4);

        return [
            'paper_size' => 'a4',
            'orientation' => 'landscape',
            'body_font' => match (true) {
                $columnCount >= 21 => '7.2px',
                $columnCount >= 17 => '7.8px',
                $columnCount >= 13 => '8.5px',
                $columnCount >= 10 => '9.2px',
                default => '10.2px',
            },
            'header_font' => match (true) {
                $columnCount >= 21 => '6.0px',
                $columnCount >= 17 => '6.5px',
                $columnCount >= 13 => '7.1px',
                $columnCount >= 10 => '7.7px',
                default => '8.6px',
            },
            'cell_padding' => match (true) {
                $columnCount >= 21 => '1.1px 1.35px',
                $columnCount >= 17 => '1.25px 1.5px',
                $columnCount >= 13 => '1.45px 1.75px',
                $columnCount >= 10 => '1.7px 2px',
                default => '2.4px 2.6px',
            },
            'row_number_width' => $rowNumberWidth,
            'employee_width' => $employeeWidth,
            'numeric_width' => $numericWidth,
            'content_width' => '98.5%',
            'table_width' => '99%',
        ];
    }

    /**
     * Finalized payslips for a payroll report. When scoped to a batch run, the batch id is the
     * source of truth so legacy rows with a clamped computation start date still appear.
     *
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForBatchRun(PayrollBatchRun $run, ?int $companyId = null): Collection
    {
        $query = Payslip::query()
            ->with(['employee:id,name,first_name,middle_name,last_name,suffix,employee_code'])
            ->where('payroll_batch_run_id', (int) $run->id)
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereNotNull('snapshot')
            ->orderBy('user_id')
            ->orderByDesc('id');

        $module = trim((string) ($run->payroll_module ?? ''));
        if ($module !== '') {
            $query->where('payroll_module', $module);
        }
        if ($companyId !== null && $companyId > 0) {
            $query->where('company_id', $companyId);
        }

        return $query->get()->unique('user_id')->values();
    }

    /**
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForRunCompany(PayrollBatchRun $run, Company $company): Collection
    {
        return $this->finalizedPayslipsForBatchRun($run, (int) $company->id);
    }

    /**
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForRun(PayrollBatchRun $run): Collection
    {
        return $this->finalizedPayslipsForBatchRun($run);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForPayslip(Payslip $payslip): array
    {
        $employee = $payslip->employee;
        $metrics = $this->payslipService->frozenPayslipLineMetrics($payslip);
        $snapshot = is_array($payslip->snapshot)
            ? $payslip->snapshot
            : (is_string($payslip->snapshot) ? json_decode($payslip->snapshot, true) : []);
        $viewSnapshot = is_array($snapshot) && $snapshot !== []
            ? $this->payslipService->frozenSnapshotForPayslipView($snapshot)
            : [];
        $summary = is_array($viewSnapshot['summary'] ?? null)
            ? $viewSnapshot['summary']
            : (is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : []);
        $earningLines = array_values(array_merge(
            $this->lineList($summary['daily_computation_earning_lines'] ?? []),
            $this->lineList($summary['payslip_earning_lines'] ?? [])
        ));
        $deductionLines = array_values(array_merge(
            $this->lineList($summary['payslip_deduction_lines'] ?? []),
            $this->lineList($summary['payslip_custom_deduction_lines'] ?? [])
        ));
        $categoryTotals = is_array($metrics['category_totals'] ?? null) ? $metrics['category_totals'] : [];

        $earnings = [
            'regular_basic_pay' => 0.0,
            'holiday_pay' => 0.0,
            'overtime_pay' => 0.0,
            'night_differential' => 0.0,
            'paid_leave' => 0.0,
            'other_earnings' => 0.0,
            'allowance' => 0.0,
        ];
        foreach ($earningLines as $line) {
            $amount = $this->lineAmount($line);
            if ($amount <= 0.0) {
                continue;
            }
            $earnings[$this->earningBucket($line)] += $amount;
        }
        $earnings['regular_basic_pay'] = $this->metricAmount($metrics, $categoryTotals, 'regular_pay', $earnings['regular_basic_pay']);
        $earnings['holiday_pay'] = $this->metricAmount($metrics, $categoryTotals, 'holiday_pay', $earnings['holiday_pay']);
        $earnings['overtime_pay'] = $this->metricAmount($metrics, $categoryTotals, 'overtime_pay', $earnings['overtime_pay']);
        $earnings['night_differential'] = $this->metricAmount($metrics, $categoryTotals, 'night_differential', $earnings['night_differential']);
        $earnings['paid_leave'] = $this->metricAmount($metrics, $categoryTotals, 'paid_leave', $earnings['paid_leave']);
        $earnings['allowance'] = $this->metricAmount($metrics, $categoryTotals, 'allowances', $earnings['allowance'], 'allowance');
        $earnings['other_earnings'] = $this->metricAmount($metrics, $categoryTotals, 'other_earning', $earnings['other_earnings']);

        $deductions = [
            'sss' => 0.0,
            'philhealth' => 0.0,
            'pagibig' => 0.0,
            'withholding_tax' => 0.0,
            'other_deductions' => 0.0,
        ];
        foreach ($deductionLines as $line) {
            $amount = $this->lineAmount($line);
            if ($amount <= 0.0) {
                continue;
            }
            $deductions[$this->deductionBucket($line)] += $amount;
        }

        $name = $employee instanceof User
            ? $employee->display_name
            : trim((string) data_get($snapshot, 'employee.name', 'Employee '.$payslip->user_id));

        return array_merge($earnings, $deductions, [
            'employee_name' => $name !== '' ? $name : 'Employee '.$payslip->user_id,
            'employee_sort_key' => $employee instanceof User ? $employee->employeeListingSortKey() : mb_strtolower($name),
            'gross_earnings' => round((float) $metrics['gross_pay'], 2),
            'total_deductions' => round((float) $metrics['total_deductions'], 2),
            'net_pay' => round((float) $metrics['net_pay'], 2),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($line): bool => is_array($line)));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineAmount(array $line): float
    {
        foreach (['amount', 'resolved_amount', 'total_amount', 'value'] as $field) {
            if (array_key_exists($field, $line) && is_numeric($line[$field])) {
                return round(max(0.0, (float) $line[$field]), 2);
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $categoryTotals
     */
    private function metricAmount(array $metrics, array $categoryTotals, string $metricKey, float $fallback, ?string $categoryKey = null): float
    {
        if (array_key_exists($metricKey, $metrics) && is_numeric($metrics[$metricKey])) {
            $amount = round(max(0.0, (float) $metrics[$metricKey]), 2);
            if ($amount > 0.004) {
                return $amount;
            }
        }

        $categoryKey ??= $metricKey;
        if (array_key_exists($categoryKey, $categoryTotals) && is_numeric($categoryTotals[$categoryKey])) {
            $amount = round(max(0.0, (float) $categoryTotals[$categoryKey]), 2);
            if ($amount > 0.004) {
                return $amount;
            }
        }

        return round($fallback, 2);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function earningBucket(array $line): string
    {
        $text = $this->lineSearchText($line);
        if (str_contains($text, 'allowance')) {
            return 'allowance';
        }
        if (str_contains($text, 'holiday')) {
            return 'holiday_pay';
        }
        if (str_contains($text, 'overtime') || str_contains($text, 'ot_pay') || preg_match('/\bot\b/', $text)) {
            return 'overtime_pay';
        }
        if (str_contains($text, 'night') || str_contains($text, 'nd_pay') || str_contains($text, 'night_diff')) {
            return 'night_differential';
        }
        if (str_contains($text, 'paid_leave') || str_contains($text, 'paid leave') || str_contains($text, 'leave adjustment') || str_contains($text, 'leave adjustments')) {
            return 'paid_leave';
        }
        if (str_contains($text, 'regular') || str_contains($text, 'basic')) {
            return 'regular_basic_pay';
        }

        return 'other_earnings';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function deductionBucket(array $line): string
    {
        $text = $this->lineSearchText($line);
        if (str_contains($text, 'philhealth') || str_contains($text, 'phil health')) {
            return 'philhealth';
        }
        if (str_contains($text, 'pag-ibig') || str_contains($text, 'pagibig')) {
            return 'pagibig';
        }
        if (str_contains($text, 'withholding') || str_contains($text, 'wht')) {
            return 'withholding_tax';
        }
        if (preg_match('/\bsss\b/', $text)) {
            return 'sss';
        }

        return 'other_deductions';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineSearchText(array $line): string
    {
        return strtolower(trim(implode(' ', array_filter([
            (string) ($line['category'] ?? ''),
            (string) ($line['component'] ?? ''),
            (string) ($line['component_code'] ?? ''),
            (string) ($line['code'] ?? ''),
            (string) ($line['key'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
            (string) ($line['component_name'] ?? ''),
            (string) ($line['description'] ?? ''),
            (string) ($line['source_type'] ?? ''),
            (string) ($line['calculation_standard'] ?? ''),
            (string) ($line['type'] ?? ''),
        ]))));
    }

    private function logoLocalPath(Company $company): ?string
    {
        $logoRaw = is_string($company->logo ?? null) ? trim((string) $company->logo) : '';
        if ($logoRaw === '') {
            return null;
        }

        $normalized = ltrim($logoRaw, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        $candidate = storage_path('app/public/'.$normalized);
        if (! is_file($candidate)) {
            Log::info('Payroll Report logo file missing', [
                'company_id' => (int) $company->id,
                'logo' => $logoRaw,
            ]);

            return null;
        }

        return $candidate;
    }

    private function filename(Company $company, PayrollBatchRun $run): string
    {
        $companyName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $company->name) ?: 'Company';
        $start = $run->pay_period_start?->format('Ymd') ?? 'period';
        $end = $run->pay_period_end?->format('Ymd') ?? 'end';

        return "Payroll_Report_{$companyName}_{$start}_{$end}_Run_{$run->id}.pdf";
    }

    private function runFilename(PayrollBatchRun $run): string
    {
        $module = strtolower(trim((string) ($run->payroll_module ?? ''))) === PayrollBatchRun::MODULE_EXECOM
            ? 'EXECOM'
            : 'Payroll';
        $start = $run->pay_period_start?->format('Ymd') ?? 'period';
        $end = $run->pay_period_end?->format('Ymd') ?? 'end';

        return "Payroll_Report_{$module}_All_Companies_{$start}_{$end}_Run_{$run->id}.pdf";
    }
}
