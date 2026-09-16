<?php

namespace App\Console\Commands;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Services\PayslipService;
use Illuminate\Console\Command;

class RepairFinalizedPayslipDisplayTotalsCommand extends Command
{
    protected $signature = 'payroll:repair-finalized-display-totals
        {--period-start= : Pay period start date (Y-m-d)}
        {--period-end= : Pay period end date (Y-m-d)}
        {--batch-id= : Optional payroll_batch_run id}
        {--dry-run : Preview changes without saving}';

    protected $description = 'Align finalized payslip display totals with payable line metrics for HRIS/report/bank export.';

    public function handle(PayslipService $payslipService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchId = (int) ($this->option('batch-id') ?? 0);
        $periodStart = trim((string) ($this->option('period-start') ?? ''));
        $periodEnd = trim((string) ($this->option('period-end') ?? ''));

        $query = Payslip::query()
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereNull('voided_at')
            ->whereNotNull('snapshot');

        if ($batchId > 0) {
            $query->where('payroll_batch_run_id', $batchId);
        } else {
            if ($periodStart === '' || $periodEnd === '') {
                $this->error('Provide --period-start and --period-end, or --batch-id.');

                return self::FAILURE;
            }
            $runIds = PayrollBatchRun::query()
                ->whereDate('pay_period_start', $periodStart)
                ->whereDate('pay_period_end', $periodEnd)
                ->where('status', PayrollBatchRun::STATUS_FINALIZED)
                ->pluck('id');
            if ($runIds->isEmpty()) {
                $this->warn('No finalized payroll batch runs found for the requested period.');

                return self::SUCCESS;
            }
            $query->whereIn('payroll_batch_run_id', $runIds);
        }

        $changedCount = 0;
        $batchIds = [];
        foreach ($query->cursor() as $payslip) {
            if ($dryRun) {
                $metrics = $payslipService->frozenPayslipLineMetrics($payslip);
                $snapshot = is_array($payslip->snapshot) ? $payslip->snapshot : json_decode((string) $payslip->snapshot, true);
                $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
                $displayNet = round((float) ($summary['display_net_pay'] ?? 0), 2);
                $metricsNet = round((float) ($metrics['net_pay'] ?? 0), 2);
                if (abs($displayNet - $metricsNet) > 0.015) {
                    $this->line(sprintf(
                        'payslip_id=%d user_id=%d display_net=%.2f metrics_net=%.2f',
                        (int) $payslip->id,
                        (int) $payslip->user_id,
                        $displayNet,
                        $metricsNet
                    ));
                    $changedCount++;
                }

                continue;
            }

            $result = $payslipService->repairFinalizedPayslipDisplayTotals($payslip, save: true);
            if ($result['changed']) {
                $changedCount++;
                if ($payslip->payroll_batch_run_id !== null) {
                    $batchIds[(int) $payslip->payroll_batch_run_id] = true;
                }
            }
        }

        if (! $dryRun) {
            foreach (array_keys($batchIds) as $runId) {
                $run = PayrollBatchRun::query()->find($runId);
                if ($run instanceof PayrollBatchRun) {
                    $payslipService->syncBatchRunTotals($run);
                }
            }
        }

        $this->info(sprintf(
            '%s complete. payslips_%s=%d batches_synced=%d',
            $dryRun ? 'Dry run' : 'Repair',
            $dryRun ? 'with_drift' : 'updated',
            $changedCount,
            $dryRun ? 0 : count($batchIds)
        ));

        return self::SUCCESS;
    }
}
