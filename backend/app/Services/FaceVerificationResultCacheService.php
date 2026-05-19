<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceVerificationResultCacheService
{
    public static function key(int $employeeId, Request $request): string
    {
        return 'face:verified:'.$employeeId.':'.FaceAttemptThrottleService::deviceId($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getForSession(int $employeeId, Request $request, ?string $sessionId): ?array
    {
        if (empty($sessionId)) {
            return null;
        }

        try {
            $payload = self::cache()->get(self::key($employeeId, $request));
        } catch (\Throwable $e) {
            Log::warning('Face verification result cache read failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return is_array($payload) && ($payload['session_id'] ?? null) === $sessionId
            ? $payload
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(int $employeeId, Request $request, array $payload): void
    {
        $ttl = max(10, min(30, (int) config('attendance.face_verified_ttl_seconds', 20)));

        try {
            self::cache()->put(self::key($employeeId, $request), array_merge($payload, [
                'employee_id' => $employeeId,
                'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            ]), $ttl);
        } catch (\Throwable $e) {
            Log::warning('Face verification result cache write failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function cache(): Repository
    {
        return Cache::store(config('cache.face_store') ?: config('cache.default'));
    }
}
