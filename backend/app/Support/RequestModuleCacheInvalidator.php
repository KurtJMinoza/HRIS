<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
            if ($actor !== null) {
                $companyId = (int) ($actor->getEffectiveCompanyId() ?? $actor->company_id ?? 0);
                if ($companyId > 0) {
                    AdminDashboardCache::invalidateForUserCompany(
                        $companyId,
                        ['requests', 'summary', 'attendance'],
                    );
                }
                Cache::forget('sidebar:user:'.(int) $actor->id);
                Cache::forget('notification_counts:user:'.(int) $actor->id);
                Cache::forget('notification_module_counts:user:'.(int) $actor->id);
                Cache::forget('pending_approvals:user:'.(int) $actor->id);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }
}
