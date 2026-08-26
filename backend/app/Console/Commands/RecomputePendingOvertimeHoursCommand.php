<?php

namespace App\Console\Commands;

use App\Services\OvertimeService;
use App\Support\OvertimeModuleCache;
use Illuminate\Console\Command;

class RecomputePendingOvertimeHoursCommand extends Command
{
    protected $signature = 'overtime:recompute-pending-hours
        {--user= : Limit to one employee user id}
        {--id= : Recompute a single overtime request id}';

    protected $description = 'Recompute pending overtime hours excluding unpaid schedule breaks.';

    public function handle(OvertimeService $overtimeService): int
    {
        $userId = $this->option('user');
        $onlyUserId = ($userId !== null && $userId !== '') ? (int) $userId : null;
        $overtimeId = $this->option('id');
        $onlyOvertimeId = ($overtimeId !== null && $overtimeId !== '') ? (int) $overtimeId : null;

        $result = $overtimeService->syncPendingOvertimeQuantities($onlyUserId, $onlyOvertimeId);
        OvertimeModuleCache::flush();

        $this->info(sprintf(
            'Pending overtime recompute complete: scanned=%d updated=%d skipped=%d',
            $result['scanned'],
            $result['updated'],
            $result['skipped'],
        ));

        return 0;
    }
}
