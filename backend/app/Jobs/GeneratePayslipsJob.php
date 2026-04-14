<?php

namespace App\Jobs;

use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Services\PayslipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Background bulk generation of Draft payslips (DB rows + snapshot) for a {@see PayrollBatchRun}.
 *
 * Important: this job intentionally does NOT generate PDFs. PDFs are generated during Finalize Payroll
 * (lock step) or on-demand endpoints; generation should be fast and non-blocking for the admin UI.
 */
class GeneratePayslipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /** @var list<string>|null */
    private static ?array $payrollBatchRunColumns = null;

    public function __construct(
        private readonly int $batchRunId,
        private readonly ?int $actorUserId = null
    ) {}

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
            'queue_timeout_seconds' => $this->timeout,
        ]);

        $run = PayrollBatchRun::query()->find($this->batchRunId);
        if (! $run) {
            Log::warning('GeneratePayslipsJob skipped: payroll batch run missing', ['batch_run_id' => $this->batchRunId]);

            return;
        }

        // If finalized, nothing to do here.
        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            Log::info('GeneratePayslipsJob skipped: already finalized', [
                'batch_run_id' => $this->batchRunId,
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);

            return;
        }

        PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
            'status' => PayrollBatchRun::STATUS_PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ]));

        $actor = $this->actorUserId ? User::query()->find($this->actorUserId) : null;

        try {
            $payload = [
                'from_date' => $run->pay_period_start?->toDateString(),
                'to_date' => $run->pay_period_end?->toDateString(),
                'pay_cycle_id' => $run->pay_cycle_id,
                'reference_date' => $run->reference_date?->toDateString(),
                'payroll_period_id' => $run->payroll_period_id,
                'is_final_pay' => (bool) $run->is_final_pay,
                'password_protect' => (bool) $run->password_protect,
                // scope is taken from the batch run
            ];

            $ids = $payslipService->generateBulkPayslips(
                $run->company_id ? (int) $run->company_id : null,
                $run->branch_id ? (int) $run->branch_id : null,
                $run->department_id ? (int) $run->department_id : null,
                null,
                $payload,
                $actor,
                withPdf: false,
            );

            PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_DRAFT,
                'completed_at' => now(),
                'employee_count' => count($ids),
                'error_message' => null,
            ]));

            $run->refresh();
            $payslipService->syncBatchRunTotals($run);

            Log::info('GeneratePayslipsJob completed', [
                'batch_run_id' => (int) $run->id,
                'payslip_count' => count($ids),
                'total_net' => $run->total_net,
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('GeneratePayslipsJob failed', [
                'batch_run_id' => (int) $run->id,
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);
            PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterBatchRunPayload(array $payload): array
    {
        if (self::$payrollBatchRunColumns === null) {
            self::$payrollBatchRunColumns = Schema::hasTable('payroll_batch_runs')
                ? Schema::getColumnListing('payroll_batch_runs')
                : [];
        }
        if (self::$payrollBatchRunColumns === []) {
            return $payload;
        }
        $allowed = array_flip(self::$payrollBatchRunColumns);

        return array_intersect_key($payload, $allowed);
    }
}
