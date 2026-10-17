<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceLivenessSessionCacheService
{
    public static function key(string $sessionId): string
    {
        return 'face:liveness:session:'.$sessionId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $sessionId): ?array
    {
        try {
            $payload = self::cache()->get(self::key($sessionId));

            return is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            Log::warning('Face liveness session cache read failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function put(string $sessionId, array $result, ?int $employeeId = null): void
    {
        $ttl = self::ttl();
        $confidence = isset($result['confidence']) ? (float) $result['confidence'] : null;

        try {
            self::cache()->put(self::key($sessionId), array_merge($result, [
                'session_id' => $sessionId,
                'employee_id' => $employeeId,
                'user_id' => $employeeId,
                'liveness_score' => $confidence !== null ? $confidence / 100 : null,
                'status' => $result['result'] ?? (! empty($result['is_live']) ? 'PASS' : 'FAIL'),
                'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            ]), $ttl);
        } catch (\Throwable $e) {
            Log::warning('Face liveness session cache write failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function cache(): Repository
    {
        return Cache::store(config('cache.face_store') ?: config('cache.default'));
    }

    private static function ttl(): int
    {
        return max(180, min(600, (int) config('attendance.face_liveness_session_ttl_seconds', 300)));
    }
}
