<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceVerificationService
{
    /**
     * Embedding vector dimension produced by the Python face service.
     * InsightFace ArcFace (buffalo_l) → 512-D L2-normalized unit vectors.
     * All stored embeddings, distance computations, and validation checks use this value.
     */
    public const EMBEDDING_DIM = 512;

    /** User-visible message when another employee already owns this face template. */
    public static function duplicateRegistrationUserMessage(): string
    {
        return 'This face is already registered to another employee. Please use a different face or contact HR.';
    }

    /**
     * Default face match distance threshold (Euclidean distance between 512D ArcFace descriptors).
     * InsightFace unit vectors: cos_sim=0.5 → euc≈1.0; cos_sim=0.3 → euc≈1.18.
     * Match when distance <= threshold. Use config('attendance.face_match_threshold') for runtime value.
     */
    public const DEFAULT_MATCH_THRESHOLD = 1.0;

    private static function matchThreshold(): float
    {
        return (float) config('attendance.face_match_threshold', self::DEFAULT_MATCH_THRESHOLD);
    }

    private static function useCosineThreshold(): bool
    {
        $t = config('attendance.face_cosine_distance_threshold');

        return $t !== null && is_numeric($t);
    }

    private static function cosineDistanceThreshold(): float
    {
        // Default is set in config/attendance.php; keep a safe fallback here.
        return (float) config('attendance.face_cosine_distance_threshold', 0.55);
    }

    private static function minSimilarityScore(): float
    {
        return (float) config('attendance.face_min_similarity_score', 0.40);
    }

    private static function minSimilarityMargin(): float
    {
        return (float) config('attendance.face_min_similarity_margin', 0.04);
    }

    private static function kioskCosineDistanceThreshold(): ?float
    {
        $v = config('attendance.face_kiosk_cosine_distance_threshold');

        return $v !== null && is_numeric($v) ? (float) $v : null;
    }

    private static function kioskMinSimilarityScore(): ?float
    {
        $v = config('attendance.face_kiosk_min_similarity_score');

        return $v !== null && is_numeric($v) ? (float) $v : null;
    }

    private static function kioskMatchThreshold(): ?float
    {
        $v = config('attendance.face_kiosk_match_threshold');

        return $v !== null && is_numeric($v) ? (float) $v : null;
    }

    private static function crossCameraRelaxEnabled(): bool
    {
        return (bool) config('attendance.face_cross_camera_relax_enabled', true);
    }

    private static function crossCameraHighLivenessScore(): float
    {
        return (float) config('attendance.face_cross_camera_high_liveness_score', 0.90);
    }

    private static function crossCameraMinSimilarityRelaxDelta(): float
    {
        return (float) config('attendance.face_cross_camera_min_similarity_relax_delta', 0.05);
    }

    private static function crossCameraCosineDistanceRelaxDelta(): float
    {
        return (float) config('attendance.face_cross_camera_cosine_distance_relax_delta', 0.04);
    }

    private static function crossCameraKioskMinSimilarityFloor(): float
    {
        return (float) config('attendance.face_cross_camera_kiosk_min_similarity_floor', 0.33);
    }

    private static function crossCameraMinMargin(): float
    {
        return (float) config('attendance.face_cross_camera_min_similarity_margin', 0.06);
    }

    /**
     * Duplicate registration (cross-account): block when cosine similarity is high enough
     * to indicate the same person. Multi-signal approach (cosine, Euclidean, dual-signal, aggregate)
     * catches most cases; this is the primary per-row cosine gate.
     */
    private static function duplicateMinCosineSimilarity(): float
    {
        $v = config('attendance.face_duplicate_min_cosine_similarity');

        $resolved = is_numeric($v)
            ? (float) $v
            : (float) config('attendance.face_duplicate_min_best_cosine_similarity', 0.70);

        if (config('attendance.face_duplicate_enforce_registration_cosine_floor', true)) {
            $floor = (float) config('attendance.face_duplicate_registration_cosine_floor', 0.65);

            return max($resolved, $floor);
        }

        return $resolved;
    }

    /**
     * Secondary duplicate rule: L2 distance on raw ArcFace vectors (when cosine alone is ambiguous).
     */
    private static function duplicateMaxEuclideanDistance(): float
    {
        return (float) config('attendance.face_duplicate_max_euclidean', 0.80);
    }

    /**
     * Duplicate rule: L2 distance between L2-normalized 512-D vectors (same geometry as cosine similarity).
     * For unit vectors, distance d relates to cosine similarity as cos = 1 - d^2/2.
     */
    private static function duplicateMaxEuclideanNormalized(): float
    {
        return (float) config('attendance.face_duplicate_max_euclidean_normalized', 0.80);
    }

    /**
     * Extra duplicate rule: both cosine and raw Euclidean are in a "borderline same person" band.
     * Catches pairs that miss the primary OR gates (e.g. cos 0.83 with euc 0.42) without loosening single-signal checks.
     */
    private static function duplicateDualSignalEnabled(): bool
    {
        return (bool) config('attendance.face_duplicate_dual_signal_enabled', true);
    }

    private static function duplicateDualCosineMin(): float
    {
        return (float) config('attendance.face_duplicate_dual_cosine_min', 0.65);
    }

    private static function duplicateDualMaxEuclidean(): float
    {
        return (float) config('attendance.face_duplicate_dual_max_euclidean', 0.85);
    }

    /**
     * Aggregate best-across-all-samples threshold: if the BEST cosine similarity
     * across ALL stored vectors for a single other user exceeds this, flag as duplicate.
     * Lower than per-row gate because the "best sample" comparison is the most reliable signal.
     */
    private static function duplicateAggregateBestCosineMin(): float
    {
        return (float) config('attendance.face_duplicate_aggregate_best_cosine_min', 0.65);
    }

    /**
     * Also check raw (un-normalized) cosine similarity. ArcFace vectors are not always
     * unit-length; raw cosine can diverge from normalized cosine for high-norm vectors.
     */
    private static function duplicateRawCosineMin(): float
    {
        return (float) config('attendance.face_duplicate_raw_cosine_min', 0.70);
    }

    /**
     * Strict cross-account duplicate: any of cosine-on-normalized, raw cosine, raw L2,
     * or normalized L2 crosses threshold.
     *
     * @param  'sample'|'avg'  $kind
     */
    private static function duplicateRowMatchesIncoming(
        float $cosineSim,
        float $rawCosineSim,
        float $rawEuclideanDistance,
        float $normEuclideanDistance,
        string $kind,
        float $minCosSample,
        float $minCosAvg,
        float $maxEucPrimary,
        float $maxEucNormPrimary
    ): bool {
        $cosineGate = $kind === 'avg' ? $minCosAvg : $minCosSample;

        if ($cosineSim >= $cosineGate
            || $rawCosineSim >= self::duplicateRawCosineMin()
            || $rawEuclideanDistance <= $maxEucPrimary
            || $normEuclideanDistance <= $maxEucNormPrimary) {
            return true;
        }
        if (! self::duplicateDualSignalEnabled()) {
            return false;
        }

        $dualCosMin = self::duplicateDualCosineMin();
        $dualMaxEuc = self::duplicateDualMaxEuclidean();

        return ($cosineSim >= $dualCosMin || $rawCosineSim >= $dualCosMin)
            && ($rawEuclideanDistance <= $dualMaxEuc
                || $normEuclideanDistance <= $maxEucNormPrimary);
    }

    /**
     * Averaged embedding row for another user (optional env). Defaults to the same cosine gate as samples
     * to avoid extra false "already registered" hits from the mean vector alone.
     */
    private static function duplicateMinCosineSimilarityAvg(): float
    {
        $v = config('attendance.face_duplicate_min_cosine_similarity_avg');

        $resolved = is_numeric($v)
            ? (float) $v
            : self::duplicateMinCosineSimilarity();

        if (config('attendance.face_duplicate_enforce_registration_cosine_floor', true)) {
            $floor = (float) config('attendance.face_duplicate_registration_cosine_floor', 0.65);

            return max($resolved, $floor);
        }

        return $resolved;
    }

    private static function duplicateNearMissLogMinSimilarity(): float
    {
        return (float) config('attendance.face_duplicate_near_miss_log_min_similarity', 0.72);
    }

    /**
     * Bump after any face enrollment change so cached duplicate rows rebuild from the database.
     */
    public static function bumpDuplicateEmbeddingIndexVersion(): void
    {
        Cache::increment('face:dup-embedding-index-ver');
    }

    /**
     * Base query for cross-account duplicate scans: any user row that may hold face embeddings (not limited by roster role).
     */
    private static function duplicateScanUserQuery(): Builder
    {
        return User::query()
            ->where(function ($q) {
                $q->where('face_status', 'registered')
                    ->orWhereNotNull('face_descriptor_samples')
                    ->orWhereNotNull('face_embedding')
                    ->orWhereNotNull('face_descriptor');
            });
    }

    /**
     * Flattened duplicate-comparison rows for all users with a registered face (for caching).
     *
     * @return array<int, array{user_id: int, kind: 'sample'|'avg', vec: array<int, float>}>
     */
    private static function buildDuplicateEmbeddingIndexPayload(): array
    {
        $employees = self::duplicateScanUserQuery()->get();

        $out = [];
        foreach ($employees as $user) {
            if (! $user->hasRegisteredFace()) {
                continue;
            }
            $rows = self::duplicateComparisonRowsForUser($user);
            // Do not truncate: duplicate index must include every stored sample row or the cache can miss a match.
            foreach ($rows as $row) {
                $out[] = [
                    'user_id' => (int) $user->id,
                    'kind' => $row['kind'],
                    'vec' => $row['vec'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{user_id: int, kind: 'sample'|'avg', vec: array<int, float>}>
     */
    private static function getCachedDuplicateEmbeddingIndexRows(): array
    {
        $ver = (int) Cache::get('face:dup-embedding-index-ver', 0);
        $ttl = max(60, (int) config('attendance.face_duplicate_embedding_index_ttl_seconds', 86400));

        return Cache::remember(
            'face:dup-embedding-index:data:'.$ver,
            now()->addSeconds($ttl),
            static fn () => self::buildDuplicateEmbeddingIndexPayload()
        );
    }

    /**
     * L2-normalize a face embedding vector (stabilizes cosine / Euclidean comparisons across sessions).
     * InsightFace already returns unit vectors, but normalizing again is safe and idempotent.
     *
     * @param  array<int, float>  $v
     * @return array<int, float>
     */
    public static function l2Normalize(array $v): array
    {
        $dim = count($v);
        $sum = 0.0;
        for ($i = 0; $i < $dim; $i++) {
            $x = (float) ($v[$i] ?? 0);
            $sum += $x * $x;
        }
        $n = sqrt($sum);
        if ($n < 1e-9) {
            return array_map('floatval', array_values($v));
        }
        $out = [];
        for ($i = 0; $i < $dim; $i++) {
            $out[$i] = (float) ($v[$i] ?? 0) / $n;
        }

        return $out;
    }

    /**
     * @deprecated Use l2Normalize(). Kept as a backward-compatible alias.
     *
     * @param  array<int, float>  $v
     * @return array<int, float>
     */
    public static function l2Normalize128(array $v): array
    {
        return self::l2Normalize($v);
    }

    /**
     * Verify that the provided descriptor matches the stored one.
     * Stored descriptor can be JSON array of 128 floats, or legacy image object (not verifiable).
     *
     * @param  string|null  $storedDescriptor  JSON string (array of 128 numbers or legacy object)
     * @param  array  $incomingDescriptor  Array of 128 numbers
     */
    public static function verify(?string $storedDescriptor, array $incomingDescriptor): bool
    {
        if (empty($storedDescriptor) || empty($incomingDescriptor)) {
            return false;
        }

        $stored = json_decode($storedDescriptor, true);
        if (! is_array($stored)) {
            return false;
        }

        // Legacy format: { "type": "image", "data": "base64...", ... } — cannot verify with descriptor
        if (isset($stored['type']) && isset($stored['data'])) {
            return false;
        }

        // Expect EMBEDDING_DIM-dimensional descriptor
        if (count($stored) !== self::EMBEDDING_DIM || count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return false;
        }

        $distance = self::euclideanDistance($stored, $incomingDescriptor);

        return $distance <= self::matchThreshold();
    }

    /**
     * Euclidean distance between two face embedding vectors.
     */
    public static function euclideanDistance(array $a, array $b): float
    {
        $dim = max(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $dim; $i++) {
            $d = ($a[$i] ?? 0) - ($b[$i] ?? 0);
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /**
     * Cosine similarity (0–1) for InsightFace ArcFace embeddings (L2-normalized unit vectors).
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dim = max(count($a), count($b));
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $dim; $i++) {
            $x = (float) ($a[$i] ?? 0);
            $y = (float) ($b[$i] ?? 0);
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        $denom = sqrt($normA) * sqrt($normB);

        return $denom < 1e-9 ? 0.0 : max(0, min(1, $dot / $denom));
    }

    /**
     * Cosine distance = 1 - cosine_similarity. Used for configurable threshold.
     */
    public static function cosineDistance(array $a, array $b): float
    {
        return 1.0 - self::cosineSimilarity($a, $b);
    }

    /**
     * Average multiple face embedding descriptors element-wise, then L2-normalize the result.
     * Used to combine 5–10 samples for better match reliability.
     *
     * @param  array<int, array<int, float>>  $descriptors  Non-empty list of EMBEDDING_DIM-length arrays
     * @return array<int, float>
     */
    public static function averageDescriptor(array $descriptors): array
    {
        if (empty($descriptors)) {
            return [];
        }

        $dim = self::EMBEDDING_DIM;
        $n = count($descriptors);
        $out = [];
        for ($i = 0; $i < $dim; $i++) {
            $sum = 0.0;
            foreach ($descriptors as $d) {
                $sum += (float) ($d[$i] ?? 0);
            }
            $out[$i] = $sum / $n;
        }

        return self::l2Normalize($out);
    }

    /**
     * Get the effective descriptor for a user: average of face_descriptor_samples if present,
     * otherwise the single face_descriptor decoded to array. Returns null if no valid descriptor.
     *
     * @return array<int, float>|null
     */
    public static function getEffectiveDescriptor(User $user): ?array
    {
        $dim = self::EMBEDDING_DIM;

        $samples = $user->face_descriptor_samples;
        if (is_array($samples) && ! empty($samples)) {
            $valid = [];
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === $dim) {
                    $valid[] = array_map('floatval', array_values($s));
                }
            }
            if (! empty($valid)) {
                return self::averageDescriptor($valid);
            }
        }

        $stored = $user->face_embedding ?? $user->face_descriptor;
        if (empty($stored)) {
            return null;
        }

        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
        if (! is_array($decoded) || isset($decoded['type']) || count($decoded) !== $dim) {
            return null;
        }

        return array_map('floatval', array_values($decoded));
    }

    /**
     * All stored 128-D vectors for a user (each sample + primary embedding).
     * Duplicate checks compare against every candidate — averaging alone can miss same-face re-registration.
     *
     * @return array<int, array<int, float>>
     */
    public static function descriptorCandidatesForUser(User $user): array
    {
        $dim = self::EMBEDDING_DIM;
        $candidates = [];

        $samples = $user->face_descriptor_samples;
        if (is_array($samples)) {
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === $dim) {
                    $candidates[] = array_map('floatval', array_values($s));
                }
            }
        }

        $stored = $user->face_embedding ?? $user->face_descriptor;
        if (! empty($stored)) {
            $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
            if (is_array($decoded) && ! isset($decoded['type']) && count($decoded) === $dim) {
                $candidates[] = array_map('floatval', array_values($decoded));
            }
        }

        return $candidates;
    }

    /**
     * Rows to compare during duplicate registration: each raw sample + primary, and when
     * multiple samples exist, the averaged embedding (kind `avg` uses the avg cosine gate).
     *
     * @return array<int, array{vec: array<int, float>, kind: 'sample'|'avg'}>
     */
    public static function duplicateComparisonRowsForUser(User $user): array
    {
        $dim = self::EMBEDDING_DIM;
        $rows = [];
        foreach (self::descriptorCandidatesForUser($user) as $vec) {
            if (count($vec) === $dim) {
                $rows[] = ['vec' => $vec, 'kind' => 'sample'];
            }
        }

        if ($rows === []) {
            $eff = self::getEffectiveDescriptor($user);
            if ($eff !== null && count($eff) === $dim) {
                $rows[] = ['vec' => $eff, 'kind' => 'sample'];
            }

            return $rows;
        }

        $validSampleCount = 0;
        $samples = $user->face_descriptor_samples;
        if (is_array($samples)) {
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === $dim) {
                    $validSampleCount++;
                }
            }
        }

        if ($validSampleCount >= 2) {
            $avg = self::getEffectiveDescriptor($user);
            if ($avg !== null && count($avg) === $dim) {
                $rows[] = ['vec' => $avg, 'kind' => 'avg'];
            }
        }

        return $rows;
    }

    /**
     * Find an employee whose stored face descriptor best matches the given descriptor.
     * Returns the user with smallest distance if within MATCH_THRESHOLD, else null.
     *
     * @param  array<int, float>  $incomingDescriptor  512D descriptor (InsightFace ArcFace)
     */
    public static function identifyUserByFace(array $incomingDescriptor): ?User
    {
        $result = self::identifyUserByFaceWithScoreFromCache($incomingDescriptor);

        return $result ? $result['user'] : null;
    }

    /**
     * Users eligible for kiosk / face login identification (must match hasRegisteredFace() intent).
     */
    public static function faceIdentificationCandidateQuery(): Builder
    {
        return User::query()
            ->activeRoster()
            ->where(function ($q) {
                $q->where('face_status', 'registered')
                    ->orWhereNotNull('face_descriptor_samples')
                    ->orWhereNotNull('face_embedding')
                    ->orWhereNotNull('face_descriptor');
            });
    }

    /**
     * Best match of incoming descriptor against all stored vectors for one user (samples + primary).
     * Uses max cosine similarity across enrollment captures — same idea as duplicate detection
     * (mean-only matching caused false rejects when kiosk lighting differed from registration).
     *
     * @param  bool  $kioskMode  Use looser kiosk thresholds (clock-in/out with liveness already verified)
     * @return array{similarity_score: float, distance: float, cmp: float, passes: bool}|null
     */
    public static function aggregateBestMatchForUser(User $user, array $incomingDescriptor, bool $kioskMode = false, ?float $livenessScore = null): ?array
    {
        $dim = self::EMBEDDING_DIM;
        if (count($incomingDescriptor) !== $dim) {
            return null;
        }

        $payload = FaceEmbeddingCacheService::getEmployeeEmbeddings((int) $user->id);
        $candidates = FaceEmbeddingCacheService::vectorsFromPayload($payload);
        if ($candidates === []) {
            // Database-backed fallback keeps registration/update flows usable while
            // Redis is cold; refreshEmployeeFaceCache() remains the normal hot path.
            $candidates = self::descriptorCandidatesForUserCached($user);
        }
        if ($candidates === []) {
            $eff = self::getEffectiveDescriptor($user);
            if ($eff !== null && count($eff) === $dim) {
                $candidates = [$eff];
            }
        }
        if ($candidates === []) {
            return null;
        }

        return self::aggregateBestMatchForVectors($candidates, $incomingDescriptor, $kioskMode, $livenessScore);
    }

    /**
     * @param  array<int, array<int, float>>  $candidates
     * @return array{similarity_score: float, distance: float, cmp: float, passes: bool}|null
     */
    public static function aggregateBestMatchForVectors(array $candidates, array $incomingDescriptor, bool $kioskMode = false, ?float $livenessScore = null): ?array
    {
        $dim = self::EMBEDDING_DIM;
        if (count($incomingDescriptor) !== $dim || $candidates === []) {
            return null;
        }

        $incomingNorm = self::l2Normalize($incomingDescriptor);

        $bestCosineSim = -1.0;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($candidates as $stored) {
            if (count($stored) !== $dim) {
                continue;
            }
            $storedNorm = self::l2Normalize($stored);
            $s = self::cosineSimilarity($storedNorm, $incomingNorm);
            $d = self::euclideanDistance($storedNorm, $incomingNorm);
            if ($s > $bestCosineSim) {
                $bestCosineSim = $s;
                $bestDistance = $d;
            }
        }

        if ($bestCosineSim < 0) {
            return null;
        }

        $useCosine = self::useCosineThreshold();
        $cosineDist = 1.0 - $bestCosineSim;

        if ($kioskMode) {
            $cosineThreshold = self::kioskCosineDistanceThreshold() ?? self::cosineDistanceThreshold();
            $minSim = self::kioskMinSimilarityScore() ?? self::minSimilarityScore();
            $euclideanThreshold = self::kioskMatchThreshold() ?? self::matchThreshold();
        } else {
            $cosineThreshold = self::cosineDistanceThreshold();
            $minSim = self::minSimilarityScore();
            $euclideanThreshold = self::matchThreshold();
        }

        if (
            $kioskMode
            && self::crossCameraRelaxEnabled()
            && $livenessScore !== null
            && $livenessScore >= self::crossCameraHighLivenessScore()
        ) {
            $cosineThreshold = min(0.75, $cosineThreshold + self::crossCameraCosineDistanceRelaxDelta());
            $minSim = max(
                self::crossCameraKioskMinSimilarityFloor(),
                $minSim - self::crossCameraMinSimilarityRelaxDelta()
            );
        }

        $passes = $useCosine
            ? ($cosineDist <= $cosineThreshold && $bestCosineSim >= $minSim)
            : ($bestDistance <= $euclideanThreshold && $bestCosineSim >= $minSim);

        return [
            'similarity_score' => $bestCosineSim,
            'distance' => $bestDistance,
            'cmp' => $useCosine ? $cosineDist : $bestDistance,
            'passes' => $passes,
        ];
    }

    /**
     * Cached descriptor candidates for fast kiosk/login matching.
     * Uses Redis (via default cache store) when available, with a key versioned by user update timestamp.
     *
     * @return array<int, array<int, float>>
     */
    private static function descriptorCandidatesForUserCached(User $user): array
    {
        $updatedAt = $user->updated_at?->timestamp ?? 0;
        $faceRegisteredAt = $user->face_registered_at?->timestamp ?? 0;
        $key = sprintf('face:descriptor-candidates:user:%d:%d:%d', (int) $user->id, (int) $updatedAt, (int) $faceRegisteredAt);

        return Cache::remember($key, now()->addMinutes(10), static fn () => self::descriptorCandidatesForUser($user));
    }

    /**
     * Find an employee whose stored face descriptor best matches the given descriptor.
     * Returns [user, distance, similarity_score] if within threshold, else null.
     * similarity_score = cosine similarity (0–1) for storage in attendance log.
     *
     * @param  array<int, float>  $incomingDescriptor  128D descriptor
     * @param  bool  $kioskMode  Use relaxed kiosk thresholds (liveness already proven by Rekognition)
     * @return array{user: User, distance: float, similarity_score: float, second_best_score?: float|null, margin_score?: float|null}|null
     */
    public static function identifyUserByFaceWithScore(array $incomingDescriptor, bool $kioskMode = false, ?float $livenessScore = null, ?int $companyId = null): ?array
    {
        if (count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return null;
        }

        $employees = self::faceIdentificationCandidateQuery()
            ->select([
                'id', 'name', 'email', 'role', 'is_active',
                'face_status', 'face_descriptor', 'face_descriptor_samples',
                'face_embedding', 'face_registered_at',
                // `profile_image` only — `profile_image_url` is an appended accessor, not a DB column.
                'profile_image',
                'updated_at',
            ])
            ->get();
        $minMargin = self::minSimilarityMargin();
        if (
            $kioskMode
            && self::crossCameraRelaxEnabled()
            && $livenessScore !== null
            && $livenessScore >= self::crossCameraHighLivenessScore()
        ) {
            // Keep cross-camera mode accurate by enforcing a stronger top-1 vs top-2 margin.
            $minMargin = max($minMargin, self::crossCameraMinMargin());
        }

        $passing = [];
        foreach ($employees as $user) {
            if (! $user->hasRegisteredFace()) {
                continue;
            }
            $agg = self::aggregateBestMatchForUser($user, $incomingDescriptor, $kioskMode, $livenessScore);
            if ($agg === null || ! $agg['passes']) {
                continue;
            }
            $passing[] = [
                'user' => $user,
                'distance' => $agg['distance'],
                'similarity_score' => $agg['similarity_score'],
                'cmp' => $agg['cmp'],
            ];
        }

        if ($passing === []) {
            self::logIdentificationNearMiss($incomingDescriptor, $employees);

            return null;
        }

        usort($passing, static fn (array $a, array $b) => $a['cmp'] <=> $b['cmp']);
        $best = $passing[0];

        if (count($passing) >= 2) {
            $topN = array_slice($passing, 0, min(3, count($passing)));
            Log::info('Face identification top matches', [
                'matches' => array_map(fn ($p, $i) => [
                    'rank' => $i + 1,
                    'user_id' => config('attendance.face_log_identification_user_ids') ? $p['user']->id : null,
                    'similarity' => round($p['similarity_score'], 4),
                    'distance' => round($p['distance'], 4),
                ], $topN, array_keys($topN)),
                'margin' => round($passing[0]['similarity_score'] - $passing[1]['similarity_score'], 4),
                'min_margin_required' => $minMargin,
            ]);

            $second = $passing[1];
            if (($best['similarity_score'] - $second['similarity_score']) < $minMargin) {
                Log::warning('Face identification rejected: top matches too close in similarity', [
                    'margin_required' => $minMargin,
                    'best_similarity' => $best['similarity_score'],
                    'second_similarity' => $second['similarity_score'],
                ]);

                return null;
            }
        }

        return [
            'user' => $best['user'],
            'distance' => $best['distance'],
            'similarity_score' => $best['similarity_score'],
        ];
    }

    /**
     * Redis-backed identification for kiosk/global recognition. Loads company
     * embedding indexes from cache and queries the database only for the final
     * accepted employee row.
     *
     * @return array{user: User, distance: float, similarity_score: float, second_best_score?: float|null, margin_score?: float|null}|null
     */
    public static function identifyUserByFaceWithScoreFromCache(array $incomingDescriptor, bool $kioskMode = false, ?float $livenessScore = null, ?int $companyId = null): ?array
    {
        if (count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return null;
        }

        $index = FaceEmbeddingCacheService::getCompanyEmbeddingIndex($companyId);
        $employeePayloads = isset($index['employees']) && is_array($index['employees'])
            ? $index['employees']
            : [];

        $minMargin = self::minSimilarityMargin();
        if (
            $kioskMode
            && self::crossCameraRelaxEnabled()
            && $livenessScore !== null
            && $livenessScore >= self::crossCameraHighLivenessScore()
        ) {
            $minMargin = max($minMargin, self::crossCameraMinMargin());
        }

        $passing = [];
        $nearMiss = [];
        foreach ($employeePayloads as $employeeId => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $vectors = FaceEmbeddingCacheService::vectorsFromPayload($payload);
            $agg = self::aggregateBestMatchForVectors($vectors, $incomingDescriptor, $kioskMode, $livenessScore);
            if ($agg === null) {
                continue;
            }

            $resolvedEmployeeId = (int) ($payload['employee_id'] ?? $employeeId);
            $nearMiss[] = [
                'employee_id' => $resolvedEmployeeId,
                'similarity_score' => $agg['similarity_score'],
            ];

            if (! $agg['passes']) {
                continue;
            }

            $passing[] = [
                'employee_id' => $resolvedEmployeeId,
                'distance' => $agg['distance'],
                'similarity_score' => $agg['similarity_score'],
                'cmp' => $agg['cmp'],
            ];
        }

        if ($passing === []) {
            self::logIdentificationNearMissFromScores($nearMiss, count($employeePayloads));

            return null;
        }

        usort($passing, static fn (array $a, array $b) => $a['cmp'] <=> $b['cmp']);
        $best = $passing[0];

        if (count($passing) >= 2) {
            $topN = array_slice($passing, 0, min(3, count($passing)));
            Log::info('Face identification top matches', [
                'matches' => array_map(fn ($p, $i) => [
                    'rank' => $i + 1,
                    'user_id' => config('attendance.face_log_identification_user_ids') ? $p['employee_id'] : null,
                    'similarity' => round($p['similarity_score'], 4),
                    'distance' => round($p['distance'], 4),
                ], $topN, array_keys($topN)),
                'margin' => round($passing[0]['similarity_score'] - $passing[1]['similarity_score'], 4),
                'min_margin_required' => $minMargin,
                'company_id' => $companyId,
                'cache_key' => FaceEmbeddingCacheService::companyIndexKey($companyId),
            ]);

            $second = $passing[1];
            if (($best['similarity_score'] - $second['similarity_score']) < $minMargin) {
                Log::warning('Face identification rejected: top matches too close in similarity', [
                    'margin_required' => $minMargin,
                    'best_similarity' => $best['similarity_score'],
                    'second_similarity' => $second['similarity_score'],
                    'company_id' => $companyId,
                ]);

                return null;
            }
        }

        $user = self::faceIdentificationCandidateQuery()
            ->select([
                'id', 'name', 'email', 'role', 'is_active',
                'face_status', 'face_descriptor', 'face_descriptor_samples',
                'face_embedding', 'face_registered_at',
                'profile_image',
                'company_id',
                'updated_at',
            ])
            ->whereKey($best['employee_id'])
            ->first();

        if (! $user || ! $user->hasRegisteredFace() || $user->needsFaceReregistration()) {
            FaceEmbeddingCacheService::invalidateFaceCache((int) $best['employee_id'], $companyId);

            return null;
        }

        $secondBest = $passing[1]['similarity_score'] ?? null;

        return [
            'user' => $user,
            'distance' => $best['distance'],
            'similarity_score' => $best['similarity_score'],
            'second_best_score' => $secondBest,
            'margin_score' => $secondBest !== null ? $best['similarity_score'] - $secondBest : null,
        ];
    }

    /**
     * Verify incoming descriptor against one specific employee only (identity-bound).
     *
     * @return array{passes: bool, similarity_score: float, distance: float}|null
     */
    public static function verifySpecificUserByFaceWithScore(User $user, array $incomingDescriptor, ?float $livenessScore = null): ?array
    {
        if (! $user->isOperationallyActive() || ! $user->hasRegisteredFace() || count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return null;
        }

        $agg = self::aggregateBestMatchForUser($user, $incomingDescriptor, false, $livenessScore);
        if ($agg === null) {
            return null;
        }

        $minSimilarity = (float) config('attendance.face_identity_min_similarity_score', 0.55);
        $maxDistance = (float) config('attendance.face_identity_max_euclidean_distance', 1.0);
        if (
            self::crossCameraRelaxEnabled()
            && $livenessScore !== null
            && $livenessScore >= self::crossCameraHighLivenessScore()
        ) {
            $minSimilarity = max(
                self::crossCameraKioskMinSimilarityFloor(),
                $minSimilarity - self::crossCameraMinSimilarityRelaxDelta()
            );
            $maxDistance = min(1.25, $maxDistance + 0.08);
        }
        $passes = $agg['similarity_score'] >= $minSimilarity && $agg['distance'] <= $maxDistance;

        return [
            'passes' => $passes,
            'similarity_score' => (float) $agg['similarity_score'],
            'distance' => (float) $agg['distance'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $employees
     */
    private static function logIdentificationNearMiss(array $incomingDescriptor, $employees): void
    {
        if (! config('attendance.face_log_identification_misses', true)) {
            return;
        }

        $bestSim = -1.0;
        $bestUserId = null;
        foreach ($employees as $user) {
            $agg = self::aggregateBestMatchForUser($user, $incomingDescriptor);
            if ($agg === null) {
                continue;
            }
            if ($agg['similarity_score'] > $bestSim) {
                $bestSim = $agg['similarity_score'];
                $bestUserId = $user->id;
            }
        }

        Log::warning('Face identification miss (no user met match threshold)', [
            'pool_size' => $employees->count(),
            'best_cosine_similarity' => $bestSim >= 0 ? round($bestSim, 4) : null,
            'nearest_user_id' => ((bool) config('attendance.face_log_identification_user_ids', false)) ? $bestUserId : null,
            'uses_cosine' => self::useCosineThreshold(),
            'cosine_distance_threshold' => self::useCosineThreshold() ? self::cosineDistanceThreshold() : null,
            'euclidean_threshold' => self::useCosineThreshold() ? null : self::matchThreshold(),
            'min_similarity_required' => self::minSimilarityScore(),
        ]);
    }

    /**
     * @param  array<int, array{employee_id: int, similarity_score: float}>  $scores
     */
    private static function logIdentificationNearMissFromScores(array $scores, int $poolSize): void
    {
        if (! config('attendance.face_log_identification_misses', true)) {
            return;
        }

        $best = null;
        foreach ($scores as $score) {
            if ($best === null || $score['similarity_score'] > $best['similarity_score']) {
                $best = $score;
            }
        }

        Log::warning('Face identification miss (no cached employee met match threshold)', [
            'pool_size' => $poolSize,
            'best_cosine_similarity' => $best ? round((float) $best['similarity_score'], 4) : null,
            'nearest_user_id' => ($best && (bool) config('attendance.face_log_identification_user_ids', false)) ? $best['employee_id'] : null,
            'uses_cosine' => self::useCosineThreshold(),
            'cosine_distance_threshold' => self::useCosineThreshold() ? self::cosineDistanceThreshold() : null,
            'euclidean_threshold' => self::useCosineThreshold() ? null : self::matchThreshold(),
            'min_similarity_required' => self::minSimilarityScore(),
        ]);
    }

    /**
     * Strict global duplicate gate for face registration.
     *
     * Security invariants:
     *  1. ALWAYS queries the live database — never reads from the embedding index
     *     cache.  The cache can be stale (e.g. cleared between registrations, or
     *     not yet rebuilt after the previous successful enrolment).
     *  2. Compares the incoming vector against every valid 512-D sample and the
     *     averaged embedding stored for every other user.
     *  3. Uses L2-normalised vectors for both cosine AND Euclidean so the
     *     thresholds are geometry-consistent (cos 0.88 ≡ norm-euc ≈ 0.49).
     *  4. Logs every single-row score during the scan for auditability.
     *
     * Gate: flagged as duplicate when ANY stored vector from another user satisfies
     *   cosine(norm_a, norm_b) >= strict_min_cosine          (default 0.88)
     *   OR euclidean(norm_a, norm_b) <= strict_max_euc_norm  (default 0.35 ≡ cos ≈ 0.939)
     *
     * @param  array<int, float>  $incomingDescriptor  512-D ArcFace embedding (unit vector)
     * @param  int|null  $excludeUserId  User being registered/re-registered (exclude self)
     * @return array{user: User, similarity_score: float, euclidean_distance: float, detection_method: string}|null
     */
    public static function findExistingOwnerOfFaceStrictGlobal(array $incomingDescriptor, ?int $excludeUserId = null): ?array
    {
        if (count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            Log::warning('Face duplicate strict check: incoming descriptor has wrong dimension', [
                'expected' => self::EMBEDDING_DIM,
                'got' => count($incomingDescriptor),
                'exclude_user_id' => $excludeUserId,
            ]);

            return null;
        }

        $minCosine = (float) config('attendance.face_duplicate_strict_min_cosine_similarity', 0.88);
        $maxEucNorm = (float) config('attendance.face_duplicate_strict_max_euclidean_normalized', 0.35);
        $nearMissCosine = (float) config('attendance.face_duplicate_strict_near_miss_cosine', 0.72);
        $logUserIds = (bool) config('attendance.face_log_identification_user_ids', false);

        $incomingNorm = self::l2Normalize($incomingDescriptor);

        // Always scan the live database — never use cache for registration security.
        $query = self::duplicateScanUserQuery();
        if ($excludeUserId !== null) {
            $query->where('id', '!=', $excludeUserId);
        }
        $employees = $query->get();

        $bestCosine = -1.0;
        $bestEucNorm = PHP_FLOAT_MAX;
        $bestUserId = null;
        $rowsScanned = 0;

        foreach ($employees as $user) {
            $rows = self::duplicateComparisonRowsForUser($user);
            if ($rows === []) {
                continue;
            }

            foreach ($rows as $row) {
                $storedRaw = $row['vec'];
                $kind = $row['kind'];
                $rowsScanned++;

                // Normalise the stored vector — ArcFace returns unit vectors but
                // re-normalising is safe and guards against any DB precision drift.
                $storedNorm = self::l2Normalize($storedRaw);

                // Cosine similarity on normalised vectors (scale-invariant, primary metric).
                $cosine = self::cosineSimilarity($storedNorm, $incomingNorm);

                // Euclidean distance on normalised vectors (euc = sqrt(2*(1-cos)) for unit vecs).
                $eucNorm = self::euclideanDistance($storedNorm, $incomingNorm);

                if ($cosine > $bestCosine) {
                    $bestCosine = $cosine;
                    $bestEucNorm = $eucNorm;
                    $bestUserId = $user->id;
                }

                $isDuplicate = ($cosine >= $minCosine || $eucNorm <= $maxEucNorm);

                if ($isDuplicate) {
                    Log::warning('Face duplicate strict gate: MATCH — blocking registration', [
                        'existing_user_id' => $user->id,
                        'exclude_user_id' => $excludeUserId,
                        'row_kind' => $kind,
                        'cosine_similarity' => round($cosine, 4),
                        'euclidean_normalized' => round($eucNorm, 4),
                        'min_cosine_threshold' => $minCosine,
                        'max_euclidean_norm_threshold' => $maxEucNorm,
                        'rows_scanned_so_far' => $rowsScanned,
                    ]);

                    return [
                        'user' => $user,
                        'similarity_score' => round($cosine, 4),
                        'euclidean_distance' => round($eucNorm, 4),
                        'detection_method' => 'strict_global_db',
                    ];
                }

                // Near-miss: in the band [near_miss, strict_min) — log for threshold tuning.
                if ($cosine >= $nearMissCosine) {
                    Log::info('Face duplicate strict gate: near-miss (below threshold, not blocked)', [
                        'other_user_id' => $logUserIds ? $user->id : null,
                        'exclude_user_id' => $excludeUserId,
                        'row_kind' => $kind,
                        'cosine_similarity' => round($cosine, 4),
                        'euclidean_normalized' => round($eucNorm, 4),
                        'min_cosine_threshold' => $minCosine,
                        'near_miss_threshold' => $nearMissCosine,
                    ]);
                }
            }
        }

        Log::info('Face duplicate strict gate: scan complete — no duplicate found', [
            'exclude_user_id' => $excludeUserId,
            'rows_scanned' => $rowsScanned,
            'users_checked' => $employees->count(),
            'best_cosine_to_any_other' => $bestCosine >= 0 ? round($bestCosine, 4) : null,
            'best_euclidean_norm_to_any_other' => $bestEucNorm < PHP_FLOAT_MAX ? round($bestEucNorm, 4) : null,
            'nearest_other_user_id' => $logUserIds ? $bestUserId : null,
            'min_cosine_threshold' => $minCosine,
            'max_euclidean_norm_threshold' => $maxEucNorm,
        ]);

        return null;
    }

    /**
     * Check if the given face descriptor is already registered to another employee.
     * Used during registration to enforce one-face-per-account. Excludes the given user ID
     * (e.g. the user we are registering for) so re-registration or update is allowed.
     *
     * @param  array<int, float>  $incomingDescriptor  128D face descriptor
     * @param  int|null  $excludeUserId  User ID to exclude (e.g. current employee being registered)
     * @param  bool  $useExhaustiveDatabaseScan  When true, compares against every stored row in the DB (registration path). Skips the embedding index cache so no row is omitted.
     * @return array{user: User, similarity_score: float, detection_method: string}|null
     */
    public static function findExistingOwnerOfFaceWithDetails(array $incomingDescriptor, ?int $excludeUserId = null, bool $useExhaustiveDatabaseScan = false): ?array
    {
        if (count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return null;
        }

        $incomingNorm = self::l2Normalize($incomingDescriptor);
        $minCos = self::duplicateMinCosineSimilarity();
        $minCosAvg = self::duplicateMinCosineSimilarityAvg();
        $maxEuc = self::duplicateMaxEuclideanDistance();
        $maxEucNorm = self::duplicateMaxEuclideanNormalized();
        $nearMissMin = self::duplicateNearMissLogMinSimilarity();
        $aggregateBestMin = self::duplicateAggregateBestCosineMin();

        $useIndex = ! $useExhaustiveDatabaseScan && (bool) config('attendance.face_duplicate_use_embedding_index_cache', true);
        $rowsFlat = $useIndex
            ? self::getCachedDuplicateEmbeddingIndexRows()
            : null;

        $globalBestSim = -1.0;
        $globalBestRawSim = -1.0;
        $globalBestUserId = null;
        $globalBestDistance = PHP_FLOAT_MAX;
        $globalBestNormDistance = PHP_FLOAT_MAX;

        // Track best cosine per other user for aggregate best-of-all-samples check.
        $bestSimPerUser = [];

        if ($rowsFlat !== null) {
            foreach ($rowsFlat as $row) {
                $uid = (int) $row['user_id'];
                if ($excludeUserId !== null && $uid === $excludeUserId) {
                    continue;
                }
                $storedRaw = $row['vec'];
                $kind = $row['kind'];
                $storedN = self::l2Normalize($storedRaw);
                $cosineSim = self::cosineSimilarity($storedN, $incomingNorm);
                $rawCosineSim = self::cosineSimilarity($storedRaw, $incomingDescriptor);
                $distance = self::euclideanDistance($storedRaw, $incomingDescriptor);
                $normDistance = self::euclideanDistance($storedN, $incomingNorm);

                $effectiveSim = max($cosineSim, $rawCosineSim);
                if ($effectiveSim > $globalBestSim) {
                    $globalBestSim = $effectiveSim;
                    $globalBestRawSim = $rawCosineSim;
                    $globalBestUserId = $uid;
                    $globalBestDistance = $distance;
                    $globalBestNormDistance = $normDistance;
                }

                if (! isset($bestSimPerUser[$uid]) || $effectiveSim > $bestSimPerUser[$uid]) {
                    $bestSimPerUser[$uid] = $effectiveSim;
                }

                if (self::duplicateRowMatchesIncoming($cosineSim, $rawCosineSim, $distance, $normDistance, $kind, $minCos, $minCosAvg, $maxEuc, $maxEucNorm)) {
                    $owner = User::query()->whereKey($uid)->first();
                    if ($owner === null) {
                        continue;
                    }
                    $cosineGate = $kind === 'avg' ? $minCosAvg : $minCos;
                    Log::info('Face duplicate registration check: match (strict gate)', [
                        'existing_user_id' => $owner->id,
                        'exclude_user_id' => $excludeUserId,
                        'row_kind' => $kind,
                        'cosine_similarity_normalized' => round($cosineSim, 4),
                        'cosine_similarity_raw' => round($rawCosineSim, 4),
                        'euclidean_distance_raw' => round($distance, 4),
                        'euclidean_distance_normalized' => round($normDistance, 4),
                        'min_cosine_gate' => round($cosineGate, 4),
                        'max_euclidean_primary_raw' => $maxEuc,
                        'max_euclidean_primary_normalized' => $maxEucNorm,
                        'dual_signal_used' => self::duplicateDualSignalEnabled(),
                        'used_cached_index' => true,
                    ]);

                    return ['user' => $owner, 'similarity_score' => round($effectiveSim, 4), 'detection_method' => 'per_row_strict'];
                }
            }

            // Aggregate best-of-all-samples: if any user's best row exceeds the aggregate threshold, block.
            foreach ($bestSimPerUser as $uid => $bestSim) {
                if ($bestSim >= $aggregateBestMin) {
                    $owner = User::query()->whereKey($uid)->first();
                    if ($owner === null) {
                        continue;
                    }
                    Log::info('Face duplicate registration check: match (aggregate best-of-samples)', [
                        'existing_user_id' => $owner->id,
                        'exclude_user_id' => $excludeUserId,
                        'best_cosine_similarity' => round($bestSim, 4),
                        'aggregate_threshold' => $aggregateBestMin,
                        'used_cached_index' => true,
                    ]);

                    return ['user' => $owner, 'similarity_score' => round($bestSim, 4), 'detection_method' => 'aggregate_best'];
                }
            }
        } else {
            $employees = self::duplicateScanUserQuery();
            if ($excludeUserId !== null) {
                $employees->where('id', '!=', $excludeUserId);
            }
            $employees = $employees->get();

            foreach ($employees as $user) {
                if (! $user->hasRegisteredFace()) {
                    continue;
                }
                $rows = self::duplicateComparisonRowsForUser($user);
                if ($rows === []) {
                    continue;
                }

                $userBestSim = -1.0;

                foreach ($rows as $row) {
                    $storedRaw = $row['vec'];
                    $kind = $row['kind'];
                    $storedN = self::l2Normalize($storedRaw);
                    $cosineSim = self::cosineSimilarity($storedN, $incomingNorm);
                    $rawCosineSim = self::cosineSimilarity($storedRaw, $incomingDescriptor);
                    $distance = self::euclideanDistance($storedRaw, $incomingDescriptor);
                    $normDistance = self::euclideanDistance($storedN, $incomingNorm);

                    $effectiveSim = max($cosineSim, $rawCosineSim);
                    if ($effectiveSim > $globalBestSim) {
                        $globalBestSim = $effectiveSim;
                        $globalBestRawSim = $rawCosineSim;
                        $globalBestUserId = $user->id;
                        $globalBestDistance = $distance;
                        $globalBestNormDistance = $normDistance;
                    }
                    if ($effectiveSim > $userBestSim) {
                        $userBestSim = $effectiveSim;
                    }

                    if (self::duplicateRowMatchesIncoming($cosineSim, $rawCosineSim, $distance, $normDistance, $kind, $minCos, $minCosAvg, $maxEuc, $maxEucNorm)) {
                        $cosineGate = $kind === 'avg' ? $minCosAvg : $minCos;
                        Log::info('Face duplicate registration check: match (strict gate)', [
                            'existing_user_id' => $user->id,
                            'exclude_user_id' => $excludeUserId,
                            'row_kind' => $kind,
                            'cosine_similarity_normalized' => round($cosineSim, 4),
                            'cosine_similarity_raw' => round($rawCosineSim, 4),
                            'euclidean_distance_raw' => round($distance, 4),
                            'euclidean_distance_normalized' => round($normDistance, 4),
                            'min_cosine_gate' => round($cosineGate, 4),
                            'max_euclidean_primary_raw' => $maxEuc,
                            'max_euclidean_primary_normalized' => $maxEucNorm,
                            'dual_signal_used' => self::duplicateDualSignalEnabled(),
                            'used_cached_index' => false,
                        ]);

                        return ['user' => $user, 'similarity_score' => round($effectiveSim, 4), 'detection_method' => 'per_row_strict'];
                    }
                }

                // Aggregate best-of-all-samples for this user.
                if ($userBestSim >= $aggregateBestMin) {
                    Log::info('Face duplicate registration check: match (aggregate best-of-samples)', [
                        'existing_user_id' => $user->id,
                        'exclude_user_id' => $excludeUserId,
                        'best_cosine_similarity' => round($userBestSim, 4),
                        'aggregate_threshold' => $aggregateBestMin,
                        'used_cached_index' => false,
                    ]);

                    return ['user' => $user, 'similarity_score' => round($userBestSim, 4), 'detection_method' => 'aggregate_best'];
                }
            }
        }

        if ($useExhaustiveDatabaseScan && (bool) config('attendance.face_duplicate_log_registration_scan_summary', true)) {
            Log::info('Face duplicate registration scan complete (no cross-account match)', [
                'exclude_user_id' => $excludeUserId,
                'best_cosine_similarity_to_other' => $globalBestSim >= 0 ? round($globalBestSim, 4) : null,
                'best_raw_cosine_similarity' => $globalBestRawSim >= 0 ? round($globalBestRawSim, 4) : null,
                'best_raw_euclidean_at_best_cos' => $globalBestDistance < PHP_FLOAT_MAX ? round($globalBestDistance, 4) : null,
                'best_normalized_euclidean_at_best_cos' => $globalBestNormDistance < PHP_FLOAT_MAX ? round($globalBestNormDistance, 4) : null,
                'nearest_other_user_id' => ((bool) config('attendance.face_log_identification_user_ids', false)) ? $globalBestUserId : null,
                'min_cosine_primary' => round($minCos, 4),
                'aggregate_best_threshold' => $aggregateBestMin,
                'max_euclidean_primary_raw' => $maxEuc,
                'max_euclidean_primary_normalized' => $maxEucNorm,
                'exhaustive_db_scan' => true,
            ]);
        }

        $effectiveMinForNearMiss = min($minCos, $aggregateBestMin);
        if ($globalBestSim >= $nearMissMin && $globalBestSim < $effectiveMinForNearMiss && config('attendance.face_duplicate_log_near_misses', true)) {
            Log::warning('Face duplicate registration check: near-miss (below duplicate threshold)', [
                'best_cosine_similarity' => round($globalBestSim, 4),
                'nearest_user_id' => ((bool) config('attendance.face_log_identification_user_ids', false)) ? $globalBestUserId : null,
                'min_cosine_for_duplicate' => $effectiveMinForNearMiss,
                'note' => 'If legitimate users are blocked, lower min_cosine slightly; if different people match, raise it.',
            ]);
        }

        return null;
    }

    /**
     * Backward-compatible wrapper: returns only the User or null.
     *
     * @param  array<int, float>  $incomingDescriptor  128D face descriptor
     * @param  int|null  $excludeUserId  User ID to exclude
     * @param  bool  $useExhaustiveDatabaseScan  When true, full DB scan
     * @return User|null
     */
    public static function findExistingOwnerOfFace(array $incomingDescriptor, ?int $excludeUserId = null, bool $useExhaustiveDatabaseScan = false): ?User
    {
        $result = self::findExistingOwnerOfFaceWithDetails($incomingDescriptor, $excludeUserId, $useExhaustiveDatabaseScan);

        return $result ? $result['user'] : null;
    }

    /**
     * Combined registration duplicate gate: runs the strict global check first,
     * then falls through to the multi-signal check for defense-in-depth.
     *
     * The strict gate (cosine ≥ 0.88 / euc_norm ≤ 0.35) catches obvious same-face
     * registrations with near-identical captures.
     *
     * The multi-signal gate (cosine ≥ 0.65, raw euclidean, dual-signal, aggregate-best)
     * catches borderline same-person pairs that the strict single-pass misses —
     * e.g. same person under varied lighting, angle, or expression.
     *
     * Both always query the live database (never use the embedding index cache).
     *
     * @param  array<int, float>  $incomingDescriptor  512-D ArcFace embedding
     * @param  int|null  $excludeUserId  User being registered (exclude self)
     * @return array{user: User, similarity_score: float, euclidean_distance: float|null, detection_method: string}|null
     */
    public static function findDuplicateFaceForRegistration(array $incomingDescriptor, ?int $excludeUserId = null): ?array
    {
        if (count($incomingDescriptor) !== self::EMBEDDING_DIM) {
            return null;
        }

        $strictResult = self::findExistingOwnerOfFaceStrictGlobal($incomingDescriptor, $excludeUserId);
        if ($strictResult !== null) {
            Log::info('Registration duplicate: blocked by strict global gate', [
                'exclude_user_id' => $excludeUserId,
                'existing_user_id' => $strictResult['user']->id,
                'cosine_similarity' => $strictResult['similarity_score'],
                'euclidean_distance' => $strictResult['euclidean_distance'] ?? null,
                'detection_method' => $strictResult['detection_method'],
            ]);

            return $strictResult;
        }

        $multiResult = self::findExistingOwnerOfFaceWithDetails(
            $incomingDescriptor,
            $excludeUserId,
            true
        );
        if ($multiResult !== null) {
            Log::warning('Registration duplicate: strict gate passed but multi-signal gate caught duplicate', [
                'exclude_user_id' => $excludeUserId,
                'existing_user_id' => $multiResult['user']->id,
                'similarity_score' => $multiResult['similarity_score'],
                'detection_method' => $multiResult['detection_method'],
            ]);

            return [
                'user' => $multiResult['user'],
                'similarity_score' => $multiResult['similarity_score'],
                'euclidean_distance' => null,
                'detection_method' => 'multi_signal_'.$multiResult['detection_method'],
            ];
        }

        return null;
    }
}
