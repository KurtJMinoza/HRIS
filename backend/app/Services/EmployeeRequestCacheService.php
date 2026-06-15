<?php

namespace App\Services;

use App\Support\AttendanceCorrectionModuleCache;
use App\Support\LeaveModuleCache;
use App\Support\OvertimeModuleCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmployeeRequestCacheService
{
    public const LIST_TTL = 45;

    public const SUMMARY_TTL = 60;

    public const FORM_CONTEXT_TTL = 600;

    public const ATTENDANCE_DETAIL_TTL = 180;

    /**
     * @return array{payload: mixed, cache_hit: bool}
     */
    public function remember(string $key, int $ttlSeconds, callable $resolver): array
    {
        $repo = $this->repository();

        try {
            $cached = $repo->get($key);
            if ($cached !== null) {
                return ['payload' => $cached, 'cache_hit' => true];
            }
        } catch (\Throwable $e) {
            Log::warning('employee_request.cache_read_failed', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }

        $payload = $resolver();

        try {
            $repo->put($key, $payload, max(30, $ttlSeconds));
        } catch (\Throwable $e) {
            Log::warning('employee_request.cache_write_failed', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
        }

        return ['payload' => $payload, 'cache_hit' => false];
    }

    public function leaveKey(int $employeeId, string $slice, array $params = []): string
    {
        return $this->key('leave', $employeeId, $slice, LeaveModuleCache::version(), $params);
    }

    public function overtimeKey(int $employeeId, string $slice, array $params = []): string
    {
        return $this->key('overtime', $employeeId, $slice, OvertimeModuleCache::version(), $params);
    }

    public function correctionKey(int $employeeId, string $slice, array $params = []): string
    {
        return $this->key('attendance_correction', $employeeId, $slice, AttendanceCorrectionModuleCache::version(), $params);
    }

    public function attendanceDetailKey(int $employeeId, string $date, string $issueType): string
    {
        return sprintf(
            'employee:attendance_details:%d:%s:%s:v%d:%d',
            $employeeId,
            $date,
            $issueType,
            AttendanceCorrectionModuleCache::version(),
            $this->employeeContextVersion($employeeId),
        );
    }

    public function invalidateEmployeeContext(int $employeeId): void
    {
        try {
            $key = $this->employeeContextVersionKey($employeeId);
            $this->repository()->add($key, 1);
            $this->repository()->increment($key);
        } catch (\Throwable $e) {
            Log::warning('employee_request.context_invalidation_failed', [
                'employee_id' => $employeeId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function key(string $module, int $employeeId, string $slice, int $moduleVersion, array $params): string
    {
        ksort($params);
        $hash = $params === [] ? 'default' : substr(hash('sha256', json_encode($params)), 0, 16);

        return sprintf(
            'employee:%s:%s:%d:%s:v%d:%d',
            $module,
            $slice,
            $employeeId,
            $hash,
            $moduleVersion,
            $this->employeeContextVersion($employeeId),
        );
    }

    private function employeeContextVersion(int $employeeId): int
    {
        try {
            return (int) $this->repository()->rememberForever(
                $this->employeeContextVersionKey($employeeId),
                fn (): int => 1,
            );
        } catch (\Throwable) {
            return 1;
        }
    }

    private function employeeContextVersionKey(int $employeeId): string
    {
        return 'employee:request_context:version:'.$employeeId;
    }

    private function repository(): Repository
    {
        $store = config('cache.attendance_store');

        return is_string($store) && $store !== ''
            ? Cache::store($store)
            : Cache::store();
    }
}
