<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis-oriented cache for Admin Attendance module (list, detail, filters, counts, search).
 * Uses targeted invalidation instead of flushing entire admin attendance caches.
 */
class AdminAttendanceCacheService
{
    public const LIST_TTL = 45;

    public const DETAIL_TTL = 300;

    public const FILTERS_TTL = 600;

    public const SUMMARY_TTL = 600;

    public const COUNTS_TTL = 30;

    public const SEARCH_TTL = 60;

    public const EXPORT_STATUS_TTL = 3600;

    /**
     * @param  array<string, scalar|null>  $filters
     */
    public static function filtersHash(array $filters): string
    {
        ksort($filters);
        $payload = json_encode($filters, JSON_THROW_ON_ERROR);

        return substr(hash('xxh128', $payload), 0, 16);
    }

    /**
     * @param  array<string, scalar|null>  $parts
     */
    public static function listKey(array $parts): string
    {
        $companyId = self::segment($parts['company_id'] ?? 0);
        $branchId = self::segment($parts['branch_id'] ?? 0);
        $pageOrCursor = self::segment($parts['cursor'] ?? $parts['page'] ?? 1);
        $hash = self::filtersHash([
            'scope' => $parts['scope'] ?? null,
            'start_date' => $parts['start_date'] ?? null,
            'end_date' => $parts['end_date'] ?? null,
            'department_id' => $parts['department_id'] ?? null,
            'department' => $parts['department'] ?? null,
            'employee_id' => $parts['employee_id'] ?? null,
            'status' => $parts['status'] ?? null,
            'premium_type' => $parts['premium_type'] ?? null,
            'pending_attention' => $parts['pending_attention'] ?? null,
            'search' => $parts['search'] ?? null,
            'company' => $parts['company'] ?? null,
            'per_page' => $parts['per_page'] ?? null,
        ]);

        return sprintf(
            'attendance:list:company_%s:branch_%s:page_%s:hash_%s:v%d',
            $companyId,
            $branchId,
            $pageOrCursor,
            $hash,
            self::listVersion()
        );
    }

    public static function detailKey(string $attendanceId): string
    {
        return sprintf('attendance:detail:%s:v%d', self::sanitizeId($attendanceId), self::detailVersion());
    }

    public static function filtersKey(?int $companyId, ?int $branchId, int $scopeUserId): string
    {
        return sprintf(
            'attendance:filters:company_%s:branch_%s:scope_%d:v%d',
            self::segment($companyId),
            self::segment($branchId),
            $scopeUserId,
            self::filtersVersion()
        );
    }

