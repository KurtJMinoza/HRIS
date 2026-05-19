<?php

namespace App\Services;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Redis-friendly cache for Admin and Employee detailed reports.
 */
class ReportsCacheService
{
    /** @var list<int> */
    public const ALLOWED_PER_PAGE = [25, 50, 100];

    public const DEFAULT_PER_PAGE = 50;

    public const TABLE_TTL_SECONDS = 180;

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
            'reports:admin:v%d:%s:%s:%s:%s:%s:%s:%s:%s:%s:%s',
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
            substr(hash('xxh128', serialize([
                'scope' => $parts['scope'] ?? null,
                'department' => $parts['department'] ?? null,
                'leave_type' => $parts['leave_type'] ?? null,
                'overtime_status' => $parts['overtime_status'] ?? null,
                'search' => $parts['search'] ?? null,
                'include_deactivated' => $parts['include_deactivated'] ?? null,
            ]), false), 0, 16),
        );
    }

    /**
     * @param  array<string, scalar|null>  $parts
     */
    public static function employeeListKey(array $parts): string
    {
        $employeeId = (int) ($parts['employee_id'] ?? 0);
        $version = self::employeeVersion($employeeId);

        return sprintf(
            'reports:employee:v%d:%d:%s:%s:%s:%s:%s:%s',
            $version,
            $employeeId,
            self::segment($parts['start_date'] ?? null),
            self::segment($parts['end_date'] ?? null),
            self::segment($parts['status'] ?? null),
            self::segment($parts['page'] ?? 1),
            self::segment($parts['per_page'] ?? self::DEFAULT_PER_PAGE),
            substr(hash('xxh128', (string) ($parts['search'] ?? ''), false), 0, 16),
        );
    }

    public static function remember(string $key, int $ttlSeconds, callable $callback, ?int $employeeId = null): mixed
    {
        $repo = self::repository();
        $ttl = max(60, $ttlSeconds);

        if (self::supportsTags($repo)) {
            return $repo->tags(self::tagsFor($employeeId))->remember($key, $ttl, $callback);
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
            $repo->tags(self::tagsFor($employeeId))->put($key, $value, $ttl);

            return;
        }
        $repo->put($key, $value, $ttl);
    }

    public static function invalidate(?int $employeeId = null, ?string $date = null): void
    {
        self::bumpAdminVersion();
        if ($employeeId !== null && $employeeId > 0) {
            self::bumpEmployeeVersion($employeeId);
        }

        $repo = self::repository();
        if (! self::supportsTags($repo)) {
            unset($date);

            return;
        }

        $repo->tags(['reports', 'reports:admin'])->flush();
        if ($employeeId !== null && $employeeId > 0) {
            $repo->tags(['reports', 'reports:employee:'.$employeeId])->flush();
        }

        unset($date);
    }

    public static function invalidateAttendanceCache(?int $employeeId = null, ?string $date = null): void
    {
        self::invalidate($employeeId, $date);
    }

    private static function repository(): Repository
    {
        $store = config('cache.reports_store');
        if (is_string($store) && $store !== '') {
            return Cache::store($store);
        }

        $attendanceStore = config('cache.attendance_store');
        if (is_string($attendanceStore) && $attendanceStore !== '') {
            return Cache::store($attendanceStore);
        }

        return Cache::store();
    }

    private static function adminVersion(): int
    {
        return max(1, (int) self::repository()->get('reports:version:admin', 1));
    }

    private static function employeeVersion(int $employeeId): int
    {
        return max(1, (int) self::repository()->get('reports:version:employee:'.$employeeId, 1));
    }

    private static function bumpAdminVersion(): void
    {
        $repo = self::repository();
        $key = 'reports:version:admin';
        if (! $repo->has($key)) {
            $repo->forever($key, 1);
        }
        $repo->increment($key);
    }

    private static function bumpEmployeeVersion(int $employeeId): void
    {
        $repo = self::repository();
        $key = 'reports:version:employee:'.$employeeId;
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
        $tags = ['reports', 'reports:admin'];
        if ($employeeId !== null && $employeeId > 0) {
            $tags[] = 'reports:employee:'.$employeeId;
        }

        return $tags;
    }

    private static function supportsTags(Repository $repo): bool
    {
        return $repo->getStore() instanceof TaggableStore;
    }

    private static function segment(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '_';
        }

        return str_replace(':', '-', (string) $value);
    }
}
