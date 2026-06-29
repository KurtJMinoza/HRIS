<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class HolidayPolicyCache
{
    public const TTL_SECONDS = 1800;

    public static function policyKey(int $companyId): string
    {
        return 'policy:holiday:'.max(0, $companyId);
    }

    public static function forgetPolicy(?int $companyId): void
    {
        if ($companyId !== null) {
            Cache::forget(self::policyKey(max(0, $companyId)));
        }
    }

    public static function trackCompany(int $companyId): void
    {
        $ids = Cache::get('policy:holiday:companies', []);
        $ids = is_array($ids) ? $ids : [];
        $ids[(string) max(0, $companyId)] = true;
        Cache::put('policy:holiday:companies', $ids, self::TTL_SECONDS);
    }

    public static function forgetAll(): void
    {
        $ids = Cache::pull('policy:holiday:companies', []);
        foreach (array_keys(is_array($ids) ? $ids : []) as $companyId) {
            Cache::forget(self::policyKey((int) $companyId));
        }
        Cache::forget(self::policyKey(0));
    }
}
