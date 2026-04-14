<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks async face registration job outcome for polling (cache-backed).
 *
 * @phpstan-type StatusPayload array{
 *   status: 'pending'|'processing'|'completed'|'failed',
 *   target_user_id: int,
 *   message?: string,
 *   error_code?: string,
 *   completed_at?: string
 * }
 */
class FaceRegistrationStatusService
{
    private static function key(string $trackId): string
    {
        return 'face_registration:'.$trackId;
    }

    /**
     * @param  array{target_user_id: int}  $meta
     */
    public static function create(string $trackId, array $meta, int $ttlSeconds = 600): void
    {
        Cache::put(self::key($trackId), array_merge([
            'status' => 'pending',
        ], $meta), $ttlSeconds);
    }

    public static function markProcessing(string $trackId): void
    {
        $k = self::key($trackId);
        $prev = Cache::get($k, []);
        if (! is_array($prev)) {
            $prev = [];
        }
        Cache::put($k, array_merge($prev, ['status' => 'processing']), 600);
    }

    public static function complete(string $trackId): void
    {
        $k = self::key($trackId);
        $prev = Cache::get($k, []);
        if (! is_array($prev)) {
            $prev = [];
        }
        Cache::put($k, array_merge($prev, [
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
        ]), 600);
    }

    public static function fail(string $trackId, string $message, ?string $errorCode = null): void
    {
        $k = self::key($trackId);
        $prev = Cache::get($k, []);
        if (! is_array($prev)) {
            $prev = [];
        }
        Cache::put($k, array_merge($prev, [
            'status' => 'failed',
            'message' => $message,
            'error_code' => $errorCode,
            'completed_at' => now()->toIso8601String(),
        ]), 600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $trackId): ?array
    {
        $v = Cache::get(self::key($trackId));

        return is_array($v) ? $v : null;
    }
}
