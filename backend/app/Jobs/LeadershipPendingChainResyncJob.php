<?php

namespace App\Jobs;

use App\Services\OrgApprovalWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LeadershipPendingChainResyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  list<string>  $requestTypes
     */
    public function __construct(
        private readonly array $requestTypes = [
            'leave',
            'overtime',
            'attendance_correction',
            'change_schedule',
        ],
        private readonly ?string $legacyType = null,
        private readonly ?int $legacyId = null,
    ) {
        // Reuse an existing PM2 redis worker (default queue has none).
        $this->onConnection('redis');
        $this->onQueue('emails');
    }

    public function handle(OrgApprovalWorkflowService $approvalWorkflowService): void
    {
        $approvalWorkflowService->resyncPendingRequestChains(
            $this->requestTypes,
            $this->legacyType,
            $this->legacyId,
        );
    }
}
