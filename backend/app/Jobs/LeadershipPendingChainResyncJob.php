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

    /**
     * @param  list<string>  $requestTypes
     */
    public function __construct(
        private readonly array $requestTypes = ['leave', 'overtime'],
    ) {}

    public function handle(OrgApprovalWorkflowService $approvalWorkflowService): void
    {
        $approvalWorkflowService->resyncPendingRequestChains($this->requestTypes);
    }
}
