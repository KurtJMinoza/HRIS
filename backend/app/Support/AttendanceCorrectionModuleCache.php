<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AttendanceCorrectionModuleCache
{
    private const VERSION_KEY = 'attendance_correction_module:version';

    public static function version(): int
    {
        try {
            return (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    public static function flush(): void
    {
        try {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        } catch (\Throwable $e) {
            Log::warning('attendance_correction_module.cache_flush_failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bump list/counts cache version and clear related dashboard/sidebar caches.
     */
    public static function flushAfterMutation(?\App\Models\User $actor = null, ?int $companyId = null, ?int $correctionId = null): void
    {
        self::flush();
        app(\App\Services\AttendanceCorrectionStatusService::class)
            ->invalidateCaches($actor, $companyId, $correctionId, bumpModuleVersion: false);
    }
}
