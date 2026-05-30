<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminDashboardCache
{
    public const TTL_SECONDS = 60;

    private const VERSION_KEY = 'admin_dashboard:version';

    public static function version(): int
    {
        try {
            return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    public static function key(User $actor, string $segment, string $periodKey): string
    {
        $companyId = (int) ($actor->getEffectiveCompanyId() ?? $actor->company_id ?? 0);
        $scope = implode('-', array_filter([
            'u'.(int) $actor->id,
            (string) ($actor->hr_role ?? ''),
            (string) ($actor->role ?? ''),
        ]));

        return sprintf(
            'admin_dashboard:%s:%d:%s:%s:v%d',
            $segment,
            $companyId,
            $periodKey,
            $scope !== '' ? $scope : 'global',
            self::version(),
        );
    }

    /**
     * @template T of array
     *
     * @param  callable(): T  $resolver
     * @return array{payload: T, cache_hit: bool, cache_key: string}
     */
    public static function remember(User $actor, string $segment, string $periodKey, callable $resolver, ?int $ttlSeconds = null): array
    {
        $key = self::key($actor, $segment, $periodKey);
        $ttl = now()->addSeconds($ttlSeconds ?? self::TTL_SECONDS);

        try {
            $hit = Cache::has($key);
            $payload = Cache::remember($key, $ttl, $resolver);

            return [
                'payload' => is_array($payload) ? $payload : [],
                'cache_hit' => $hit,
                'cache_key' => $key,
            ];
        } catch (\Throwable $e) {
            Log::warning('admin_dashboard.cache_failed', [
                'segment' => $segment,
                'actor_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'payload' => $resolver(),
                'cache_hit' => false,
                'cache_key' => $key,
            ];
        }
    }

    public static function flush(): void
    {
        try {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        } catch (\Throwable $e) {
            Log::warning('admin_dashboard.cache_flush_failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
