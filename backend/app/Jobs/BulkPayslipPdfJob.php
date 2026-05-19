<?php

namespace App\Jobs;

use App\Models\PayslipBulkDownload;
use App\Services\PayslipBulkDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a bulk payslip ZIP on the payslip-pdf queue (never payroll).
 *
 *   php artisan queue:work redis --queue=payslip-pdf --timeout=300 --sleep=1 --tries=1
 */
class BulkPayslipPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        private readonly int $bulkDownloadId
    ) {
        $this->onConnection('redis');
        $this->onQueue('payslip-pdf');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bulk-payslip-pdf-'.$this->bulkDownloadId))->expireAfter(600)];
    }

    public function handle(PayslipBulkDownloadService $service): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('max_execution_time', '0');

        $download = PayslipBulkDownload::query()->find($this->bulkDownloadId);
        if (! $download) {
            Log::warning('BulkPayslipPdfJob skipped: download record missing', [
                'bulk_download_id' => $this->bulkDownloadId,
            ]);

            return;
        }

        if ($download->isTerminal()) {
            Log::info('BulkPayslipPdfJob skipped: download already terminal', [
                'bulk_download_id' => (int) $download->id,
                'status' => (string) $download->status,
            ]);

            return;
        }

        Log::info('BulkPayslipPdfJob started', [
            'bulk_download_id' => (int) $download->id,
            'payroll_batch_run_id' => (int) $download->payroll_batch_run_id,
        ]);

        try {
            $service->processDownload($download);
        } catch (Throwable $e) {
            report($e);
            $service->markFailed($download, $e->getMessage());
            Log::error('BulkPayslipPdfJob failed', [
                'bulk_download_id' => (int) $download->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
