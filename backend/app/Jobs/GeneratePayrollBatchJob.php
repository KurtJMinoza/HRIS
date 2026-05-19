<?php

namespace App\Jobs;

use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Services\PayslipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Computes a draft payroll batch in the background and persists draft payslip rows.
 *
 * Run with:
 *   php artisan queue:work redis --queue=payroll --timeout=300 --sleep=1 --tries=1
 */
class GeneratePayrollBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    /** @var list<string>|null */
    private static ?array $payrollBatchRunColumns = null;

    public function __construct(
        private readonly int $batchRunId,
        private readonly ?int $actorUserId = null
    ) {
        $this->onConnection('redis');
        $this->onQueue('payroll');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('generate-payroll-batch-'.$this->batchRunId))->expireAfter(600)];
    }

    public function handle(PayslipService $payslipService): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $jobStartedAt = microtime(true);
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        Log::info('GeneratePayrollBatchJob started', [
            'batch_run_id' => $this->batchRunId,
            'actor_user_id' => $this->actorUserId,
            'queue_connection' => $this->connection,
            'queue' => $this->queue,
            'queue_timeout_seconds' => $this->timeout,
        ]);

        $run = PayrollBatchRun::query()->find($this->batchRunId);
        if (! $run) {
            Log::warning('GeneratePayrollBatchJob skipped: payroll batch run missing', ['batch_run_id' => $this->batchRunId]);

            return;
        }

        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            Log::info('GeneratePayrollBatchJob skipped: already finalized', [
                'batch_run_id' => $this->batchRunId,
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);

            return;
        }

        $expectedTotal = max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0));
        PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
            'status' => PayrollBatchRun::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'processed_employees' => 0,
            'failed_employees' => 0,
            'total_employees' => $expectedTotal,
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
            ];

            $run->refresh();
            $bulk = $payslipService->generateBulkPayslips(
                $run->company_id ? (int) $run->company_id : null,
                $run->branch_id ? (int) $run->branch_id : null,
                $run->department_id ? (int) $run->department_id : null,
                null,
                $payload,
                $actor,
                withPdf: false,
                progressRun: $run,
            );
            $ids = $bulk['payslip_ids'];
            $sectionTimings = $bulk['timings'] ?? [];
            $sectionTimings['total_job_ms'] = round((microtime(true) - $jobStartedAt) * 1000, 2);
            $sectionTimings['query_count'] = $queryCount;

            PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_DRAFT,
                'completed_at' => now(),
                'employee_count' => count($ids),
                'total_employees' => count($ids),
                'processed_employees' => count($ids),
                'failed_employees' => 0,
                'error_message' => null,
            ]));

            $run->refresh();
            $payslipService->syncBatchRunTotals($run);

            Log::info('GeneratePayrollBatchJob completed', [
                'batch_run_id' => (int) $run->id,
                'payslip_count' => count($ids),
                'employee_count' => $sectionTimings['employee_count'] ?? count($ids),
                'attendance_rows_count' => $sectionTimings['attendance_rows_count'] ?? null,
                'pay_component_rows_count' => $sectionTimings['pay_component_rows_count'] ?? null,
                'query_count' => $queryCount,
                'total_net' => $run->total_net,
                'timings_ms' => $sectionTimings,
                'elapsed_ms' => $sectionTimings['total_job_ms'] ?? round((microtime(true) - $jobStartedAt) * 1000, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('GeneratePayrollBatchJob failed', [
                'batch_run_id' => (int) $run->id,
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);
            PayrollBatchRun::query()->whereKey($run->id)->update($this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_FAILED,
                'failed_employees' => max(1, $expectedTotal),
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]));

            throw $e;
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
