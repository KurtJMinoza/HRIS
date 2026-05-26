<?php

namespace App\Services;

use App\Models\PayrollEmployee;
use App\Models\PayrollLine;
use App\Models\Payslip;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Persists payroll line items (draft/finalized) and employee summaries — single source for finalized display.
 */
class PayrollLinePersistService
{
    public function tablesReady(): bool
    {
        try {
            return Schema::hasTable('payroll_employees') && Schema::hasTable('payroll_lines');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Replace draft payroll lines from a payslip snapshot (no compensation resolver).
     */
    public function syncDraftLinesFromPayslip(Payslip $payslip): ?PayrollEmployee
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $employee = $this->upsertPayrollEmployee($payslip, PayrollEmployee::STATUS_DRAFT);
        $rows = $this->extractLineRowsFromPayslipSnapshot($payslip);

        DB::transaction(function () use ($employee, $payslip, $rows): void {
            PayrollLine::query()
                ->where('payroll_employee_id', (int) $employee->id)
                ->where('status', PayrollLine::STATUS_DRAFT)
                ->delete();

            foreach ($rows as $row) {
                PayrollLine::query()->create(array_merge($row, [
                    'payroll_employee_id' => (int) $employee->id,
                    'payslip_id' => (int) $payslip->id,
                    'status' => PayrollLine::STATUS_DRAFT,
                ]));
            }

            $this->syncPayrollEmployeeSummaryFromLines((int) $employee->id, PayrollLine::STATUS_DRAFT);
        });

        return $employee->fresh(['lines']);
    }

    /**
     * Copy every draft line to finalized (exact field copy) and sync employee + payslip totals.
     *
     * @throws \RuntimeException
     */
    public function finalizeLinesFromDraft(Payslip $payslip): PayrollEmployee
    {
        if (! $this->tablesReady()) {
            throw new \RuntimeException('Payroll line tables are not available.');
        }

        $draftEmployee = PayrollEmployee::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollEmployee::STATUS_DRAFT)
            ->first();

        if (! $draftEmployee instanceof PayrollEmployee) {
            $this->syncDraftLinesFromPayslip($payslip);
            $draftEmployee = PayrollEmployee::query()
                ->where('payslip_id', (int) $payslip->id)
                ->where('status', PayrollEmployee::STATUS_DRAFT)
                ->firstOrFail();
        }

        $draftLines = PayrollLine::query()
            ->where('payroll_employee_id', (int) $draftEmployee->id)
            ->where('status', PayrollLine::STATUS_DRAFT)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($draftLines->isEmpty()) {
            $gross = round((float) $draftEmployee->gross_pay, 2);
            $deductions = round((float) $draftEmployee->total_deductions, 2);
            $net = round((float) $draftEmployee->net_pay, 2);
            if (abs($gross) < 0.01 && abs($deductions) < 0.01 && abs($net) < 0.01) {
                $finalEmployee = $this->upsertPayrollEmployee($payslip, PayrollEmployee::STATUS_FINALIZED);
                $finalEmployee->forceFill([
                    'gross_pay' => 0.0,
                    'total_deductions' => 0.0,
                    'net_pay' => 0.0,
                ]);
                $finalEmployee->save();

                return $finalEmployee->fresh() ?? $finalEmployee;
            }

            throw new \RuntimeException('Cannot finalize: draft payroll lines are missing for payslip_id='.(int) $payslip->id);
        }

        $finalEmployee = $this->upsertPayrollEmployee($payslip, PayrollEmployee::STATUS_FINALIZED);

        DB::transaction(function () use ($draftLines, $finalEmployee, $payslip, $draftEmployee): void {
            PayrollLine::query()
                ->where('payroll_employee_id', (int) $finalEmployee->id)
                ->where('status', PayrollLine::STATUS_FINALIZED)
                ->delete();

            foreach ($draftLines as $draftLine) {
                PayrollLine::query()->create([
                    'payroll_employee_id' => (int) $finalEmployee->id,
                    'payslip_id' => (int) $payslip->id,
                    'line_key' => $draftLine->line_key,
                    'component_code' => $draftLine->component_code,
                    'component_name' => $draftLine->component_name,
                    'description' => $draftLine->description,
                    'type' => $draftLine->type,
                    'category' => $draftLine->category,
                    'amount' => round((float) $draftLine->amount, 2),
                    'units' => $draftLine->units,
                    'schedule' => $draftLine->schedule,
                    'calculation_standard' => $draftLine->calculation_standard,
                    'source_type' => $draftLine->source_type,
                    'source_id' => $draftLine->source_id,
                    'metadata' => $draftLine->metadata,
                    'status' => PayrollLine::STATUS_FINALIZED,
                    'sort_order' => (int) $draftLine->sort_order,
                ]);
            }

            $this->assertDraftFinalizedLineParity((int) $draftEmployee->id, (int) $finalEmployee->id);

            $summary = $this->syncPayrollEmployeeSummaryFromFinalizedLines((int) $finalEmployee->id);
            $payslip->forceFill([
                'gross_pay' => $summary['gross_pay'],
                'total_deductions' => $summary['total_deductions'],
                'net_pay' => $summary['net_pay'],
            ]);
            $payslip->save();

            $this->applyFinalizedLinesToPayslipSnapshot($payslip);
            $this->logFinalizedLineCopyDiagnostics($payslip, $draftEmployee, $finalEmployee, $summary);
        });

        $this->clearPayrollCaches($payslip);

        return $finalEmployee->fresh(['lines']) ?? $finalEmployee;
    }

