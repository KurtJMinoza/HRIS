<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestModuleCacheInvalidator
{
    public static function afterMutation(string $module, int $requestId, ?User $actor = null): void
    {
        $run = static function () use ($module, $requestId, $actor): void {
            ReviewRequestCache::forget($module, $requestId);
            DashboardPendingCountsCache::forgetForActor($actor);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }

    /**
     * @param  list<int>  $requestIds
     */
    public static function afterBulk(string $module, array $requestIds, ?User $actor = null): void
    {
        $run = static function () use ($module, $requestIds, $actor): void {
            ReviewRequestCache::forgetMany($module, $requestIds);
            DashboardPendingCountsCache::forgetForActor($actor);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }
}
