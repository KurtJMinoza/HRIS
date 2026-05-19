<?php

namespace App\Jobs;

use App\Services\PayslipBulkDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupExpiredPayslipBulkDownloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $retentionDays = 7
    ) {}

    public function handle(PayslipBulkDownloadService $service): void
    {
        $removed = $service->cleanupExpiredDownloads($this->retentionDays);
        Log::info('CleanupExpiredPayslipBulkDownloadsJob completed', [
            'retention_days' => $this->retentionDays,
            'removed' => $removed,
        ]);
    }
}
