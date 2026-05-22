<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceEmbeddingCacheService
{
    public static function employeeKey(int $employeeId): string
    {
        return 'face:embeddings:employee:'.$employeeId;
    }

    public static function companyIndexKey(?int $companyId): string
    {
        return 'face:embedding:index:company:'.($companyId ?? 'all');
    }

    public static function legacyCompanyIndexKey(?int $companyId): string
    {
        return 'face:embedding:index:'.($companyId ?? 'all');
    }

    public static function companyEmbeddingsKey(?int $companyId): string
    {
        return 'face:embeddings:company:'.($companyId ?? 'all');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getEmployeeEmbeddings(int $employeeId): ?array
    {
        try {
            $payload = self::cache()->get(self::employeeKey($employeeId));
            if (self::isValidEmployeePayload($payload)) {
                return $payload;
            }
        } catch (\Throwable $e) {
            self::logCacheFailure('read employee embedding cache', $e);
        }

        return self::refreshEmployeeFaceCache($employeeId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getCompanyEmbeddingIndex(?int $companyId): array
    {
        $key = self::companyIndexKey($companyId);
        try {
            $payload = self::cache()->get($key);
            if (is_array($payload) && isset($payload['employees']) && is_array($payload['employees'])) {
                return $payload;
            }
        } catch (\Throwable $e) {
            self::logCacheFailure('read company embedding index', $e);
        }

        return self::refreshCompanyFaceIndex($companyId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function refreshEmployeeFaceCache(int $employeeId): ?array
    {
        $user = self::faceUserQuery()
            ->whereKey($employeeId)
            ->first();

        if (! $user || ! self::canCacheUser($user)) {
            self::forgetEmployee($employeeId);

            return null;
        }

        $payload = self::buildEmployeePayload($user);
        if ($payload === null) {
            self::forgetEmployee($employeeId);

            return null;
        }

        try {
            self::cache()->put(self::employeeKey($employeeId), $payload, self::ttl());
        } catch (\Throwable $e) {
            self::logCacheFailure('write employee embedding cache', $e);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function refreshCompanyFaceIndex(?int $companyId): array
    {
        $query = self::faceUserQuery()->attendanceEmployees()->active();
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $employees = [];
        $query->orderBy('id')->chunkById(200, function ($users) use (&$employees): void {
            foreach ($users as $user) {
                if (! self::canCacheUser($user)) {
                    continue;
                }

                $payload = self::buildEmployeePayload($user);
                if ($payload === null) {
                    continue;
                }

                $employees[(int) $user->id] = $payload;
                try {
                    self::cache()->put(self::employeeKey((int) $user->id), $payload, self::ttl());
                } catch (\Throwable $e) {
                    self::logCacheFailure('write employee embedding cache from company index', $e);
                }
            }
        });

        $payload = [
            'company_id' => $companyId,
            'generated_at' => now()->toIso8601String(),
            'employee_count' => count($employees),
            'employees' => $employees,
        ];

        try {
            self::cache()->put(self::companyIndexKey($companyId), $payload, self::ttl());
            self::cache()->put(self::legacyCompanyIndexKey($companyId), $payload, self::ttl());
            self::cache()->put(self::companyEmbeddingsKey($companyId), $payload, self::ttl());
        } catch (\Throwable $e) {
            self::logCacheFailure('write company embedding index', $e);
        }

        return $payload;
    }

    public static function invalidateFaceCache(int $employeeId, ?int $companyId = null): void
    {
        if ($companyId === null) {
            $companyId = User::query()->whereKey($employeeId)->value('company_id');
            $companyId = $companyId !== null ? (int) $companyId : null;
        }

        self::forgetEmployee($employeeId);
        self::forgetCompanyIndex($companyId);
        self::forgetCompanyIndex(null);
    }

    /**
     * Rebuild the employee cache and the affected company index after a successful
     * face write. Database/storage remains the source of truth.
     */
    public static function refreshAfterFaceChange(int $employeeId, ?int $companyId = null): void
    {
        self::invalidateFaceCache($employeeId, $companyId);

        $payload = self::refreshEmployeeFaceCache($employeeId);
        $effectiveCompanyId = $companyId ?? (isset($payload['company_id']) ? (int) $payload['company_id'] : null);
        self::refreshCompanyFaceIndex($effectiveCompanyId);
        self::refreshCompanyFaceIndex(null);
    }

    public static function forgetCompanyIndex(?int $companyId): void
    {
        try {
            self::cache()->forget(self::companyIndexKey($companyId));
            self::cache()->forget(self::legacyCompanyIndexKey($companyId));
            self::cache()->forget(self::companyEmbeddingsKey($companyId));
        } catch (\Throwable $e) {
            self::logCacheFailure('forget company embedding index', $e);
        }
    }

    /**
     * @return array<int, array<int, float>>
     */
    public static function vectorsFromPayload(?array $payload): array
    {
        if (! is_array($payload) || ! isset($payload['embedding_vectors']) || ! is_array($payload['embedding_vectors'])) {
            return [];
        }

        $vectors = [];
        foreach ($payload['embedding_vectors'] as $row) {
            $vector = is_array($row) && isset($row['vector']) ? $row['vector'] : $row;
            if (! is_array($vector) || count($vector) !== FaceVerificationService::EMBEDDING_DIM) {
                continue;
            }

            $vectors[] = array_values(array_map('floatval', $vector));
        }

        return $vectors;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function buildEmployeePayload(User $user): ?array
    {
        $vectors = FaceVerificationService::descriptorCandidatesForUser($user);
        $effective = FaceVerificationService::getEffectiveDescriptor($user);

        if ($effective !== null && count($effective) === FaceVerificationService::EMBEDDING_DIM) {
            $alreadyIncluded = false;
            foreach ($vectors as $vector) {
                if ($vector === $effective) {
                    $alreadyIncluded = true;
                    break;
                }
            }
            if (! $alreadyIncluded) {
                $vectors[] = $effective;
            }
        }

        $rows = [];
        foreach ($vectors as $index => $vector) {
            if (count($vector) !== FaceVerificationService::EMBEDDING_DIM) {
                continue;
            }
            $rows[] = [
                'kind' => $index === 0 ? 'primary' : 'sample',
                'vector' => array_values(array_map('floatval', $vector)),
            ];
        }

        if ($rows === []) {
            return null;
        }

        $companyId = $user->company_id !== null ? (int) $user->company_id : $user->getEffectiveCompanyId();
        $versionSource = $user->face_registered_at ?: $user->updated_at;

        return [
            'employee_id' => (int) $user->id,
            'company_id' => $companyId,
            'embedding_vectors' => $rows,
            'embedding_version' => $versionSource?->timestamp ?? $user->updated_at?->timestamp ?? time(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'face_registered_at' => $user->face_registered_at?->toIso8601String(),
            'active' => $user->isOperationallyActive(),
        ];
    }

    private static function canCacheUser(User $user): bool
    {
        return $user->isRosterEligible()
            && $user->isOperationallyActive()
            && $user->hasRegisteredFace()
            && ! $user->needsFaceReregistration();
    }

    private static function isValidEmployeePayload(mixed $payload): bool
    {
        return is_array($payload)
            && isset($payload['employee_id'], $payload['embedding_vectors'])
            && is_array($payload['embedding_vectors'])
            && $payload['embedding_vectors'] !== [];
    }

    private static function faceUserQuery()
    {
        return User::query()->select([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'employment_status',
            'company_id',
            'branch_id',
            'department_id',
            'face_status',
            'face_descriptor',
            'face_descriptor_samples',
            'face_embedding',
            'face_registered_at',
            'updated_at',
        ]);
    }

    private static function cache(): Repository
    {
        $store = config('cache.face_store') ?: config('cache.default');

        try {
            return Cache::store($store);
        } catch (\Throwable $e) {
            Log::warning('Face cache store unavailable; falling back to default cache store', [
                'store' => $store,
                'message' => $e->getMessage(),
            ]);

            return Cache::store();
        }
    }

    private static function ttl(): int
    {
        return max(60, (int) config('attendance.face_embedding_cache_ttl_seconds', 86400));
    }

    private static function forgetEmployee(int $employeeId): void
    {
        try {
            self::cache()->forget(self::employeeKey($employeeId));
        } catch (\Throwable $e) {
            self::logCacheFailure('forget employee embedding cache', $e);
        }
    }

    private static function logCacheFailure(string $operation, \Throwable $e): void
    {
        Log::warning('Face embedding cache operation failed', [
            'operation' => $operation,
            'message' => $e->getMessage(),
        ]);
    }
}
