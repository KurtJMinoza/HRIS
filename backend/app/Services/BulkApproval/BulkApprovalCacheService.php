<?php

namespace App\Services\BulkApproval;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class BulkApprovalCacheService
{
    private const SELECTION_TTL_SECONDS = 600;

    private const PROGRESS_TTL_SECONDS = 1800;

    /**
     * @param  array<string, mixed>  $filters
     * @param  int[]  $ids
     * @return array{bulk_token: string, total_matching: int, eligible_count: int, skipped_count: int, skipped_reasons_summary: array<string, int>}
     */
    public function storePreview(
        string $module,
        User $actor,
        array $filters,
        array $ids,
        int $totalMatching,
        array $skippedReasons = [],
    ): array {
        $filters = $this->canonicalize($filters);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $token = substr(hash('sha256', json_encode([
            'module' => $module,
            'user_id' => (int) $actor->id,
            'filters' => $filters,
        ], JSON_THROW_ON_ERROR)), 0, 32);

        $payload = [
            'module' => $module,
            'user_id' => (int) $actor->id,
            'ids' => $ids,
            'filters' => $filters,
            'count' => count($ids),
            'total' => count($ids),
            'total_matching' => max($totalMatching, count($ids)),
            'skipped_reasons_summary' => $skippedReasons,
            'created_at' => now()->timestamp,
        ];

        $this->cache()->put(
            $this->selectionKey($module, (int) $actor->id, $token),
            $payload,
            now()->addSeconds(self::SELECTION_TTL_SECONDS),
        );

        return [
            'bulk_token' => $token,
            'total_matching' => (int) $payload['total_matching'],
            'eligible_count' => count($ids),
            'skipped_count' => max(0, (int) $payload['total_matching'] - count($ids)),
            'skipped_reasons_summary' => $skippedReasons,
        ];
    }

    /**
     * @return array{module: string, user_id: int, ids: int[], filters: array<string, mixed>, count: int, total_matching: int, skipped_reasons_summary: array<string, int>, created_at: int}|null
     */
    public function selection(string $module, User $actor, string $token): ?array
    {
        $payload = $this->cache()->get($this->selectionKey($module, (int) $actor->id, $token));
        if (! is_array($payload)
            || ($payload['module'] ?? null) !== $module
            || (int) ($payload['user_id'] ?? 0) !== (int) $actor->id
            || ! is_array($payload['ids'] ?? null)
        ) {
            return null;
        }

        return $payload;
    }

    public function beginProgress(string $module, string $token, int $total): void
    {
        $this->putProgress($module, $token, [
            'status' => 'processing',
            'total' => max(0, $total),
            'processed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'skipped' => 0,
            'failed' => 0,
            'updated_at' => now()->timestamp,
        ]);
    }

    /**
     * @param  array{processed?: int, approved?: int, rejected?: int, skipped?: int, failed?: int}  $increments
     */
    public function advanceProgress(string $module, string $token, array $increments): void
    {
        $progress = $this->progress($module, $token) ?? [
            'status' => 'processing',
            'total' => 0,
            'processed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach (['processed', 'approved', 'rejected', 'skipped', 'failed'] as $field) {
            $progress[$field] = (int) ($progress[$field] ?? 0) + (int) ($increments[$field] ?? 0);
        }
        $progress['status'] = 'processing';
        $progress['updated_at'] = now()->timestamp;
        $this->putProgress($module, $token, $progress);
    }

    public function finishProgress(string $module, string $token, string $status = 'completed'): void
    {
        $progress = $this->progress($module, $token);
        if (! is_array($progress)) {
            return;
        }

        $progress['status'] = $status;
        $progress['updated_at'] = now()->timestamp;
        $this->putProgress($module, $token, $progress);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function progress(string $module, string $token): ?array
    {
        $value = $this->cache()->get($this->progressKey($module, $token));

        return is_array($value) ? $value : null;
    }

    private function putProgress(string $module, string $token, array $payload): void
    {
        $this->cache()->put(
            $this->progressKey($module, $token),
            $payload,
            now()->addSeconds(self::PROGRESS_TTL_SECONDS),
        );
    }

    private function selectionKey(string $module, int $userId, string $token): string
    {
        if ($module === 'attendance_correction') {
            return "attendance_bulk:{$userId}:{$token}";
        }

        return "bulk:{$module}:{$userId}:{$token}";
    }

    private function progressKey(string $module, string $token): string
    {
        if ($module === 'attendance_correction') {
            return "attendance_bulk_progress:{$token}";
        }

        return "bulk_progress:{$module}:{$token}";
    }

    private function cache(): Repository
    {
        return Cache::store(config('cache.bulk_approval_store') ?: config('cache.default'));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
