<?php

namespace App\Jobs;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayslipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds finalized payslip PDFs after payroll has been locked.
 *
 * This job intentionally runs on a separate Redis queue so Chromium/PDF work never competes
 * with payroll computation or face registration.
 *
 * Run with:
 *   php artisan queue:work redis --queue=payslip --timeout=300 --sleep=1 --tries=2
 */
class GeneratePayslipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        private readonly int $batchRunId,
        private readonly ?int $actorUserId = null
    ) {
        $this->onConnection('redis');
        $this->onQueue('payslip');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('generate-payslip-pdfs-'.$this->batchRunId))->expireAfter(600)];
    }

    public function handle(PayslipService $payslipService): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $jobStartedAt = microtime(true);
        Log::info('GeneratePayslipsJob started', [
            'batch_run_id' => $this->batchRunId,
            'actor_user_id' => $this->actorUserId,
            'queue_connection' => $this->connection,
            'queue' => $this->queue,
            'queue_timeout_seconds' => $this->timeout,
        ]);

        $run = PayrollBatchRun::query()->find($this->batchRunId);
        if (! $run) {
            Log::warning('GeneratePayslipsJob skipped: payroll batch run missing', ['batch_run_id' => $this->batchRunId]);

            return;
        }

        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            Log::info('GeneratePayslipsJob skipped: batch is not finalized', [
                'batch_run_id' => (int) $run->id,
                'status' => (string) $run->status,
            ]);

            return;
        }

        $generated = 0;
        $failed = 0;

        $this->payslipsForBatchRun($run)
            ->with(['employee.company', 'employee.branch', 'employee.departmentRelation', 'employee.governmentIds'])
            ->chunkById(10, function ($payslips) use ($payslipService, &$generated, &$failed) {
                /** @var Payslip $payslip */
                foreach ($payslips as $payslip) {
                    $employee = $payslip->employee;
                    if (! $employee instanceof User) {
                        $failed++;

                        continue;
                    }

                    try {
                        $payslipService->ensurePayslipPdfOnDisk($payslip, $employee);
                        $generated++;
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                        Log::error('GeneratePayslipsJob PDF failed', [
                            'batch_run_id' => $this->batchRunId,
                            'payslip_id' => (int) $payslip->id,
                            'user_id' => (int) $employee->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        Log::info('GeneratePayslipsJob completed', [
            'batch_run_id' => (int) $run->id,
            'generated_pdfs' => $generated,
            'failed_pdfs' => $failed,
            'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ]);
    }

    private function payslipsForBatchRun(PayrollBatchRun $run): Builder
    {
        $query = Payslip::query()
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString());

        if ($run->company_id !== null) {
            $query->where('company_id', (int) $run->company_id);
        }
        if ($run->branch_id !== null) {
            $query->where('branch_id', (int) $run->branch_id);
        }
        if ($run->department_id !== null) {
            $query->where('department_id', (int) $run->department_id);
        }
        if ($run->employee_id !== null) {
            $query->where('user_id', (int) $run->employee_id);
        }

        return $query;
    }
}
