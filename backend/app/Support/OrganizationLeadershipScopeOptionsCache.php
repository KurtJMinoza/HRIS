<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class OrganizationLeadershipScopeOptionsCache
{
    private const VERSION_PREFIX = 'org_leadership_scope_opts:version:';

    private const TTL_SECONDS = 300;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $legacyType, int $legacyId, callable $callback): mixed
    {
        $versionKey = self::versionKey($legacyType, $legacyId);
        $version = (int) Cache::get($versionKey, 1);

        return Cache::remember(
            "org_leadership_scope_opts:{$legacyType}:{$legacyId}:v{$version}",
            now()->addSeconds(self::TTL_SECONDS),
            $callback,
        );
    }

    public static function flush(string $legacyType, int $legacyId): void
    {
        if ($legacyId <= 0) {
            return;
        }

        $versionKey = self::versionKey($legacyType, $legacyId);
        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);

            return;
        }

        Cache::increment($versionKey);
    }

    private static function versionKey(string $legacyType, int $legacyId): string
    {
        return self::VERSION_PREFIX."{$legacyType}:{$legacyId}";
    }
}
