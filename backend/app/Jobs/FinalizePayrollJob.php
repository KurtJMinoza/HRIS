<?php

namespace App\Jobs;

use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Services\FinalizePayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persists finalized payroll (periods, payslip PDFs, locks, audits) for a {@see PayrollBatchRun}.
 *
 * Dispatched from {@see \App\Http\Controllers\Admin\PayrollFinalizeController::execute} after the HTTP
 * handler returns 202 — never run this work synchronously in the request (60s PHP limits, DB locks).
 *
 * Run a dedicated Redis worker:
 *
 *   php artisan queue:work redis --queue=payroll --timeout=300 --sleep=1 --tries=1
 *
 * Set REDIS_QUEUE_RETRY_AFTER higher than this job's {@see $timeout}.
 */
class FinalizePayrollJob implements ShouldQueue
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
        return [(new WithoutOverlapping('finalize-payroll-'.$this->batchRunId))->expireAfter(600)];
    }

    public function handle(FinalizePayrollService $finalizePayrollService): void
    {
        // HTTP requests use max_execution_time (e.g. 60s); this job runs in a queue worker and must
        // not inherit that ceiling for PDF generation, cache, and bulk DB work.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $jobStartedAt = microtime(true);
        Log::info('FinalizePayrollJob started', [
            'batch_run_id' => $this->batchRunId,
            'actor_user_id' => $this->actorUserId,
            'queue_timeout_seconds' => $this->timeout,
        ]);

        $run = PayrollBatchRun::query()->find($this->batchRunId);
        if (! $run) {
            Log::warning('FinalizePayrollJob skipped: payroll batch run missing', ['batch_run_id' => $this->batchRunId]);

            return;
        }

        if ($run->status === PayrollBatchRun::STATUS_FINALIZED) {
            Log::info('FinalizePayrollJob skipped: already finalized', [
                'batch_run_id' => $this->batchRunId,
                'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);

            return;
        }

        $actor = $this->actorUserId ? User::query()->find($this->actorUserId) : null;

        if ($run->status === PayrollBatchRun::STATUS_FAILED) {
            $finalizePayrollService->recoverLocksAfterFailedBatchRun($run, $actor);
        }

        $run->update($this->filterBatchRunPayload([
            'status' => PayrollBatchRun::STATUS_PROCESSING,
            'started_at' => now(),
            'processed_employees' => 0,
            'failed_employees' => 0,
            'total_employees' => max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0)),
            'error_message' => null,
        ]));

        Log::info('FinalizePayrollJob marked processing', [
            'batch_run_id' => $this->batchRunId,
            'elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
        ]);

        try {
            $finalizeStartedAt = microtime(true);
            $finalizeResult = $finalizePayrollService->finalizeQueuedRun($run->fresh(), $actor);
            GeneratePayslipsJob::dispatch((int) $run->id, (int) $this->actorUserId)
                ->onConnection('redis')
                ->onQueue('payslip-pdf');
            Log::info('FinalizePayrollJob completed', [
                'batch_run_id' => $this->batchRunId,
                'finalize_core_ms' => round((microtime(true) - $finalizeStartedAt) * 1000, 2),
                'finalize_timings_ms' => $finalizeResult['timings'] ?? null,
                'total_elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('FinalizePayrollJob failed', [
                'batch_run_id' => $this->batchRunId,
                'message' => $e->getMessage(),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'total_elapsed_ms' => round((microtime(true) - $jobStartedAt) * 1000, 2),
            ]);
            PayrollBatchRun::query()->whereKey($this->batchRunId)->update($this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]));
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('FinalizePayrollJob failed handler', [
            'batch_run_id' => $this->batchRunId,
            'message' => $e?->getMessage(),
        ]);
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
