<?php

namespace App\Services;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Redis-friendly cache for Admin and Employee attendance list/summary endpoints.
 *
 * Invalidation: {@see invalidate()} bumps version keys and flushes tags when supported.
 */
class AttendanceCacheService
{
    /** @var list<int> */
    public const ALLOWED_PER_PAGE = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 50;

    /** Filtered table payloads (rows + pagination). */
    public const TABLE_TTL_SECONDS = 180;

    /** Summary rollups / KPI blocks when cached separately. */
    public const SUMMARY_TTL_SECONDS = 600;

    public static function normalizePerPage(mixed $perPage, int $default = self::DEFAULT_PER_PAGE): int
    {
        $n = is_numeric($perPage) ? (int) $perPage : 0;
        if (in_array($n, self::ALLOWED_PER_PAGE, true)) {
            return $n;
        }

        return $default;
    }

    /**
     * @param  array<string, scalar|null>  $parts
     */
    public static function adminListKey(array $parts): string
    {
        $version = self::adminVersion();

        return sprintf(
            'attendance:admin:v%d:%s:%s:%s:%s:%s:%s:%s:%s:%s:%s:%s:%s:%s',
            $version,
            self::segment($parts['company_id'] ?? null),
            self::segment($parts['branch_id'] ?? null),
            self::segment($parts['department_id'] ?? null),
            self::segment($parts['employee_id'] ?? null),
            self::segment($parts['start_date'] ?? null),
            self::segment($parts['end_date'] ?? null),
            self::segment($parts['status'] ?? null),
            self::segment($parts['page'] ?? 1),
            self::segment($parts['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::segment($parts['scope'] ?? null),
            self::segment($parts['premium_type'] ?? null),
            self::segment($parts['pending_attention'] ?? null),
            substr(hash('xxh128', (string) ($parts['search'] ?? '').'|'.(string) ($parts['company'] ?? '').'|'.(string) ($parts['department'] ?? '')), 0, 16),
        );
    }

    /**
     * @param  array<string, scalar|null>  $parts
     */
    public static function adminSummaryKey(array $parts): string
    {
        $version = self::adminVersion();
        unset($parts['page'], $parts['per_page']);

        return self::adminListKey($parts).':summary:v'.$version;
    }

    /**
     * @param  array<string, scalar|null>  $parts
     */
    public static function employeeListKey(array $parts): string
    {
        $employeeId = (int) ($parts['employee_id'] ?? 0);
        $version = self::employeeVersion($employeeId);

        return sprintf(
            'attendance:employee:v%d:%d:%s:%s:%s:%s:%s:%s',
            $version,
            $employeeId,
            self::segment($parts['start_date'] ?? null),
            self::segment($parts['end_date'] ?? null),
            self::segment($parts['status'] ?? 'all'),
            self::segment($parts['page'] ?? 1),
            self::segment($parts['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::segment($parts['dashboard_lite'] ?? null),
        );
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback, ?int $employeeId = null): mixed
    {
        $repo = self::repository();
        $ttl = max(60, $ttlSeconds);

        if (self::supportsTags($repo)) {
            return self::tagged($repo, self::tagsFor($employeeId))->remember($key, $ttl, $callback);
        }

        return $repo->remember($key, $ttl, $callback);
    }

    public static function get(string $key): mixed
    {
        return self::repository()->get($key);
    }

    public static function put(string $key, mixed $value, int $ttlSeconds, ?int $employeeId = null): void
    {
        $repo = self::repository();
        $ttl = max(60, $ttlSeconds);
        if (self::supportsTags($repo)) {
            self::tagged($repo, self::tagsFor($employeeId))->put($key, $value, $ttl);

            return;
        }
        $repo->put($key, $value, $ttl);
    }

    /**
     * Invalidate attendance list caches for one employee and/or admin views touching a date.
     */
    public static function invalidate(?int $employeeId = null, ?string $date = null): void
    {
        self::bumpAdminVersion();
        if ($employeeId !== null && $employeeId > 0) {
            self::bumpEmployeeVersion($employeeId);
        }

        $repo = self::repository();
        if (self::supportsTags($repo)) {
            self::tagged($repo, ['attendance', 'attendance:admin'])->flush();
            if ($employeeId !== null && $employeeId > 0) {
                self::tagged($repo, ['attendance', 'attendance:employee:'.$employeeId])->flush();
            }
        }

        ReportsCacheService::invalidate($employeeId, $date);

        unset($date);
    }

    public static function invalidateForUserId(int $userId, ?string $date = null): void
    {
        self::invalidate($userId, $date);
    }

    /** @alias Central invalidation entry point for attendance modules. */
    public static function invalidateAttendanceCache(?int $employeeId = null, ?string $date = null): void
    {
        self::invalidate($employeeId, $date);
    }

    /**
     * Bump list/summary version keys without tag flush or report invalidation.
     * Used when a sibling cache layer already performed targeted key invalidation.
     */
    public static function bumpVersionsOnly(?int $employeeId = null): void
    {
        self::bumpAdminVersion();
        if ($employeeId !== null && $employeeId > 0) {
            self::bumpEmployeeVersion($employeeId);
        }
    }

    /**
     * @param  array<int, array{employee_id: int, date: string}>  $affectedRows
     */
    public static function invalidateMany(array $affectedRows): void
    {
        $pairs = [];
        foreach ($affectedRows as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            $date = trim((string) ($row['date'] ?? ''));
            if ($employeeId > 0 && $date !== '') {
                $pairs[$employeeId.'|'.$date] = ['employee_id' => $employeeId, 'date' => $date];
            }
        }
        if ($pairs === []) {
            return;
        }

        self::bumpAdminVersion();
        $employeeIds = array_values(array_unique(array_column($pairs, 'employee_id')));
        foreach ($employeeIds as $employeeId) {
            self::bumpEmployeeVersion((int) $employeeId);
        }

        $repo = self::repository();
        if (self::supportsTags($repo)) {
            self::tagged($repo, ['attendance', 'attendance:admin'])->flush();
            foreach ($employeeIds as $employeeId) {
                self::tagged($repo, ['attendance', 'attendance:employee:'.(int) $employeeId])->flush();
            }
        }

        foreach ($pairs as $pair) {
            ReportsCacheService::invalidate((int) $pair['employee_id'], (string) $pair['date']);
        }
    }

    private static function repository(): Repository
    {
        $store = config('cache.attendance_store');
        if (is_string($store) && $store !== '') {
            return Cache::store($store);
        }

        return Cache::store();
    }

    private static function adminVersion(): int
    {
        return max(1, (int) self::repository()->get('attendance:version:admin', 1));
    }

    private static function employeeVersion(int $employeeId): int
    {
        return max(1, (int) self::repository()->get('attendance:version:employee:'.$employeeId, 1));
    }

    private static function bumpAdminVersion(): void
    {
        $repo = self::repository();
        $key = 'attendance:version:admin';
        if (! $repo->has($key)) {
            $repo->forever($key, 1);
        }
        $repo->increment($key);
    }

    private static function bumpEmployeeVersion(int $employeeId): void
    {
        $repo = self::repository();
        $key = 'attendance:version:employee:'.$employeeId;
        if (! $repo->has($key)) {
            $repo->forever($key, 1);
        }
        $repo->increment($key);
    }

    /**
     * @return array<int, string>
     */
    private static function tagsFor(?int $employeeId): array
    {
        $tags = ['attendance', 'attendance:admin'];
        if ($employeeId !== null && $employeeId > 0) {
            $tags[] = 'attendance:employee:'.$employeeId;
        }

        return $tags;
    }

    private static function supportsTags(Repository $repo): bool
    {
        return $repo->getStore() instanceof TaggableStore;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function tagged(Repository $repo, array $tags): CacheRepository
    {
        assert($repo instanceof CacheRepository);

        return $repo->tags($tags);
    }

    private static function segment(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '_';
        }

        return str_replace(':', '-', (string) $value);
    }
}
