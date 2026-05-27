<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Short-lived cache for dashboard pending request counts (leave / OT / corrections).
 */
class DashboardPendingCountsCache
{
    public const TTL_SECONDS = 60;

    public static function keyForActor(User $actor): string
    {
        $companyId = (int) ($actor->company_id ?? 0);
        $role = (string) ($actor->role ?? 'user');

        return "dashboard:counts:{$companyId}:{$role}:{$actor->id}";
    }

    /**
     * @param  callable(): array{leave: int, overtime: int, attendance_correction: int, total: int}  $resolver
     * @return array{leave: int, overtime: int, attendance_correction: int, total: int}
     */
    public static function remember(User $actor, callable $resolver): array
    {
        $key = self::keyForActor($actor);

        try {
            return Cache::remember($key, now()->addSeconds(self::TTL_SECONDS), $resolver);
        } catch (\Throwable $e) {
            Log::warning('dashboard_pending_counts.cache_read_failed', [
                'actor_id' => $actor->id,
                'message' => $e->getMessage(),
            ]);

            return $resolver();
        }
    }

    public static function forgetForActor(?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        try {
            Cache::forget(self::keyForActor($actor));
        } catch (\Throwable $e) {
            Log::warning('dashboard_pending_counts.cache_forget_failed', [
                'actor_id' => $actor->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
