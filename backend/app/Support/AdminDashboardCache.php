<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminDashboardCache
{
    public const TTL_SUMMARY = 90;

    public const TTL_ATTENDANCE = 60;

    public const TTL_REQUESTS = 60;

    public const TTL_PAYROLL = 120;

    public const TTL_CHARTS = 90;

    public const CHARTS_CACHE_VERSION = 2;

    public const TTL_RECENT = 60;

    /** @var list<string> */
    public const SEGMENTS = [
        'summary',
        'attendance',
        'requests',
        'payroll',
        'charts',
        'recent',
    ];

    public static function ttlForSegment(string $segment): int
    {
        return match ($segment) {
            'summary' => self::TTL_SUMMARY,
            'attendance' => self::TTL_ATTENDANCE,
            'requests', 'pending' => self::TTL_REQUESTS,
            'payroll' => self::TTL_PAYROLL,
            'charts' => self::TTL_CHARTS,
            'recent' => self::TTL_RECENT,
            default => 60,
        };
    }

    public static function key(User $actor, string $segment, string $periodKey): string
    {
        $userId = (int) $actor->id;
        $companyId = (int) ($actor->getEffectiveCompanyId() ?? $actor->company_id ?? 0);
        $version = self::segmentVersion($companyId, $segment);
        $codeVersion = $segment === 'charts' ? self::CHARTS_CACHE_VERSION : 0;

        return sprintf(
            'admin_dashboard:%s:%d:%d:%s:v%d_c%d',
            $segment,
            $userId,
            $companyId,
            $periodKey,
            $version,
            $codeVersion,
        );
    }

    public static function attendanceSummaryKey(int $companyId, string $date): string
    {
        return sprintf(
            'attendance_summary:%d:%s:v%d',
            $companyId,
            $date,
            self::segmentVersion($companyId, 'attendance'),
        );
    }

    public static function chartKey(int $companyId, string $chartType, string $range): string
    {
        return sprintf(
            'dashboard_chart:%d:%s:%s:v%d',
            $companyId,
            $chartType,
            $range,
            self::segmentVersion($companyId, 'charts'),
        );
    }

    public static function segmentVersion(int $companyId, string $segment): int
    {
        try {
            $companyVersion = (int) Cache::get(self::versionKey($companyId, $segment), 1);
            if ($companyId <= 0) {
                return max(1, $companyVersion);
            }

            // Company-specific keys also inherit the global epoch. This makes
            // flush() invalidate every admin dashboard, including scoped admins.
            $globalVersion = (int) Cache::get(self::versionKey(0, $segment), 1);

            return max(1, $companyVersion + $globalVersion - 1);
        } catch (\Throwable) {
            return 1;
        }
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
        $ttl = now()->addSeconds($ttlSeconds ?? self::ttlForSegment($segment));

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

    /**
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return array{payload: T, cache_hit: bool, cache_key: string}
     */
    public static function rememberRaw(string $key, int $ttlSeconds, callable $resolver): array
    {
        $ttl = now()->addSeconds($ttlSeconds);

        try {
            $hit = Cache::has($key);
            $payload = Cache::remember($key, $ttl, $resolver);

            return [
                'payload' => $payload,
                'cache_hit' => $hit,
                'cache_key' => $key,
            ];
        } catch (\Throwable $e) {
            Log::warning('admin_dashboard.cache_failed', [
                'cache_key' => $key,
                'message' => $e->getMessage(),
            ]);

            return [
                'payload' => $resolver(),
                'cache_hit' => false,
                'cache_key' => $key,
            ];
        }
    }

    /**
     * @param  list<string>  $segments
     */
    public static function invalidateCompany(int $companyId, array $segments): void
    {
        foreach ($segments as $segment) {
            self::bumpVersion($companyId, $segment);
        }
    }

    /**
     * @param  list<string>  $segments
     */
    public static function invalidateForUserCompany(?int $companyId, array $segments): void
    {
        if ($companyId === null || $companyId <= 0) {
            self::flush();

            return;
        }

        self::invalidateCompany($companyId, $segments);
    }

    /**
     * @param  list<string>  $segments
     */
    public static function invalidateForModel(?int $companyId, ?int $userId, array $segments): void
    {
        self::invalidateForUserCompany($companyId, $segments);

        if ($userId !== null && $userId > 0) {
            foreach ($segments as $segment) {
                self::bumpVersion(0, $segment.'_user_'.$userId);
            }
        }
    }

    public static function flush(): void
    {
        foreach (self::SEGMENTS as $segment) {
            self::bumpVersion(0, $segment);
        }
    }

    private static function versionKey(int $companyId, string $segment): string
    {
        return "admin_dashboard:version:{$companyId}:{$segment}";
    }

    private static function bumpVersion(int $companyId, string $segment): void
    {
        $key = self::versionKey($companyId, $segment);

        try {
            if (! Cache::has($key)) {
                Cache::forever($key, 1);
            }
            Cache::increment($key);
        } catch (\Throwable $e) {
            Log::warning('admin_dashboard.cache_flush_failed', [
                'company_id' => $companyId,
                'segment' => $segment,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
