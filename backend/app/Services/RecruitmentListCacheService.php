<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class RecruitmentListCacheService
{
    private const TAB_VERSION_PREFIX = 'recruitment:list:version:';

    private const GLOBAL_VERSION_KEY = 'recruitment:list:version';

    public function listTtlSeconds(): int
    {
        return 120;
    }

    public function cacheKey(string $tab, int $userId, int $page, string $filtersHash, ?string $tabVersion = null): string
    {
        $version = $tabVersion ?? (string) $this->versionForTab($tab);

        return sprintf('recruitment:list:%s:%d:%d:%s:v%s', $tab, $userId, $page, $filtersHash, $version);
    }

    public function versionForTab(string $tab): int
    {
        $global = max(1, (int) Cache::get(self::GLOBAL_VERSION_KEY, 1));
        $tabVersion = (int) Cache::get(self::TAB_VERSION_PREFIX.$tab, 0);

        return max(1, $global + $tabVersion);
    }

    /**
     * @param  list<string>  $tabs
     */
    public function bumpTabs(array $tabs): void
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (string $tab): string => trim(str_replace('-', '_', strtolower($tab))),
            $tabs,
        ))));

        foreach ($normalized as $tab) {
            if (! Cache::has(self::TAB_VERSION_PREFIX.$tab)) {
                Cache::forever(self::TAB_VERSION_PREFIX.$tab, 0);
            }
            Cache::increment(self::TAB_VERSION_PREFIX.$tab);
        }
    }

    public function bumpAll(): void
    {
        if (! Cache::has(self::GLOBAL_VERSION_KEY)) {
            Cache::forever(self::GLOBAL_VERSION_KEY, 1);
        }
        Cache::increment(self::GLOBAL_VERSION_KEY);
    }
}
