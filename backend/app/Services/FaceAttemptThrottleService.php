<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceAttemptThrottleService
{
    /**
     * Return null when allowed, or a throttle payload when blocked.
     *
     * @return array{retry_after: int, reason: string}|null
     */
    public static function hit(Request $request, ?int $userId = null): ?array
    {
        // Face recognition must not be rate-limited; always allow attempts.
        return null;
    }

    public static function recordFailure(Request $request, ?int $userId = null): void
    {
        // Failed scans are still audited elsewhere, but they should not create lockouts.
    }

    public static function deviceId(Request $request): string
    {
        $provided = $request->input('device_id')
            ?: $request->header('X-Device-Id')
            ?: $request->header('X-Kiosk-Device-Id');

        $raw = $provided ?: implode('|', [
            $request->ip() ?: '0.0.0.0',
            substr((string) $request->userAgent(), 0, 180),
        ]);

        return substr(hash('sha256', (string) $raw), 0, 32);
    }

    /**
     * @return array{retry_after: int, reason: string}|null
     */
    private static function blockedPayload(Request $request, ?int $userId): ?array
    {
        foreach (self::cooldownKeys($request, $userId) as $key) {
            $payload = self::cache()->get($key);
            if (! is_array($payload)) {
                continue;
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);
            $retryAfter = max(1, $expiresAt - time());

            return [
                'retry_after' => $retryAfter,
                'reason' => (string) ($payload['reason'] ?? 'rate_limited'),
            ];
        }

        return null;
    }

    private static function startCooldown(Request $request, ?int $userId, string $reason): void
    {
        $seconds = self::cooldownSeconds();
        $payload = [
            'reason' => $reason,
            'expires_at' => time() + $seconds,
        ];

        foreach (self::cooldownKeys($request, $userId) as $key) {
            self::cache()->put($key, $payload, $seconds);
        }
    }

    /**
     * @return list<string>
     */
    private static function attemptKeys(Request $request, ?int $userId): array
    {
        $keys = ['face:attempts:device:'.self::deviceId($request)];
        if ($userId !== null) {
            array_unshift($keys, 'face:attempts:user:'.$userId);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function failureKeys(Request $request, ?int $userId): array
    {
        $keys = ['face:attempts:failed:device:'.self::deviceId($request)];
        if ($userId !== null) {
            array_unshift($keys, 'face:attempts:failed:user:'.$userId);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function cooldownKeys(Request $request, ?int $userId): array
    {
        $keys = ['face:attempts:cooldown:device:'.self::deviceId($request)];
        if ($userId !== null) {
            array_unshift($keys, 'face:attempts:cooldown:user:'.$userId);
        }

        return $keys;
    }

    private static function increment(string $key, int $ttlSeconds): int
    {
        self::cache()->add($key, 0, $ttlSeconds);

        return (int) self::cache()->increment($key);
    }

    private static function cooldownSeconds(): int
    {
        return max(30, (int) config('attendance.face_attempts_cooldown_seconds', 60));
    }

    private static function cache(): Repository
    {
        return Cache::store(config('cache.face_store') ?: config('cache.default'));
    }
}