    /**
     * @return array{gross_pay: float, total_deductions: float, net_pay: float}
     */
    public function syncPayrollEmployeeSummaryFromFinalizedLines(int $payrollEmployeeId): array
    {
        return $this->syncPayrollEmployeeSummaryFromLines($payrollEmployeeId, PayrollLine::STATUS_FINALIZED);
    }

    /**
     * @return array{gross_pay: float, total_deductions: float, net_pay: float}
     */
    public function syncPayrollEmployeeSummaryFromLines(int $payrollEmployeeId, string $lineStatus): array
    {
        $gross = 0.0;
        $deductions = 0.0;

        $lines = PayrollLine::query()
            ->where('payroll_employee_id', $payrollEmployeeId)
            ->where('status', $lineStatus)
            ->get(['type', 'amount']);

        foreach ($lines as $line) {
            $amount = round(max(0.0, (float) $line->amount), 2);
            if ((string) $line->type === PayrollLine::TYPE_EARNING) {
                $gross += $amount;
            } else {
                $deductions += $amount;
            }
        }

        $gross = round($gross, 2);
        $deductions = round($deductions, 2);
        $net = round($gross - $deductions, 2);

        PayrollEmployee::query()->whereKey($payrollEmployeeId)->update([
            'gross_pay' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => $net,
        ]);

        return [
            'gross_pay' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => $net,
        ];
    }

