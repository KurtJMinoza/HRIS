<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceVerificationService
{
    /** User-visible message when another employee already owns this face template. */
    public static function duplicateRegistrationUserMessage(): string
    {
        return 'This face is already registered to another employee. Please use a different face or contact HR.';
    }

    /**
     * Default face match distance threshold (Euclidean distance between 128D descriptors).
     * Match when distance <= threshold. Use config('attendance.face_match_threshold') for runtime value.
     */
    public const DEFAULT_MATCH_THRESHOLD = 0.45;

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
        return (float) config('attendance.face_cosine_distance_threshold', 0.35);
    }

    private static function minSimilarityScore(): float
    {
        return (float) config('attendance.face_min_similarity_score', 0.65);
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
            : (float) config('attendance.face_duplicate_min_best_cosine_similarity', 0.85);

        if (config('attendance.face_duplicate_enforce_registration_cosine_floor', true)) {
            $floor = (float) config('attendance.face_duplicate_registration_cosine_floor', 0.80);

            return max($resolved, $floor);
        }

        return $resolved;
    }

    /**
     * Secondary duplicate rule: L2 distance on raw 128-D ArcFace vectors (when cosine alone is ambiguous).
     */
    private static function duplicateMaxEuclideanDistance(): float
    {
        return (float) config('attendance.face_duplicate_max_euclidean', 0.65);
    }

    /**
     * Duplicate rule: L2 distance between L2-normalized 128-D vectors (same geometry as cosine similarity).
     * For unit vectors, distance d relates to cosine similarity as cos = 1 - d²/2 (small-angle regime).
     */
    private static function duplicateMaxEuclideanNormalized(): float
    {
        return (float) config('attendance.face_duplicate_max_euclidean_normalized', 0.55);
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
        return (float) config('attendance.face_duplicate_dual_cosine_min', 0.80);
    }

    private static function duplicateDualMaxEuclidean(): float
    {
        return (float) config('attendance.face_duplicate_dual_max_euclidean', 0.65);
    }

    /**
     * Aggregate best-across-all-samples threshold: if the BEST cosine similarity
     * across ALL stored vectors for a single other user exceeds this, flag as duplicate.
     * Lower than per-row gate because the "best sample" comparison is the most reliable signal.
     */
    private static function duplicateAggregateBestCosineMin(): float
    {
        return (float) config('attendance.face_duplicate_aggregate_best_cosine_min', 0.80);
    }

    /**
     * Also check raw (un-normalized) cosine similarity. ArcFace vectors are not always
     * unit-length; raw cosine can diverge from normalized cosine for high-norm vectors.
     */
    private static function duplicateRawCosineMin(): float
    {
        return (float) config('attendance.face_duplicate_raw_cosine_min', 0.80);
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
            $floor = (float) config('attendance.face_duplicate_registration_cosine_floor', 0.80);

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
     * L2-normalize a 128-D vector (stabilizes cosine / Euclidean comparisons across sessions).
     *
     * @param  array<int, float>  $v
     * @return array<int, float>
     */
    public static function l2Normalize128(array $v): array
    {
        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $x = (float) ($v[$i] ?? 0);
            $sum += $x * $x;
        }
        $n = sqrt($sum);
        if ($n < 1e-9) {
            return array_map('floatval', array_values($v));
        }
        $out = [];
        for ($i = 0; $i < 128; $i++) {
            $out[$i] = (float) ($v[$i] ?? 0) / $n;
        }

        return $out;
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

        // Expect 128-dimensional descriptor
        if (count($stored) !== 128 || count($incomingDescriptor) !== 128) {
            return false;
        }

        $distance = self::euclideanDistance($stored, $incomingDescriptor);

        return $distance <= self::matchThreshold();
    }

    /**
     * Euclidean distance between two 128D vectors.
     */
    public static function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $d = ($a[$i] ?? 0) - ($b[$i] ?? 0);
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /**
     * Cosine similarity (0–1) for L2-normalized vectors. For Facenet embeddings.
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < 128; $i++) {
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
     * Average multiple 128D descriptors element-wise.
     * Used to combine 5–10 samples for better match reliability.
     *
     * @param  array<int, array<int, float>>  $descriptors  Non-empty list of 128-length arrays
     * @return array<int, float>
     */
    public static function averageDescriptor(array $descriptors): array
    {
        if (empty($descriptors)) {
            return [];
        }

        $n = count($descriptors);
        $out = [];
        for ($i = 0; $i < 128; $i++) {
            $sum = 0.0;
            foreach ($descriptors as $d) {
                $sum += (float) ($d[$i] ?? 0);
            }
            $out[$i] = $sum / $n;
        }

        return $out;
    }

    /**
     * Get the effective descriptor for a user: average of face_descriptor_samples if present,
     * otherwise the single face_descriptor decoded to array. Returns null if no valid descriptor.
     *
     * @return array<int, float>|null
     */
    public static function getEffectiveDescriptor(User $user): ?array
    {
        $samples = $user->face_descriptor_samples;
        if (is_array($samples) && ! empty($samples)) {
            $valid = [];
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === 128) {
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
        if (! is_array($decoded) || isset($decoded['type']) || count($decoded) !== 128) {
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
        $candidates = [];
        $samples = $user->face_descriptor_samples;
        if (is_array($samples)) {
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === 128) {
                    $candidates[] = array_map('floatval', array_values($s));
                }
            }
        }

        $stored = $user->face_embedding ?? $user->face_descriptor;
        if (! empty($stored)) {
            $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
            if (is_array($decoded) && ! isset($decoded['type']) && count($decoded) === 128) {
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
        $rows = [];
        foreach (self::descriptorCandidatesForUser($user) as $vec) {
            if (count($vec) === 128) {
                $rows[] = ['vec' => $vec, 'kind' => 'sample'];
            }
        }

        if ($rows === []) {
            $eff = self::getEffectiveDescriptor($user);
            if ($eff !== null && count($eff) === 128) {
                $rows[] = ['vec' => $eff, 'kind' => 'sample'];
            }

            return $rows;
        }

        $validSampleCount = 0;
        $samples = $user->face_descriptor_samples;
        if (is_array($samples)) {
            foreach ($samples as $s) {
                if (is_array($s) && count($s) === 128) {
                    $validSampleCount++;
                }
            }
        }

        if ($validSampleCount >= 2) {
            $avg = self::getEffectiveDescriptor($user);
            if ($avg !== null && count($avg) === 128) {
                $rows[] = ['vec' => $avg, 'kind' => 'avg'];
            }
        }

        return $rows;
    }

    /**
     * Find an employee whose stored face descriptor best matches the given descriptor.
     * Returns the user with smallest distance if within MATCH_THRESHOLD, else null.
     *
     * @param  array<int, float>  $incomingDescriptor  128D descriptor
     */
    public static function identifyUserByFace(array $incomingDescriptor): ?User
    {
        $result = self::identifyUserByFaceWithScore($incomingDescriptor);

        return $result ? $result['user'] : null;
    }

    /**
     * Users eligible for kiosk / face login identification (must match hasRegisteredFace() intent).
     */
    public static function faceIdentificationCandidateQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
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
    public static function aggregateBestMatchForUser(User $user, array $incomingDescriptor, bool $kioskMode = false): ?array
    {
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $candidates = self::descriptorCandidatesForUserCached($user);
        if ($candidates === []) {
            $eff = self::getEffectiveDescriptor($user);
            if ($eff !== null && count($eff) === 128) {
                $candidates = [$eff];
            }
        }
        if ($candidates === []) {
            return null;
        }

        $incomingNorm = self::l2Normalize128($incomingDescriptor);

        $bestCosineSim = -1.0;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($candidates as $stored) {
            if (count($stored) !== 128) {
                continue;
            }
            $storedNorm = self::l2Normalize128($stored);
            $sNorm = self::cosineSimilarity($storedNorm, $incomingNorm);
            $sRaw = self::cosineSimilarity($stored, $incomingDescriptor);
            $s = max($sNorm, $sRaw);
            $d = self::euclideanDistance($stored, $incomingDescriptor);
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
     * @return array{user: User, distance: float, similarity_score: float}|null
     */
    public static function identifyUserByFaceWithScore(array $incomingDescriptor, bool $kioskMode = false): ?array
    {
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $employees = self::faceIdentificationCandidateQuery()
            ->select([
                'id', 'name', 'email', 'role', 'is_active',
                'face_status', 'face_descriptor', 'face_descriptor_samples',
                'face_embedding', 'face_registered_at',
                'profile_image', 'profile_image_url',
                'updated_at',
            ])
            ->get();
        $minMargin = self::minSimilarityMargin();

        $passing = [];
        foreach ($employees as $user) {
            if (! $user->hasRegisteredFace()) {
                continue;
            }
            $agg = self::aggregateBestMatchForUser($user, $incomingDescriptor, $kioskMode);
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
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $incomingNorm = self::l2Normalize128($incomingDescriptor);
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
                $storedN = self::l2Normalize128($storedRaw);
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
                    $storedN = self::l2Normalize128($storedRaw);
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
}
