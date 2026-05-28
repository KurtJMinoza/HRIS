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

        $rows = $payslips
            ->map(fn (Payslip $payslip): array => $this->rowForPayslip($payslip))
            ->sortBy('employee_sort_key')
            ->values()
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
            'reportCompanyName' => $isExecom ? 'Execom' : $company->name,
            'reportCompanyAddress' => $isExecom ? null : $company->address,
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
            'logoLocalPath' => $isExecom ? null : $this->logoLocalPath($company),
        ];
    }

    /**
     * @param  array<string, bool>  $dynamicColumns
     * @return list<array{key:string,label:string,group:string,class:string}>
     */
    private function reportColumns(array $dynamicColumns): array
    {
        $columns = [
            ['key' => 'employee_name', 'label' => 'Employee', 'group' => 'Employee', 'class' => 'employee'],
            ['key' => 'regular_basic_pay', 'label' => 'Regular / Basic', 'group' => 'Earnings', 'class' => 'num'],
        ];

        foreach ([
            'holiday_pay' => 'Holiday Pay',
            'overtime_pay' => 'Overtime',
            'night_differential' => 'Night Diff.',
            'paid_leave' => 'Paid Leave',
            'other_earnings' => 'Other Earn.',
        ] as $key => $label) {
            if ($dynamicColumns[$key] ?? false) {
                $columns[] = ['key' => $key, 'label' => $label, 'group' => 'Earnings', 'class' => 'num'];
            }
        }

        return array_merge($columns, [
            ['key' => 'allowance', 'label' => 'Allowance', 'group' => 'Allowance', 'class' => 'num'],
            ['key' => 'sss', 'label' => 'SSS', 'group' => 'Government Deductions', 'class' => 'num'],
            ['key' => 'philhealth', 'label' => 'PhilHealth', 'group' => 'Government Deductions', 'class' => 'num'],
            ['key' => 'pagibig', 'label' => 'Pag-IBIG', 'group' => 'Government Deductions', 'class' => 'num'],
            ['key' => 'withholding_tax', 'label' => 'WHT', 'group' => 'Government Deductions', 'class' => 'num'],
            ['key' => 'other_deductions', 'label' => 'Other Ded.', 'group' => 'Other Deductions', 'class' => 'num deductions'],
            ['key' => 'gross_earnings', 'label' => 'Gross', 'group' => 'Totals', 'class' => 'num gross'],
            ['key' => 'total_deductions', 'label' => 'Deductions', 'group' => 'Totals', 'class' => 'num deductions'],
            ['key' => 'net_pay', 'label' => 'Net Pay', 'group' => 'Totals', 'class' => 'num net'],
        ]);
    }

    /**
     * @return array{paper_size:string,orientation:string,body_font:string,header_font:string,cell_padding:string,employee_width:float,numeric_width:float}
     */
    private function layoutForColumnCount(int $columnCount): array
    {
        // Keep the payroll register in portrait while scaling paper/font density by column count.
        $employeeWidth = match (true) {
            $columnCount >= 18 => 10.0,
            $columnCount >= 15 => 11.5,
            $columnCount >= 12 => 13.0,
            default => 16.0,
        };
        $numericWidth = round((100.0 - $employeeWidth) / max(1, $columnCount - 1), 4);
        $paperSize = match (true) {
            $columnCount >= 18 => 'a2',
            $columnCount >= 13 => 'a3',
            $columnCount >= 10 => 'legal',
            default => 'a4',
        };

        return [
            'paper_size' => $paperSize,
            'orientation' => 'portrait',
            'body_font' => match (true) {
                $columnCount >= 18 => '4.6px',
                $columnCount >= 15 => '5.0px',
                $columnCount >= 12 => '5.5px',
                default => '6.2px',
            },
            'header_font' => match (true) {
                $columnCount >= 18 => '4.0px',
                $columnCount >= 15 => '4.4px',
                $columnCount >= 12 => '4.9px',
                default => '5.5px',
            },
            'cell_padding' => match (true) {
                $columnCount >= 18 => '0.6px 0.7px',
                $columnCount >= 15 => '0.8px 0.9px',
                $columnCount >= 12 => '1px 1.1px',
                default => '1.4px 1.6px',
            },
            'employee_width' => $employeeWidth,
            'numeric_width' => $numericWidth,
        ];
    }

    /**
     * @return Collection<int, Payslip>
     */
    private function finalizedPayslipsForRunCompany(PayrollBatchRun $run, Company $company): Collection
    {
        $query = Payslip::query()
            ->with(['employee:id,name,first_name,middle_name,last_name,suffix,employee_code'])
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('company_id', (int) $company->id)
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->whereIn('status', Payslip::lockingStatuses())
            ->when($run->pay_period_start !== null, fn ($q) => $q->whereDate('pay_period_start', $run->pay_period_start->toDateString()))
            ->when($run->pay_period_end !== null, fn ($q) => $q->whereDate('pay_period_end', $run->pay_period_end->toDateString()))
            ->orderBy('user_id')
            ->orderByDesc('id');

        return $query->get()->unique('user_id')->values();
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
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
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
        $earnings['regular_basic_pay'] = $this->metricAmount($categoryTotals, 'regular_pay', $earnings['regular_basic_pay']);
        $earnings['holiday_pay'] = $this->metricAmount($categoryTotals, 'holiday_pay', $earnings['holiday_pay']);
        $earnings['overtime_pay'] = $this->metricAmount($categoryTotals, 'overtime_pay', $earnings['overtime_pay']);
        $earnings['night_differential'] = $this->metricAmount($categoryTotals, 'night_differential', $earnings['night_differential']);
        $earnings['paid_leave'] = $this->metricAmount($categoryTotals, 'paid_leave', $earnings['paid_leave']);
        $earnings['allowance'] = $this->metricAmount($categoryTotals, 'allowance', $earnings['allowance']);
        $earnings['other_earnings'] = $this->metricAmount($categoryTotals, 'other_earning', $earnings['other_earnings']);

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
        return round(max(0.0, (float) ($line['amount'] ?? 0)), 2);
    }

    /**
     * @param  array<string, mixed>  $categoryTotals
     */
    private function metricAmount(array $categoryTotals, string $key, float $fallback): float
    {
        if (! array_key_exists($key, $categoryTotals)) {
            return round($fallback, 2);
        }

        return round(max(0.0, (float) $categoryTotals[$key]), 2);
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
        if (str_contains($text, 'regular') || str_contains($text, 'basic')) {
            return 'regular_basic_pay';
        }
        if (str_contains($text, 'holiday')) {
            return 'holiday_pay';
        }
        if (str_contains($text, 'overtime') || preg_match('/\bot\b/', $text)) {
            return 'overtime_pay';
        }
        if (str_contains($text, 'night') || str_contains($text, 'nd_pay') || str_contains($text, 'night_diff')) {
            return 'night_differential';
        }
        if (str_contains($text, 'paid_leave') || str_contains($text, 'paid leave') || str_contains($text, 'leave adjustment')) {
            return 'paid_leave';
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
            (string) ($line['key'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
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
}
