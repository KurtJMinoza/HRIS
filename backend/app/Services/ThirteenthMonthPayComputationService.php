<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\ThirteenthMonthSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ThirteenthMonthPayComputationService
{
    public const BASIC_COMPONENTS = ['regular_pay', 'basic_pay', 'basic_salary'];

    /** @var array{basis_total: float, included_payroll_runs: array, included_line_items: array} */
    private array $lastComputationTrace = [
        'basis_total' => 0.0,
        'included_payroll_runs' => [],
        'included_line_items' => [],
    ];

    public function activeSettingForCompany(int $companyId): ?ThirteenthMonthSetting
    {
        return ThirteenthMonthSetting::query()->where('is_active', true)->latest('updated_at')->get()
            ->first(fn (ThirteenthMonthSetting $setting) => $setting->appliesToCompany($companyId));
    }

    public function computedAmount(User $employee, int $companyId, ThirteenthMonthSetting $setting, string $payrollModule = Payslip::MODULE_STANDARD): float
    {
        $this->resetComputationTrace();
        $start = $setting->coverageStart();
        $end = $setting->coverageEnd();
        $basis = $setting->basis_type === ThirteenthMonthSetting::BASIS_GROSS
            ? $this->grossPay((int) $employee->id, $companyId, $start, $end, $payrollModule)
            : $this->basicPay((int) $employee->id, $companyId, $start, $end, $payrollModule);
        if ($this->lastComputationTrace['basis_total'] === 0.0 && $basis > 0) {
            // Keeps the trace correct for alternate implementations and test doubles.
            $this->lastComputationTrace['basis_total'] = round($basis, 2);
        }

        return $this->amountFromBasisTotal($basis);
    }

    public function amountFromBasisTotal(float $basisTotal): float
    {
        return round(max(0.0, $basisTotal) / 12, 2);
    }

    public function applyToPayslipSnapshot(array $snapshot, User $employee, int $companyId, CarbonInterface $payDate, int $payrollRunId, string $payrollModule = Payslip::MODULE_STANDARD, array $recoveredBasisLines = []): array
    {
        $setting = $this->activeSettingForCompany($companyId);
        if (! $setting) {
            Log::warning('13th_month_config_missing', ['13th_month_enabled' => true, 'employee_id' => (int) $employee->id, 'company_id' => $companyId, 'config_found' => false, 'line_item_inserted' => false, 'skip_reason' => 'active_configuration_not_found']);

            return $snapshot;
        }
        $amount = $this->computedAmount($employee, $companyId, $setting, $payrollModule);
        if ($recoveredBasisLines !== []) {
            $this->appendRecoveredBasisLines($recoveredBasisLines);
            $amount = $this->amountFromBasisTotal($this->lastComputationTrace['basis_total']);
        }
        if ($amount <= 0) {
            $this->logComputation($employee, $companyId, $setting, 0.0, false, 'no_finalized_basis_amount');

            return $snapshot;
        }

        $start = $setting->coverageStart();
        $end = $setting->coverageEnd();
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $lines = is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [];
        $lines = array_values(array_filter($lines, fn ($line) => strtoupper((string) ($line['component_code'] ?? '')) !== '13TH_MONTH_PAY'));
        $metadata = [
            'coverage_start' => $start->toDateString(),
            'coverage_end' => $end->toDateString(),
            'basis_type' => $setting->basis_type,
            'computed_from_finalized_payroll' => $recoveredBasisLines === [],
            'recovered_missing_cutoffs' => count($recoveredBasisLines),
            'payroll_run_id' => $payrollRunId,
            'employee_id' => (int) $employee->id,
            'basis_total' => $this->lastComputationTrace['basis_total'],
            'included_payroll_runs' => array_values(array_filter(
                array_column($this->lastComputationTrace['included_payroll_runs'], 'payroll_run_id'),
                fn ($id) => $id !== null
            )),
        ];
        $label = $this->lineLabel((string) $setting->basis_type);
        $lines[] = [
            'key' => '13th_month_pay',
            'component_code' => '13TH_MONTH_PAY',
            'component_type' => 'earning',
            'type' => 'earning',
            'description' => $label,
            'label' => $label,
            'name' => $label,
            'category' => 'bonus',
            'amount' => $amount,
            'resolved_amount' => $amount,
            'units' => $start->format('M Y').' - '.$end->format('M Y'),
            'metadata' => $metadata,
        ];
        $summary['payslip_earning_lines'] = $lines;
        $summary['thirteenth_month_pay_this_period'] = $amount;
        $summary['thirteenth_month_metadata'] = $metadata;
        $snapshot['summary'] = $summary;
        $this->logComputation($employee, $companyId, $setting, $amount, true, null);

        return $snapshot;
    }

    private function logComputation(User $employee, int $companyId, ThirteenthMonthSetting $setting, float $amount, bool $inserted, ?string $skipReason): void
    {
        Log::info('payroll.13th_month_computation', [
            '13th_month_enabled' => true, 'employee_id' => (int) $employee->id, 'company_id' => $companyId,
            'config_found' => true, 'basis_type' => $setting->basis_type,
            'coverage_start' => $setting->coverageStart()->toDateString(), 'coverage_end' => $setting->coverageEnd()->toDateString(),
            'included_payroll_runs' => $this->lastComputationTrace['included_payroll_runs'],
            'included_line_items' => $this->lastComputationTrace['included_line_items'],
            'basis_total' => $this->lastComputationTrace['basis_total'], 'computed_13th_month' => $amount,
            'line_item_inserted' => $inserted, 'skip_reason' => $skipReason,
        ]);
    }

    public function preserveDraftLine(array $newSnapshot, array $storedSnapshot): array
    {
        $storedSummary = is_array($storedSnapshot['summary'] ?? null) ? $storedSnapshot['summary'] : [];
        $storedLines = is_array($storedSummary['payslip_earning_lines'] ?? null) ? $storedSummary['payslip_earning_lines'] : [];
        $frozen = null;
        foreach ($storedLines as $line) {
            if (is_array($line) && strtoupper((string) ($line['component_code'] ?? '')) === '13TH_MONTH_PAY') {
                $frozen = $line;
                break;
            }
        }
        if (! $frozen) {
            return $newSnapshot;
        }
        $basisType = (string) ($frozen['metadata']['basis_type'] ?? $storedSummary['thirteenth_month_metadata']['basis_type'] ?? ThirteenthMonthSetting::BASIS_BASIC);
        $label = $this->lineLabel($basisType);
        $frozen['description'] = $label;
        $frozen['label'] = $label;
        $frozen['name'] = $label;
        $summary = is_array($newSnapshot['summary'] ?? null) ? $newSnapshot['summary'] : [];
        $lines = is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [];
        $lines = array_values(array_filter($lines, fn ($line) => strtoupper((string) ($line['component_code'] ?? '')) !== '13TH_MONTH_PAY'));
        $lines[] = $frozen;
        $summary['payslip_earning_lines'] = $lines;
        $summary['thirteenth_month_pay_this_period'] = (float) ($frozen['amount'] ?? 0);
        $summary['thirteenth_month_metadata'] = $frozen['metadata'] ?? ($storedSummary['thirteenth_month_metadata'] ?? null);
        $newSnapshot['summary'] = $summary;

        return $newSnapshot;
    }

    private function lineLabel(string $basisType): string
    {
        return $basisType === ThirteenthMonthSetting::BASIS_GROSS
            ? '13th Month Pay (Gross Pay Method)'
            : '13th Month Pay (Basic Pay Method)';
    }

    protected function grossPay(int $employeeId, int $companyId, CarbonInterface $start, CarbonInterface $end, string $payrollModule = Payslip::MODULE_STANDARD): float
    {
        $rows = $this->finalizedPayrollEmployeeQuery($employeeId, $companyId, $start, $end, $payrollModule)
            ->get([
                'pe.id as payroll_employee_id', 'pe.payslip_id', 'r.id as payroll_run_id',
                'p.pay_date', 'p.pay_period_end', 'pe.gross_pay',
            ]);

        $representedPayslips = $rows->pluck('payslip_id')->map(fn ($id) => (int) $id)->all();
        $fallback = $this->finalizedPayslipQuery($employeeId, $companyId, $start, $end, $payrollModule)
            ->when($representedPayslips !== [], fn ($query) => $query->whereNotIn('p.id', $representedPayslips))
            ->get(['p.id as payslip_id', 'r.id as payroll_run_id', 'p.pay_date', 'p.pay_period_end', 'p.gross_pay']);

        $included = $rows->map(fn ($row) => [
            'payroll_run_id' => (int) $row->payroll_run_id,
            'payslip_id' => (int) $row->payslip_id,
            'pay_date' => (string) ($row->pay_date ?? $row->pay_period_end),
            'amount' => round(max(0.0, (float) $row->gross_pay), 2),
            'source' => 'payroll_employees.gross_pay',
        ])->concat($fallback->map(fn ($row) => [
            'payroll_run_id' => (int) $row->payroll_run_id,
            'payslip_id' => (int) $row->payslip_id,
            'pay_date' => (string) ($row->pay_date ?? $row->pay_period_end),
            'amount' => round(max(0.0, (float) $row->gross_pay), 2),
            'source' => 'payslips.gross_pay',
        ]))->values();

        $total = round((float) $included->sum('amount'), 2);
        $this->setComputationTrace($total, $included, collect());

        return $total;
    }

    protected function basicPay(int $employeeId, int $companyId, CarbonInterface $start, CarbonInterface $end, string $payrollModule = Payslip::MODULE_STANDARD): float
    {
        $lineRows = $this->finalizedPayrollEmployeeQuery($employeeId, $companyId, $start, $end, $payrollModule)
            ->join('payroll_lines as pl', 'pl.payroll_employee_id', '=', 'pe.id')
            ->whereRaw('LOWER(pl.type) = ?', ['earning'])
            ->where('pl.amount', '>', 0)
            ->get([
                'pl.id as payroll_line_id', 'pl.line_key', 'pl.component_code', 'pl.component_name',
                'pl.description', 'pl.category', 'pl.amount', 'pe.payslip_id', 'r.id as payroll_run_id',
                'p.pay_date', 'p.pay_period_end',
            ])
            ->filter(fn ($row) => $this->isBasicPayLine($row))
            ->values();

        // Older finalized payrolls predate payroll_lines. Use their frozen snapshot only when
        // the same payslip was not already represented by a canonical basic-pay line.
        $representedPayslips = $lineRows->pluck('payslip_id')->map(fn ($id) => (int) $id)->unique()->all();
        $fallbackRows = $this->finalizedPayslipQuery($employeeId, $companyId, $start, $end, $payrollModule)
            ->when($representedPayslips !== [], fn ($query) => $query->whereNotIn('p.id', $representedPayslips))
            ->get(['p.id as payslip_id', 'r.id as payroll_run_id', 'p.pay_date', 'p.pay_period_end', 'p.snapshot'])
            ->map(function ($payslip) {
                $snapshot = json_decode((string) $payslip->snapshot, true);
                $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
                $earningLines = is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [];
                $matched = collect($earningLines)->filter(fn ($line) => is_array($line) && $this->isBasicPayLine((object) $line));
                $amount = $matched->isNotEmpty()
                    ? (float) $matched->sum(fn ($line) => max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)))
                    : max(0.0, (float) ($summary['basic_pay_this_period'] ?? 0));

                return [
                    'payroll_line_id' => null, 'line_key' => 'snapshot:basic_pay_this_period',
                    'component_code' => 'BASIC_PAY', 'amount' => round($amount, 2),
                    'payslip_id' => (int) $payslip->payslip_id, 'payroll_run_id' => (int) $payslip->payroll_run_id,
                    'pay_date' => (string) ($payslip->pay_date ?? $payslip->pay_period_end), 'source' => 'finalized_payslip_snapshot',
                ];
            })->filter(fn ($row) => $row['amount'] > 0)->values();

        $includedLines = $lineRows->map(fn ($row) => [
            'payroll_line_id' => (int) $row->payroll_line_id,
            'payroll_run_id' => (int) $row->payroll_run_id,
            'payslip_id' => (int) $row->payslip_id,
            'pay_date' => (string) ($row->pay_date ?? $row->pay_period_end),
            'component_code' => (string) ($row->component_code ?? $row->line_key),
            'amount' => round((float) $row->amount, 2),
            'source' => 'payroll_lines',
        ])->concat($fallbackRows)->values();

        $total = round((float) $includedLines->sum('amount'), 2);
        $runs = $includedLines->groupBy('payroll_run_id')->map(fn ($items, $runId) => [
            'payroll_run_id' => (int) $runId,
            'payslip_id' => (int) $items->first()['payslip_id'],
            'pay_date' => $items->first()['pay_date'],
            'amount' => round((float) $items->sum('amount'), 2),
        ])->values();
        $this->setComputationTrace($total, $runs, $includedLines);

        return $total;
    }

    private function finalizedPayrollEmployeeQuery(int $employeeId, int $companyId, CarbonInterface $start, CarbonInterface $end, string $payrollModule): Builder
    {
        return DB::table('payroll_employees as pe')
            ->join('payroll_batch_runs as r', 'r.id', '=', 'pe.payroll_batch_run_id')
            ->join('payslips as p', 'p.id', '=', 'pe.payslip_id')
            ->where('pe.user_id', $employeeId)->where('pe.company_id', $companyId)
            ->where('pe.payroll_module', $payrollModule)->where('r.status', PayrollBatchRun::STATUS_FINALIZED)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('p.pay_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('p.pay_period_end', [$start->toDateString(), $end->toDateString()]);
            });
    }

    private function finalizedPayslipQuery(int $employeeId, int $companyId, CarbonInterface $start, CarbonInterface $end, string $payrollModule): Builder
    {
        return DB::table('payslips as p')->join('payroll_batch_runs as r', 'r.id', '=', 'p.payroll_batch_run_id')
            ->where('p.user_id', $employeeId)->where('p.company_id', $companyId)
            ->where('p.payroll_module', $payrollModule)->where('r.status', PayrollBatchRun::STATUS_FINALIZED)
            ->where(function (Builder $query) use ($start, $end) {
                $query->whereBetween('p.pay_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('p.pay_period_end', [$start->toDateString(), $end->toDateString()]);
            });
    }

    /** @return list<string> */
    public function finalizedPeriodKeys(int $employeeId, int $companyId, CarbonInterface $start, CarbonInterface $end, string $payrollModule = Payslip::MODULE_STANDARD): array
    {
        return $this->finalizedPayslipQuery($employeeId, $companyId, $start, $end, $payrollModule)
            ->get(['p.pay_period_start', 'p.pay_period_end'])
            ->map(fn ($row) => (string) $row->pay_period_start.'|'.(string) $row->pay_period_end)
            ->unique()->values()->all();
    }

    private function isBasicPayLine(object $line): bool
    {
        foreach (['component_code', 'line_key', 'category'] as $field) {
            $value = strtolower(trim((string) ($line->{$field} ?? '')));
            $suffix = str_contains($value, ':') ? substr($value, strrpos($value, ':') + 1) : $value;
            if (in_array($value, self::BASIC_COMPONENTS, true) || in_array($suffix, self::BASIC_COMPONENTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function resetComputationTrace(): void
    {
        $this->lastComputationTrace = ['basis_total' => 0.0, 'included_payroll_runs' => [], 'included_line_items' => []];
    }

    private function setComputationTrace(float $basisTotal, Collection $runs, Collection $lines): void
    {
        $this->lastComputationTrace = [
            'basis_total' => round($basisTotal, 2),
            'included_payroll_runs' => $runs->values()->all(),
            'included_line_items' => $lines->values()->all(),
        ];
    }

    private function appendRecoveredBasisLines(array $lines): void
    {
        foreach ($lines as $line) {
            if (! is_array($line) || (float) ($line['amount'] ?? 0) <= 0) {
                continue;
            }
            $normalized = [
                'payroll_line_id' => null,
                'payroll_run_id' => null,
                'payslip_id' => null,
                'pay_date' => (string) ($line['pay_date'] ?? ''),
                'period_start' => (string) ($line['period_start'] ?? ''),
                'period_end' => (string) ($line['period_end'] ?? ''),
                'component_code' => (string) ($line['component_code'] ?? 'BASIC_PAY'),
                'amount' => round((float) $line['amount'], 2),
                'source' => 'recovered_payroll_cutoff',
            ];
            $this->lastComputationTrace['included_line_items'][] = $normalized;
            $this->lastComputationTrace['included_payroll_runs'][] = [
                'payroll_run_id' => null,
                'payslip_id' => null,
                'pay_date' => $normalized['pay_date'],
                'period_start' => $normalized['period_start'],
                'period_end' => $normalized['period_end'],
                'amount' => $normalized['amount'],
                'source' => $normalized['source'],
            ];
            $this->lastComputationTrace['basis_total'] = round(
                $this->lastComputationTrace['basis_total'] + $normalized['amount'],
                2
            );
        }
    }
}
