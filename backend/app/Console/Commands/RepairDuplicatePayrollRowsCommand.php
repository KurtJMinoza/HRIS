<?php

namespace App\Console\Commands;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Services\PayslipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detect and repair duplicate active payslip rows (period_slot=0) and recalculate batch totals.
 */
class RepairDuplicatePayrollRowsCommand extends Command
{
    protected $signature = 'payroll:repair-duplicate-rows
        {--batch-id= : Optional payroll_batch_run id to limit repair}
        {--dry-run : Report duplicates without changing data}';

    protected $description = 'Void duplicate active payslips per employee/period, dedupe payroll_period rows, and recalculate batch totals';

    public function handle(PayslipService $payslipService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchId = $this->option('batch-id');
        $batchId = is_numeric($batchId) ? (int) $batchId : null;

        if ($dryRun) {
            $this->warn('Dry run — no rows will be changed.');
        }

        if (! $dryRun) {
            $cleared = Payslip::query()
                ->whereNotNull('payroll_period_id')
                ->where(function ($q) {
                    $q->where('status', Payslip::STATUS_VOIDED)
                        ->orWhereNotNull('voided_at');
                })
                ->update(['payroll_period_id' => null]);
            if ($cleared > 0) {
                $this->info('Cleared payroll_period_id from '.$cleared.' voided payslip(s).');
            }
        }

        $duplicateGroups = DB::table('payslips')
            ->select(
                'user_id',
                'pay_period_start',
                'pay_period_end',
                DB::raw('COUNT(*) as row_count')
            )
            ->where('period_slot', 0)
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->when($batchId !== null && $batchId > 0, function ($query) use ($batchId, $payslipService) {
                $run = PayrollBatchRun::query()->find($batchId);
                if ($run instanceof PayrollBatchRun) {
                    $ids = $payslipService->latestUniquePayslipIdsForQuery(
                        Payslip::query()
                            ->when($run->pay_period_start, fn ($q) => $q->whereDate('pay_period_start', $run->pay_period_start->toDateString()))
                            ->when($run->pay_period_end, fn ($q) => $q->whereDate('pay_period_end', $run->pay_period_end->toDateString()))
                            ->when($run->company_id, fn ($q) => $q->where('company_id', (int) $run->company_id))
                    );
                    if ($ids !== []) {
                        $userIds = Payslip::query()->whereIn('id', $ids)->pluck('user_id')->all();
                        $query->whereIn('user_id', $userIds);
                    }
                }
            })
            ->groupBy('user_id', 'pay_period_start', 'pay_period_end')
            ->having('row_count', '>', 1)
            ->get();

        $voided = 0;
        foreach ($duplicateGroups as $group) {
            $rows = Payslip::query()
                ->where('user_id', (int) $group->user_id)
                ->whereDate('pay_period_start', $group->pay_period_start)
                ->whereDate('pay_period_end', $group->pay_period_end)
                ->where('period_slot', 0)
                ->where('status', '!=', Payslip::STATUS_VOIDED)
                ->orderByDesc('id')
                ->get();

            $keeper = $rows->first();
            if ($keeper === null) {
                continue;
            }

            $this->line(sprintf(
                'user_id=%d period=%s..%s rows=%d keep_id=%d',
                (int) $group->user_id,
                (string) $group->pay_period_start,
                (string) $group->pay_period_end,
                (int) $group->row_count,
                (int) $keeper->id
            ));

            foreach ($rows->skip(1) as $duplicate) {
                if ($dryRun) {
                    $this->line('  would void payslip id='.$duplicate->id.' status='.$duplicate->status);
                    continue;
                }

                $updated = Payslip::query()->whereKey($duplicate->id)->update([
                    'status' => Payslip::STATUS_VOIDED,
                    'voided_at' => now(),
                    'period_slot' => (int) $duplicate->id,
                    'payroll_period_id' => null,
                    'is_sent' => false,
                    'delivered_at' => null,
                    'sent_at' => null,
                ]);
                if ($updated) {
                    $voided++;
                }
            }
        }

        $periodDupes = 0;
        if (Schema::hasTable('payroll_periods')) {
            $periodGroups = DB::table('payroll_periods')
                ->select('user_id', 'from_date', 'to_date', DB::raw('COUNT(*) as row_count'))
                ->groupBy('user_id', 'from_date', 'to_date')
                ->having('row_count', '>', 1)
                ->get();

            foreach ($periodGroups as $group) {
                $ids = DB::table('payroll_periods')
                    ->where('user_id', $group->user_id)
                    ->whereDate('from_date', $group->from_date)
                    ->whereDate('to_date', $group->to_date)
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $keepId = array_shift($ids);
                foreach ($ids as $removeId) {
                    $periodDupes++;
                    if ($dryRun) {
                        $this->line('  would delete payroll_period id='.$removeId.' (keep '.$keepId.')');
                        continue;
                    }
                    if (Schema::hasTable('payroll_breakdowns')) {
                        DB::table('payroll_breakdowns')->where('payroll_period_id', $removeId)->delete();
                    }
                    DB::table('payroll_periods')->where('id', $removeId)->delete();
                }
            }
        }

        $batchesUpdated = 0;
        $batchQuery = PayrollBatchRun::query()
            ->when($batchId !== null && $batchId > 0, fn ($q) => $q->whereKey($batchId));
        foreach ($batchQuery->cursor() as $run) {
            if ($dryRun) {
                $agg = $payslipService->aggregateForBatchRun($run);
                $this->line(sprintf(
                    'batch_run_id=%d status=%s net=%.2f unique_employees=%d',
                    (int) $run->id,
                    (string) $run->status,
                    (float) ($agg['total_net_pay'] ?? 0),
                    (int) ($agg['payslip_count'] ?? 0)
                ));
                continue;
            }
            $payslipService->syncBatchRunTotals($run);
            $batchesUpdated++;
        }

        $this->info(sprintf(
            'Repair complete. voided_payslips=%d removed_payroll_periods=%d batches_synced=%d',
            $voided,
            $periodDupes,
            $batchesUpdated
        ));

        return self::SUCCESS;
    }
}
