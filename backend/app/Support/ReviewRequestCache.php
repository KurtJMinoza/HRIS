<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewRequestCache
{
    private const TTL_SECONDS = 120;

    private const LEAVE_REVIEW_LITE_TTL_SECONDS = 60;

    public static function key(string $module, int $id): string
    {
        return match ($module) {
            'attendance_correction' => "review:attendance_correction:{$id}",
            'leave' => "review:leave:{$id}",
            'overtime' => "review:overtime:{$id}",
            default => "review:{$module}:{$id}",
        };
    }

    public static function leaveReviewLiteKey(int $id, int $userId): string
    {
        return "leave:review-lite:{$id}:{$userId}";
    }

    public static function overtimeReviewLiteKey(int $id, int $userId): string
    {
        return "overtime:review-lite:{$id}:{$userId}";
    }

    public static function attendanceCorrectionReviewLiteKey(int $id, int $userId): string
    {
        return "attendance_correction:review-lite:{$id}:{$userId}";
    }

    private static function leaveReviewLiteIndexKey(int $id): string
    {
        return "leave:review-lite:{$id}:users";
    }

    private static function overtimeReviewLiteIndexKey(int $id): string
    {
        return "overtime:review-lite:{$id}:users";
    }

    private static function attendanceCorrectionReviewLiteIndexKey(int $id): string
    {
        return "attendance_correction:review-lite:{$id}:users";
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

    /**
     * @return array{payload: mixed, cache_hit: bool, query_count: int, total_ms: float, cache_error: string|null}
     */
    public static function rememberLeaveReviewLite(int $id, int $userId, callable $resolver): array
    {
        $started = microtime(true);
        $key = self::leaveReviewLiteKey($id, $userId);
        $cacheHit = false;
        $cacheError = null;

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
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
            Log::warning('leave_review_lite.cache_read_failed', [
                'request_id' => $id,
                'user_id' => $userId,
                'message' => $cacheError,
            ]);
        }

        $payload = $resolver();

        if ($cacheError === null) {
            try {
                Cache::put($key, $payload, now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
                $indexKey = self::leaveReviewLiteIndexKey($id);
                $userIds = Cache::get($indexKey, []);
                if (! is_array($userIds)) {
                    $userIds = [];
                }
                $userIds[] = $userId;
                Cache::put($indexKey, array_values(array_unique(array_map('intval', $userIds))), now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
            } catch (\Throwable $e) {
                $cacheError = $e->getMessage();
                Log::warning('leave_review_lite.cache_write_failed', [
                    'request_id' => $id,
                    'user_id' => $userId,
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

    /**
     * @return array{payload: mixed, cache_hit: bool, query_count: int, total_ms: float, cache_error: string|null}
     */
    public static function rememberOvertimeReviewLite(int $id, int $userId, callable $resolver): array
    {
        $started = microtime(true);
        $key = self::overtimeReviewLiteKey($id, $userId);
        $cacheHit = false;
        $cacheError = null;

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
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
            Log::warning('overtime_review_lite.cache_read_failed', [
                'request_id' => $id,
                'user_id' => $userId,
                'message' => $cacheError,
            ]);
        }

        $payload = $resolver();

        if ($cacheError === null) {
            try {
                Cache::put($key, $payload, now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
                $indexKey = self::overtimeReviewLiteIndexKey($id);
                $userIds = Cache::get($indexKey, []);
                if (! is_array($userIds)) {
                    $userIds = [];
                }
                $userIds[] = $userId;
                Cache::put($indexKey, array_values(array_unique(array_map('intval', $userIds))), now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
            } catch (\Throwable $e) {
                $cacheError = $e->getMessage();
                Log::warning('overtime_review_lite.cache_write_failed', [
                    'request_id' => $id,
                    'user_id' => $userId,
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

    /**
     * @return array{payload: mixed, cache_hit: bool, query_count: int, total_ms: float, cache_error: string|null}
     */
    public static function rememberAttendanceCorrectionReviewLite(int $id, int $userId, callable $resolver): array
    {
        $started = microtime(true);
        $key = self::attendanceCorrectionReviewLiteKey($id, $userId);
        $cacheHit = false;
        $cacheError = null;

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
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
            Log::warning('attendance_correction_review_lite.cache_read_failed', [
                'request_id' => $id,
                'user_id' => $userId,
                'message' => $cacheError,
            ]);
        }

        $payload = $resolver();

        if ($cacheError === null) {
            try {
                Cache::put($key, $payload, now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
                $indexKey = self::attendanceCorrectionReviewLiteIndexKey($id);
                $userIds = Cache::get($indexKey, []);
                if (! is_array($userIds)) {
                    $userIds = [];
                }
                $userIds[] = $userId;
                Cache::put($indexKey, array_values(array_unique(array_map('intval', $userIds))), now()->addSeconds(self::LEAVE_REVIEW_LITE_TTL_SECONDS));
            } catch (\Throwable $e) {
                $cacheError = $e->getMessage();
                Log::warning('attendance_correction_review_lite.cache_write_failed', [
                    'request_id' => $id,
                    'user_id' => $userId,
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
            if ($module === 'leave') {
                self::forgetLeaveReviewLite($id);
            } elseif ($module === 'overtime') {
                self::forgetOvertimeReviewLite($id);
            } elseif ($module === 'attendance_correction') {
                self::forgetAttendanceCorrectionReviewLite($id);
            }
        } catch (\Throwable $e) {
            Log::warning('review_request.cache_forget_failed', [
                'module' => $module,
                'request_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function forgetLeaveReviewLite(int $id): void
    {
        try {
            $indexKey = self::leaveReviewLiteIndexKey($id);
            $userIds = Cache::get($indexKey, []);
            if (is_array($userIds)) {
                foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                    Cache::forget(self::leaveReviewLiteKey($id, $userId));
                }
            }
            Cache::forget($indexKey);
        } catch (\Throwable $e) {
            Log::warning('leave_review_lite.cache_forget_failed', [
                'request_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function forgetOvertimeReviewLite(int $id): void
    {
        try {
            $indexKey = self::overtimeReviewLiteIndexKey($id);
            $userIds = Cache::get($indexKey, []);
            if (is_array($userIds)) {
                foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                    Cache::forget(self::overtimeReviewLiteKey($id, $userId));
                }
            }
            Cache::forget($indexKey);
        } catch (\Throwable $e) {
            Log::warning('overtime_review_lite.cache_forget_failed', [
                'request_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function forgetAttendanceCorrectionReviewLite(int $id): void
    {
        try {
            $indexKey = self::attendanceCorrectionReviewLiteIndexKey($id);
            $userIds = Cache::get($indexKey, []);
            if (is_array($userIds)) {
                foreach (array_unique(array_map('intval', $userIds)) as $userId) {
                    Cache::forget(self::attendanceCorrectionReviewLiteKey($id, $userId));
                }
            }
            Cache::forget($indexKey);
        } catch (\Throwable $e) {
            Log::warning('attendance_correction_review_lite.cache_forget_failed', [
                'request_id' => $id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
