<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceMonitoringProfiler
{
    private float $startedAt;

    private int $queryCountStart;

    public function __construct(
        private readonly string $endpoint,
    ) {
        $this->startedAt = microtime(true);
        $this->queryCountStart = count(DB::getQueryLog());
    }

    public static function begin(string $endpoint): self
    {
        if (! DB::logging()) {
            DB::enableQueryLog();
        }

        return new self($endpoint);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function finish(bool $cacheHit, array $context = []): void
    {
        $queries = DB::getQueryLog();
        $queryCount = max(0, count($queries) - $this->queryCountStart);
        $dbTimeMs = 0.0;
        foreach (array_slice($queries, $this->queryCountStart) as $q) {
            $dbTimeMs += (float) ($q['time'] ?? 0);
        }

        $responseMs = (int) round((microtime(true) - $this->startedAt) * 1000);

        $payload = array_merge([
            'endpoint' => $this->endpoint,
            'query_count' => $queryCount,
            'db_time_ms' => (int) round($dbTimeMs),
            'cache_hit' => $cacheHit,
            'response_time_ms' => $responseMs,
        ], $context);

        Log::info('attendance_monitoring.profile', $payload);

        if ($responseMs >= 500) {
            Log::warning('attendance_monitoring.slow', $payload);
        }
    }
}
