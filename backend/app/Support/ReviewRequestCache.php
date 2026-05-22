<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewRequestCache
{
    private const TTL_SECONDS = 120;

    public static function key(string $module, int $id): string
    {
        return match ($module) {
            'attendance_correction' => "review:attendance_correction:{$id}",
            'leave' => "review:leave:{$id}",
            'overtime' => "review:overtime:{$id}",
            default => "review:{$module}:{$id}",
        };
    }

    /**
     * @return array{payload: mixed, cache_hit: bool, query_count: int, total_ms: float, cache_error: string|null}
     */
    public static function remember(string $module, int $id, callable $resolver, ?int $ttlSeconds = null): array
    {
        $started = microtime(true);
        $key = self::key($module, $id);
        $cacheHit = false;
        $cacheError = null;

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                $cacheHit = true;

                return [
                    'payload' => $cached,
                    'cache_hit' => true,
                    'query_count' => count(DB::getQueryLog()),
                    'total_ms' => round((microtime(true) - $started) * 1000, 2),
                    'cache_error' => null,
                ];
            }
        } catch (\Throwable $e) {
            $cacheError = $e->getMessage();
            Log::warning('review_request.cache_read_failed', [
                'module' => $module,
                'request_id' => $id,
                'message' => $cacheError,
            ]);
        }

        $payload = $resolver();

        if ($cacheError === null) {
            try {
                Cache::put($key, $payload, now()->addSeconds($ttlSeconds ?? self::TTL_SECONDS));
            } catch (\Throwable $e) {
                $cacheError = $e->getMessage();
                Log::warning('review_request.cache_write_failed', [
                    'module' => $module,
                    'request_id' => $id,
                    'message' => $cacheError,
                ]);
            }
        }

        return [
            'payload' => $payload,
            'cache_hit' => $cacheHit,
            'query_count' => count(DB::getQueryLog()),
            'total_ms' => round((microtime(true) - $started) * 1000, 2),
            'cache_error' => $cacheError,
        ];
    }

    public static function forget(string $module, int $id): void
    {
        try {
            Cache::forget(self::key($module, $id));
        } catch (\Throwable $e) {
            Log::warning('review_request.cache_forget_failed', [
                'module' => $module,
                'request_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