    /**
     * @param  array<string, scalar|null>  $filters
     */
    public static function countsKey(array $filters): string
    {
        $companyId = self::segment($filters['company_id'] ?? 0);
        $branchId = self::segment($filters['branch_id'] ?? 0);
        $hash = self::filtersHash([
            'scope' => $filters['scope'] ?? null,
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
            'department_id' => $filters['department_id'] ?? null,
            'department' => $filters['department'] ?? null,
            'employee_id' => $filters['employee_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'premium_type' => $filters['premium_type'] ?? null,
            'pending_attention' => $filters['pending_attention'] ?? null,
            'search' => $filters['search'] ?? null,
            'company' => $filters['company'] ?? null,
        ]);

        return sprintf(
            'attendance:counts:company_%s:branch_%s:hash_%s:v%d',
            $companyId,
            $branchId,
            $hash,
            self::countsVersion()
        );
    }

    public static function summaryKey(int $employeeId, string $month): string
    {
        return sprintf(
            'attendance:summary:emp_%d:%s:v%d',
            $employeeId,
            str_replace('-', '_', $month),
            self::summaryVersion($employeeId)
        );
    }

    public static function searchKey(string $query, ?int $companyId, ?int $branchId, int $scopeUserId): string
    {
        $normalized = strtolower(trim($query));

        return sprintf(
            'attendance:search:%s:company_%s:branch_%s:scope_%d:v%d',
            substr(hash('xxh128', $normalized), 0, 16),
            self::segment($companyId),
            self::segment($branchId),
            $scopeUserId,
            self::searchVersion()
        );
    }

    public static function exportStatusKey(string $token): string
    {
        return 'attendance:export:status:'.$token;
    }

    /**
     * @return array{payload: mixed, cache_hit: bool}
     */
    public static function remember(string $key, int $ttlSeconds, callable $resolver): array
    {
        $repo = self::repository();

        try {
            $cached = $repo->get($key);
            if ($cached !== null) {
                return ['payload' => $cached, 'cache_hit' => true];
            }
        } catch (\Throwable $e) {
            Log::warning('admin_attendance.cache_read_failed', ['key' => $key, 'message' => $e->getMessage()]);
        }

        $payload = $resolver();

        try {
            $repo->put($key, $payload, max(15, $ttlSeconds));
        } catch (\Throwable $e) {
            Log::warning('admin_attendance.cache_write_failed', ['key' => $key, 'message' => $e->getMessage()]);
        }

        return ['payload' => $payload, 'cache_hit' => false];
    }

    public static function get(string $key): mixed
    {
        try {
            return self::repository()->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function put(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            self::repository()->put($key, $value, max(15, $ttlSeconds));
        } catch (\Throwable $e) {
            Log::warning('admin_attendance.cache_put_failed', ['key' => $key, 'message' => $e->getMessage()]);
        }
    }

    public static function forget(string $key): void
    {
        try {
            self::repository()->forget($key);
        } catch (\Throwable) {
            // no-op
        }
    }

    /**
     * Targeted invalidation for one employee/day mutation.
     */
    public static function invalidateAffected(?int $employeeId, ?string $date, ?int $companyId = null, ?int $branchId = null): void
    {
        $keys = [];
        if ($employeeId !== null && $employeeId > 0 && is_string($date) && $date !== '') {
            $keys[] = self::detailKey(self::attendanceId($employeeId, $date));
            $month = substr($date, 0, 7);
            $keys[] = self::summaryKey($employeeId, $month);
        }

        self::forgetMany($keys);
        self::bumpListVersion();
        self::bumpCountsVersion();
        self::bumpSearchVersion();

        if ($employeeId !== null && $employeeId > 0) {
            self::bumpSummaryVersion($employeeId);
            self::bumpDetailVersion();
        }

        AttendanceCacheService::bumpVersionsOnly($employeeId);

        unset($companyId, $branchId);
    }

    /**
     * @param  list<string>  $keys
     */
    public static function forgetMany(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys)));
        foreach ($keys as $key) {
            self::forget($key);
        }
    }

    public static function attendanceId(int $employeeId, string $date): string
    {
        return $employeeId.':'.$date;
    }

    /**
     * @return array{employee_id: int, date: string}|null
     */
    public static function parseAttendanceId(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/^ATT-(\d+)-(\d{8})$/i', $raw, $m)) {
            $date = substr($m[2], 0, 4).'-'.substr($m[2], 4, 2).'-'.substr($m[2], 6, 2);

            return ['employee_id' => (int) $m[1], 'date' => $date];
        }
        if (preg_match('/^(\d+):(\d{4}-\d{2}-\d{2})$/', $raw, $m)) {
            return ['employee_id' => (int) $m[1], 'date' => $m[2]];
        }
        if (preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})$/', $raw, $m)) {
            return ['employee_id' => (int) $m[1], 'date' => $m[2]];
        }

        return null;
    }

    private static function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9:_\-]/', '', $id) ?? $id;
    }

    private static function repository(): Repository
    {
        $store = config('cache.attendance_store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private static function segment(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return str_replace([':', ' '], '-', (string) $value);
    }

    private static function listVersion(): int
    {
        return self::version('attendance:version:list');
    }

    private static function detailVersion(): int
    {
        return self::version('attendance:version:detail');
    }

    private static function filtersVersion(): int
    {
        return self::version('attendance:version:filters');
    }

    private static function countsVersion(): int
    {
        return self::version('attendance:version:counts');
    }

    private static function searchVersion(): int
    {
        return self::version('attendance:version:search');
    }

    private static function summaryVersion(int $employeeId): int
    {
        return self::version('attendance:version:summary:'.$employeeId);
    }

    private static function bumpListVersion(): void
    {
        self::incrementVersion('attendance:version:list');
    }

    private static function bumpDetailVersion(): void
    {
        self::incrementVersion('attendance:version:detail');
    }

    private static function bumpCountsVersion(): void
    {
        self::incrementVersion('attendance:version:counts');
    }

    private static function bumpSearchVersion(): void
    {
        self::incrementVersion('attendance:version:search');
    }

    private static function bumpSummaryVersion(int $employeeId): void
    {
        self::incrementVersion('attendance:version:summary:'.$employeeId);
    }

    private static function version(string $key): int
    {
        try {
            return max(1, (int) self::repository()->get($key, 1));
        } catch (\Throwable) {
            return 1;
        }
    }

    private static function incrementVersion(string $key): void
    {
        try {
            $repo = self::repository();
            if (! $repo->has($key)) {
                $repo->forever($key, 1);
            }
            $repo->increment($key);
        } catch (\Throwable) {
            // no-op
        }
    }
}