    /**
     * Build payslip snapshot summary tables from finalized payroll_lines only.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshotSummaryFromFinalizedLines(Payslip $payslip): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $lines = PayrollLine::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollLine::STATUS_FINALIZED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $dailyEarnings = [];
        $payslipEarnings = [];
        $payslipDeductions = [];
        $customDeductions = [];

        foreach ($lines as $line) {
            $row = [
                'key' => (string) ($line->line_key ?? ''),
                'label' => (string) ($line->component_name ?? $line->description ?? 'Line'),
                'amount' => round((float) $line->amount, 2),
                'category' => $line->category,
                'component_code' => $line->component_code,
                'units' => $line->units,
                'component_schedule' => $line->schedule,
                'resolved_schedule' => $line->schedule,
                'calculation_standard' => $line->calculation_standard,
                'resolved_calculation_standard' => $line->calculation_standard,
                'component_amount' => round((float) data_get($line->metadata, 'component_amount', $line->amount), 2),
                'resolved_amount' => round((float) $line->amount, 2),
            ];

            if ((string) $line->type === PayrollLine::TYPE_EARNING) {
                if (str_contains(strtolower((string) $line->line_key), 'daily:')) {
                    $dailyEarnings[] = $row;
                } else {
                    $payslipEarnings[] = $row;
                }

                continue;
            }

            $code = strtoupper(trim((string) ($line->component_code ?? '')));
            $isGov = in_array($code, ['SSS', 'PHILHEALTH', 'PAGIBIG', 'PAG-IBIG', 'WHT', 'WITHHOLDING'], true)
                || str_contains(strtolower((string) $line->category), 'government');

            if ($isGov) {
                $payslipDeductions[] = $row;
            } else {
                $customDeductions[] = $row;
            }
        }

        $employee = PayrollEmployee::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollEmployee::STATUS_FINALIZED)
            ->first();

        $gross = round((float) ($employee->gross_pay ?? 0), 2);
        $ded = round((float) ($employee->total_deductions ?? 0), 2);
        $net = round((float) ($employee->net_pay ?? 0), 2);

        return [
            'daily_computation_earning_lines' => array_values($dailyEarnings),
            'payslip_earning_lines' => array_values($payslipEarnings),
            'payslip_deduction_lines' => array_values($payslipDeductions),
            'payslip_custom_deduction_lines' => array_values($customDeductions),
            'gross_pay_this_period' => $gross,
            'total_deductions_this_period' => $ded,
            'net_pay_after_withholding_estimate' => $net,
            'custom_deductions_this_period' => round(array_sum(array_map(
                fn (array $l) => (float) ($l['amount'] ?? 0),
                $customDeductions
            )), 2),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function finalizedDeductionCatalogForPayslip(Payslip $payslip): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        $lines = PayrollLine::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollLine::STATUS_FINALIZED)
            ->where('type', PayrollLine::TYPE_DEDUCTION)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $catalog = [];
        foreach ($lines as $line) {
            $code = trim((string) ($line->component_code ?? ''));
            if ($code === '') {
                $code = trim((string) ($line->line_key ?? ''));
            }
            $catalog[] = [
                'line_key' => $code !== '' ? $code : 'line:'.$line->id,
                'component_code' => $code,
                'component_name' => (string) ($line->component_name ?? ''),
                'schedule' => (string) ($line->schedule ?? ''),
                'calculation_standard' => (string) ($line->calculation_standard ?? ''),
                'configured_amount' => round((float) data_get($line->metadata, 'component_amount', $line->amount), 2),
                'amount' => round((float) $line->amount, 2),
            ];
        }

        return $catalog;
    }

    public function clearPayrollCaches(Payslip $payslip): void
    {
        $batchId = $payslip->payroll_batch_run_id !== null ? (int) $payslip->payroll_batch_run_id : 0;
        $companyId = $payslip->company_id !== null ? (int) $payslip->company_id : 0;
        $userId = (int) $payslip->user_id;

        $keys = [
            "payroll:finalized:payslip:{$payslip->id}",
            "payroll:employee_summary:{$payslip->id}",
            "payroll:table:batch:{$batchId}",
            "payroll:report:batch:{$batchId}",
            "payroll:report:company:{$companyId}",
            "payroll:recent:company:{$companyId}",
            "payroll:recent:user:{$userId}",
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Log::info('payroll.cache_cleared_after_finalization', [
            'payslip_id' => (int) $payslip->id,
            'payroll_batch_run_id' => $batchId > 0 ? $batchId : null,
            'keys' => $keys,
        ]);
    }

    /**
     * @return array{gross_pay: float, total_deductions: float, net_pay: float}
     */
    public function totalsFromFinalizedLinesForPayslip(Payslip $payslip): ?array
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $employee = PayrollEmployee::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollEmployee::STATUS_FINALIZED)
            ->first();

        if (! $employee instanceof PayrollEmployee) {
            return null;
        }

        return [
            'gross_pay' => round((float) $employee->gross_pay, 2),
            'total_deductions' => round((float) $employee->total_deductions, 2),
            'net_pay' => round((float) $employee->net_pay, 2),
        ];
    }

    private function upsertPayrollEmployee(Payslip $payslip, string $status): PayrollEmployee
    {
        return PayrollEmployee::query()->updateOrCreate(
            [
                'payslip_id' => (int) $payslip->id,
                'status' => $status,
            ],
            [
                'payroll_batch_run_id' => $payslip->payroll_batch_run_id,
                'user_id' => (int) $payslip->user_id,
                'company_id' => $payslip->company_id,
                'pay_period_start' => $payslip->pay_period_start?->toDateString(),
                'pay_period_end' => $payslip->pay_period_end?->toDateString(),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractLineRowsFromPayslipSnapshot(Payslip $payslip): array
    {
        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            return [];
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summary = app(PayslipService::class)->frozenSnapshotForPayslipView($snapshot)['summary'] ?? $summary;

        $rows = [];
        $sort = 0;

        foreach (is_array($summary['daily_computation_earning_lines'] ?? null) ? $summary['daily_computation_earning_lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rows[] = $this->mapSnapshotLineToPayrollRow($line, PayrollLine::TYPE_EARNING, 'daily_computation', $sort++);
        }

        foreach (is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rows[] = $this->mapSnapshotLineToPayrollRow($line, PayrollLine::TYPE_EARNING, 'payslip_earning', $sort++);
        }

        foreach (is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rows[] = $this->mapSnapshotLineToPayrollRow($line, PayrollLine::TYPE_DEDUCTION, 'statutory', $sort++);
        }

        foreach (is_array($summary['payslip_custom_deduction_lines'] ?? null) ? $summary['payslip_custom_deduction_lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $rows[] = $this->mapSnapshotLineToPayrollRow($line, PayrollLine::TYPE_DEDUCTION, 'deduction', $sort++);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function mapSnapshotLineToPayrollRow(array $line, string $type, string $sourceType, int $sortOrder): array
    {
        $componentCode = trim((string) ($line['component_code'] ?? ''));
        $key = trim((string) ($line['key'] ?? ''));
        if ($componentCode === '' && $key !== '') {
            if (preg_match('/^pay_component:(\d+)$/i', $key, $m)) {
                $componentCode = 'pay_component:'.$m[1];
            } else {
                $componentCode = $key;
            }
        }

        $standard = trim((string) ($line['resolved_calculation_standard'] ?? $line['calculation_standard'] ?? ''));
        $configured = round((float) ($line['component_amount'] ?? $line['configured_amount'] ?? 0), 2);
        $amount = round((float) ($line['amount'] ?? 0), 2);
        if ($amount <= 0 && array_key_exists('resolved_amount', $line) && is_numeric($line['resolved_amount'])) {
            $amount = round((float) $line['resolved_amount'], 2);
        }
        $sourceId = null;
        if (isset($line['pay_component_id']) && is_numeric($line['pay_component_id'])) {
            $sourceId = (int) $line['pay_component_id'];
            $sourceType = 'pay_component';
        }

        return [
            'line_key' => $key !== '' ? $key : ($componentCode !== '' ? $componentCode : 'line:'.$sortOrder),
            'component_code' => $componentCode !== '' ? $componentCode : null,
            'component_name' => trim((string) ($line['label'] ?? $line['name'] ?? '')),
            'description' => null,
            'type' => $type,
            'category' => isset($line['category']) ? (string) $line['category'] : null,
            'amount' => $amount,
            'units' => isset($line['units']) ? (string) $line['units'] : null,
            'schedule' => trim((string) ($line['resolved_schedule'] ?? $line['component_schedule'] ?? $line['deduction_schedule_type'] ?? $line['schedule'] ?? '')),
            'calculation_standard' => $standard !== '' ? $standard : null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'metadata' => [
                'component_amount' => $configured > 0 ? $configured : null,
                'priority_bucket' => $line['priority_bucket'] ?? null,
                'legal_warning' => $line['legal_warning'] ?? null,
            ],
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @throws \RuntimeException
     */
    private function assertDraftFinalizedLineParity(int $draftPayrollEmployeeId, int $finalPayrollEmployeeId): void
    {
        $draftLines = PayrollLine::query()
            ->where('payroll_employee_id', $draftPayrollEmployeeId)
            ->where('status', PayrollLine::STATUS_DRAFT)
            ->get()
            ->keyBy(fn (PayrollLine $l) => $this->lineIdentityKey($l));

        $finalLines = PayrollLine::query()
            ->where('payroll_employee_id', $finalPayrollEmployeeId)
            ->where('status', PayrollLine::STATUS_FINALIZED)
            ->get()
            ->keyBy(fn (PayrollLine $l) => $this->lineIdentityKey($l));

        $draftGross = round((float) $draftLines->where('type', PayrollLine::TYPE_EARNING)->sum('amount'), 2);
        $finalGross = round((float) $finalLines->where('type', PayrollLine::TYPE_EARNING)->sum('amount'), 2);
        $draftDeductionTotal = round((float) $draftLines->where('type', PayrollLine::TYPE_DEDUCTION)->sum('amount'), 2);
        $finalDeductionTotal = round((float) $finalLines->where('type', PayrollLine::TYPE_DEDUCTION)->sum('amount'), 2);
        $draftNet = round($draftGross - $draftDeductionTotal, 2);
        $finalNet = round($finalGross - $finalDeductionTotal, 2);
        if ($draftLines->count() !== $finalLines->count()
            || abs($draftGross - $finalGross) >= 0.01
            || abs($draftDeductionTotal - $finalDeductionTotal) >= 0.01
            || abs($draftNet - $finalNet) >= 0.01) {
            throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
        }

        foreach ($draftLines as $key => $draft) {
            $final = $finalLines->get($key);
            if (! $final instanceof PayrollLine) {
                throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
            }
            if (abs(round((float) $draft->amount, 2) - round((float) $final->amount, 2)) >= 0.01) {
                throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
            }
            if ((string) $draft->component_code === 'LENDING_SALARY_DEDUCTION_EVERY_30'
                && abs(round((float) $draft->amount, 2) - round((float) $final->amount, 2)) >= 0.01) {
                throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
            }
        }
    }

    private function lineIdentityKey(PayrollLine $line): string
    {
        $code = trim((string) ($line->component_code ?? ''));
        if ($code !== '') {
            return $line->type.':'.$code;
        }

        return $line->type.':'.trim((string) ($line->line_key ?? 'line:'.$line->id));
    }

    private function applyFinalizedLinesToPayslipSnapshot(Payslip $payslip): void
    {
        $summaryPatch = $this->buildSnapshotSummaryFromFinalizedLines($payslip);
        if ($summaryPatch === []) {
            return;
        }

        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $snapshot['summary'] = array_merge($summary, $summaryPatch);
        $snapshot['finalization_source'] = 'payroll_lines_finalized';
        $payslip->forceFill(['snapshot' => $snapshot]);
        $payslip->save();
    }

    /**
     * @param  array{gross_pay: float, total_deductions: float, net_pay: float}  $summary
     */
    private function logFinalizedLineCopyDiagnostics(
        Payslip $payslip,
        PayrollEmployee $draftEmployee,
        PayrollEmployee $finalEmployee,
        array $summary
    ): void {
        $targetCode = 'LENDING_SALARY_DEDUCTION_EVERY_30';
        $draftLine = PayrollLine::query()
            ->where('payroll_employee_id', (int) $draftEmployee->id)
            ->where('status', PayrollLine::STATUS_DRAFT)
            ->where('component_code', $targetCode)
            ->first();
        $finalLine = PayrollLine::query()
            ->where('payroll_employee_id', (int) $finalEmployee->id)
            ->where('status', PayrollLine::STATUS_FINALIZED)
            ->where('component_code', $targetCode)
            ->first();

        Log::info('payroll.finalization_line_copy_diagnostics', [
            'employee_id' => (int) $payslip->user_id,
            'payroll_employee_id' => (int) $finalEmployee->id,
            'payroll_run_id' => $payslip->payroll_batch_run_id !== null ? (int) $payslip->payroll_batch_run_id : null,
            'component_code' => $targetCode,
            'schedule' => $finalLine instanceof PayrollLine ? (string) ($finalLine->schedule ?? '') : null,
            'calculation_standard' => $finalLine instanceof PayrollLine ? (string) ($finalLine->calculation_standard ?? '') : null,
            'configured_amount' => $finalLine instanceof PayrollLine ? round((float) data_get($finalLine->metadata, 'component_amount', $finalLine->amount), 2) : null,
            'draft_amount' => $draftLine instanceof PayrollLine ? round((float) $draftLine->amount, 2) : null,
            'finalized_amount' => $finalLine instanceof PayrollLine ? round((float) $finalLine->amount, 2) : null,
            'rendered_amount' => $finalLine instanceof PayrollLine ? round((float) $finalLine->amount, 2) : null,
            'gross_pay' => $summary['gross_pay'],
            'deduction_line_sum' => $summary['total_deductions'],
            'stored_total_deductions' => round((float) $payslip->total_deductions, 2),
            'table_total_deductions' => $summary['total_deductions'],
            'rendered_total_deductions' => $summary['total_deductions'],
            'net_pay' => $summary['net_pay'],
            'cap_deductions_enabled' => false,
            'was_deduction_capped' => false,
            'net_pay_forced_to_zero' => false,
            'cap_reason' => null,
            'resolver_called_during_finalization' => false,
            'source_table_used' => 'payroll_lines',
            'cache_hit' => false,
        ]);
    }
}
