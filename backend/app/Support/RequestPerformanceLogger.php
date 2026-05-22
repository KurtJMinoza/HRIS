<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestPerformanceLogger
{
    /**
     * @return array{endpoint: string, started_at: float, query_count: int, db_ms: float}
     */
    public static function start(string $endpoint): array
    {
        $ctx = [
            'endpoint' => $endpoint,
            'started_at' => microtime(true),
            'query_count' => 0,
            'db_ms' => 0.0,
        ];

        DB::listen(static function ($query) use (&$ctx): void {
            $ctx['query_count']++;
            $ctx['db_ms'] += (float) $query->time;
            if ((float) $query->time > 500.0) {
                Log::warning('request_endpoint.slow_query', [
                    'endpoint' => $ctx['endpoint'],
                    'query_ms' => round((float) $query->time, 2),
                    'sql' => $query->sql,
                ]);
            }
        });

        return $ctx;
    }

    /**
     * @param  array{endpoint: string, started_at: float, query_count: int, db_ms: float}  $ctx
     * @param  array<string, mixed>  $extra
     */
    public static function finish(array $ctx, Request $request, int $rowsReturned = 0, array $extra = []): void
    {
        $totalMs = (microtime(true) - (float) $ctx['started_at']) * 1000;
        $payload = array_merge([
            'endpoint' => $ctx['endpoint'],
            'query_count' => $ctx['query_count'],
            'database_ms' => round((float) $ctx['db_ms'], 2),
            'total_ms' => round($totalMs, 2),
            'rows_returned' => $rowsReturned,
            'filters' => $request->query(),
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
        ], $extra);

        if ($totalMs > 500.0 || (float) $ctx['db_ms'] > 500.0) {
            Log::warning('request_endpoint.slow', $payload);

            return;
        }

        Log::info('request_endpoint.performance', $payload);
    }
}
