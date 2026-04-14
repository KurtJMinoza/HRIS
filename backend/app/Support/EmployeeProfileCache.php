<?php

namespace App\Support;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned cache for employee profile / auth user payloads.
 *
 * Uses the dedicated {@see config('cache.profile_store')} store so local dev can set
 * CACHE_PROFILE_DRIVER=array and avoid Windows file-cache lock storms (Filesystem.php timeouts).
 */
class EmployeeProfileCache
{
    private const VERSION_PREFIX = 'employee:profile:version:';

    private static function repository(): Repository
    {
        $name = (string) config('cache.profile_store', 'array');

        return Cache::store($name);
    }

    public static function profileKey(int $employeeId, string $section, array $parts = []): string
    {
        $version = self::version($employeeId);
        $serializedParts = $parts === [] ? 'base' : md5(json_encode($parts));

        return sprintf('employee:profile:%d:v%d:%s:%s', $employeeId, $version, $section, $serializedParts);
    }

    public static function remember(int $employeeId, string $section, array $parts, \DateTimeInterface|\DateInterval|int $ttl, callable $callback): mixed
    {
        $key = self::profileKey($employeeId, $section, $parts);
        $repo = self::repository();

        if (self::supportsTags($repo)) {
            return $repo->tags(self::tags($employeeId))->remember($key, $ttl, $callback);
        }

        return $repo->remember($key, $ttl, $callback);
    }

    public static function version(int $employeeId): int
    {
        return max(1, (int) self::repository()->get(self::versionKey($employeeId), 1));
    }

    public static function invalidate(int $employeeId): void
    {
        $repo = self::repository();
        $versionKey = self::versionKey($employeeId);
        if (! $repo->has($versionKey)) {
            $repo->forever($versionKey, 1);
        }
        $repo->increment($versionKey);

        if (self::supportsTags($repo)) {
            $repo->tags(self::tags($employeeId))->flush();
        }
    }

    public static function forgetForUser(int $employeeId): void
    {
        self::invalidate($employeeId);
    }

    /**
     * @return array<int, string>
     */
    private static function tags(int $employeeId): array
    {
        return ['employee:profile', 'employee:profile:'.$employeeId];
    }

    private static function versionKey(int $employeeId): string
    {
        return self::VERSION_PREFIX.$employeeId;
    }

    private static function supportsTags(Repository $repo): bool
    {
        return $repo->getStore() instanceof TaggableStore;
    }
}
