<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Redis-friendly cache keys for Employee Dashboard split endpoints.
 * Keys are tracked per employee so updates can invalidate dashboard slices quickly.
 */
class EmployeeDashboardCacheService
{
    public const SUMMARY_TTL = 60;
    public const CALENDAR_TTL = 120;
    public const RECENT_TTL = 90;

    public static function summaryKey(int $employeeId, string $date): string
    {
        return sprintf('employee_dashboard:summary:%d:%s', $employeeId, $date);
    }

    public static function calendarKey(int $employeeId, string $yearMonth): string
    {
        return sprintf('employee_dashboard:attendance_calendar:%d:%s', $employeeId, $yearMonth);
    }

    public static function recentRequestsKey(int $employeeId): string
    {
        return sprintf('employee_dashboard:recent_requests:%d', $employeeId);
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback, int $employeeId): mixed
    {
        $repo = self::repository();
        $ttl = max(30, $ttlSeconds);

        self::trackKey($employeeId, $key);

        return $repo->remember($key, $ttl, $callback);
    }

    public static function get(string $key): mixed
    {
        return self::repository()->get($key);
    }

    public static function put(string $key, mixed $value, int $ttlSeconds, int $employeeId): void
    {
        $repo = self::repository();
        $ttl = max(30, $ttlSeconds);

        $repo->put($key, $value, $ttl);
        self::trackKey($employeeId, $key);
    }

    public static function invalidate(int $employeeId): void
    {
        $repo = self::repository();
        $indexKey = self::indexKey($employeeId);
        $keys = $repo->get($indexKey, []);

        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key) && $key !== '') {
                    $repo->forget($key);
                }
            }
        }

        $repo->forget($indexKey);
    }

    public static function invalidateAll(): void
    {
        $repo = self::repository();
        $keys = $repo->get(self::globalIndexKey(), []);

        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key) && $key !== '') {
                    $repo->forget($key);
                }
            }
        }

        $repo->forget(self::globalIndexKey());
    }

    private static function repository(): Repository
    {
        $store = config('cache.attendance_store');
        if (is_string($store) && $store !== '') {
            return Cache::store($store);
        }

        return Cache::store();
    }

    private static function indexKey(int $employeeId): string
    {
        return 'employee_dashboard:keys:'.$employeeId;
    }

    private static function globalIndexKey(): string
    {
        return 'employee_dashboard:keys:all';
    }

    private static function trackKey(int $employeeId, string $key): void
    {
        $repo = self::repository();
        self::appendTrackedKey($repo, self::indexKey($employeeId), $key);
        self::appendTrackedKey($repo, self::globalIndexKey(), $key);
    }

    private static function appendTrackedKey(Repository $repo, string $indexKey, string $key): void
    {
        $keys = $repo->get($indexKey, []);
        if (! is_array($keys)) {
            $keys = [];
        }
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            $repo->forever($indexKey, $keys);
        }
    }
}
