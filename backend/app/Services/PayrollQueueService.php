<?php

namespace App\Services;

use App\Jobs\FinalizePayrollJob;
use App\Jobs\GeneratePayrollBatchJob;
use App\Jobs\GeneratePayslipsJob;
use App\Models\PayrollBatchRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Push payroll jobs directly onto the Redis queue (bypasses Bus deferrals that can fail under Octane).
 */
class PayrollQueueService
{
    public const STALE_QUEUED_SECONDS = 8;

    public function releaseOverlapLocks(int $batchRunId): void
    {
        $lockKeys = [
            'laravel-queue-overlap:'.GeneratePayrollBatchJob::class.':generate-payroll-batch-'.$batchRunId,
            'laravel-queue-overlap:'.FinalizePayrollJob::class.':finalize-payroll-'.$batchRunId,
            'laravel-queue-overlap:'.GeneratePayslipsJob::class.':generate-payslip-pdfs-'.$batchRunId,
        ];

        foreach ($lockKeys as $lockKey) {
            try {
                Cache::lock($lockKey)->forceRelease();
            } catch (Throwable) {
                // no-op
            }
        }
    }

    public function dispatchGeneratePayrollBatch(int $batchRunId, int $actorUserId): void
    {
        $this->releaseOverlapLocks($batchRunId);

        Queue::connection('redis')->pushOn(
            'payroll',
            new GeneratePayrollBatchJob($batchRunId, $actorUserId > 0 ? $actorUserId : null)
        );

        Log::info('PayrollQueueService: GeneratePayrollBatchJob pushed to redis:payroll', [
            'batch_run_id' => $batchRunId,
            'actor_user_id' => $actorUserId,
            'pending_on_payroll_queue' => Queue::connection('redis')->pendingSize('payroll'),
        ]);
    }

    public function dispatchFinalizePayroll(int $batchRunId, int $actorUserId): void
    {
        $this->releaseOverlapLocks($batchRunId);

        Queue::connection('redis')->pushOn(
            'payroll',
            new FinalizePayrollJob($batchRunId, $actorUserId > 0 ? $actorUserId : null)
        );

        Log::info('PayrollQueueService: FinalizePayrollJob pushed to redis:payroll', [
            'batch_run_id' => $batchRunId,
            'actor_user_id' => $actorUserId,
            'pending_on_payroll_queue' => Queue::connection('redis')->pendingSize('payroll'),
        ]);
    }

    public function dispatchGeneratePayslips(int $batchRunId, int $actorUserId): void
    {
        $this->releaseOverlapLocks($batchRunId);

        Queue::connection('redis')->pushOn(
            'payslip-pdf',
            new GeneratePayslipsJob($batchRunId, $actorUserId > 0 ? $actorUserId : null)
        );

        Log::info('PayrollQueueService: GeneratePayslipsJob pushed to redis:payslip-pdf', [
            'batch_run_id' => $batchRunId,
            'actor_user_id' => $actorUserId,
            'pending_on_payslip_pdf_queue' => Queue::connection('redis')->pendingSize('payslip-pdf'),
        ]);
    }

    /**
     * Re-push the correct payroll job when a batch row is queued but no worker picked it up.
     */
    public function recoverStuckQueuedRun(PayrollBatchRun $run, ?int $actorUserId = null, bool $force = false): bool
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_QUEUED || $run->started_at !== null) {
            return false;
        }

        $hasDraftPayslips = \App\Models\Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('period_slot', 0)
            ->whereNull('voided_at')
            ->exists();

        return $hasDraftPayslips
            ? $this->recoverStuckFinalizeBatch($run, $actorUserId, $force)
            : $this->recoverStuckQueuedBatch($run, $actorUserId, $force);
    }

    /**
     * Re-push draft generation when a batch row is queued but no worker ever picked it up.
     */
    public function recoverStuckQueuedBatch(PayrollBatchRun $run, ?int $actorUserId = null, bool $force = false): bool
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_QUEUED || $run->started_at !== null) {
            return false;
        }

        $queuedAt = $run->queued_at;
        if (
            ! $force
            && $queuedAt !== null
            && $queuedAt->gt(now()->subSeconds(self::STALE_QUEUED_SECONDS))
        ) {
            return false;
        }

        $actorId = (int) ($actorUserId ?? $run->finalized_by_user_id ?? 0);
        Log::warning('PayrollQueueService: recovering stuck queued payroll batch', [
            'batch_run_id' => (int) $run->id,
            'queued_at' => $queuedAt?->toIso8601String(),
            'actor_user_id' => $actorId > 0 ? $actorId : null,
        ]);

        $this->dispatchGeneratePayrollBatch((int) $run->id, $actorId);

        return true;
    }

    /**
     * Re-push finalize when a batch row is queued but finalize never started.
     */
    public function recoverStuckFinalizeBatch(PayrollBatchRun $run, ?int $actorUserId = null, bool $force = false): bool
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_QUEUED || $run->started_at !== null) {
            return false;
        }

        $queuedAt = $run->queued_at;
        if (
            ! $force
            && $queuedAt !== null
            && $queuedAt->gt(now()->subSeconds(self::STALE_QUEUED_SECONDS))
        ) {
            return false;
        }

        $actorId = (int) ($actorUserId ?? $run->finalized_by_user_id ?? 0);
        Log::warning('PayrollQueueService: recovering stuck queued finalize batch', [
            'batch_run_id' => (int) $run->id,
            'queued_at' => $queuedAt?->toIso8601String(),
            'actor_user_id' => $actorId > 0 ? $actorId : null,
        ]);

        $this->dispatchFinalizePayroll((int) $run->id, $actorId);

        return true;
    }
}
