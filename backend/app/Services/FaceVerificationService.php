<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FaceVerificationService
{
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

    /**
     * Duplicate registration: Euclidean max distance (only when cosine mode is off).
     * Same-person cross-session can exceed 0.5; too low allows a second account.
     */
    private static function duplicateMatchThreshold(): float
    {
        return (float) config('attendance.face_duplicate_match_threshold', 0.62);
    }

    /**
     * Duplicate registration: max cosine distance = 1 - similarity.
     * Slightly looser than clock-in match so two captures of the same face still collide.
     */
    private static function duplicateCosineDistanceThreshold(): float
    {
        return (float) config('attendance.face_duplicate_cosine_distance_threshold', 0.45);
    }

    /**
     * Looser max cosine distance when comparing to the averaged embedding (multi-sample users).
     */
    private static function duplicateCosineDistanceThresholdAvg(): float
    {
        return (float) config('attendance.face_duplicate_cosine_distance_threshold_avg', 0.52);
    }

    /**
     * Primary duplicate rule: max cosine similarity between the new embedding and ANY stored
     * vector (samples, primary, average) must be ≥ this. Catches same identity with different
     * expressions better than a single distance gate (expression shifts all rows somewhat).
     */
    private static function duplicateMinBestCosineSimilarity(): float
    {
        return (float) config('attendance.face_duplicate_min_best_cosine_similarity', 0.50);
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
     * multiple samples exist, the averaged embedding (looser threshold in findExistingOwnerOfFace).
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
            ->whereIn('role', [User::ROLE_EMPLOYEE, User::ROLE_ADMIN])
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
     * @return array{similarity_score: float, distance: float, cmp: float, passes: bool}|null
     */
    public static function aggregateBestMatchForUser(User $user, array $incomingDescriptor): ?array
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

        $bestCosineSim = -1.0;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($candidates as $stored) {
            if (count($stored) !== 128) {
                continue;
            }
            $d = self::euclideanDistance($stored, $incomingDescriptor);
            $s = self::cosineSimilarity($stored, $incomingDescriptor);
            if ($s > $bestCosineSim) {
                $bestCosineSim = $s;
                $bestDistance = $d;
            }
        }

        if ($bestCosineSim < 0) {
            return null;
        }

        $useCosine = self::useCosineThreshold();
        $euclideanThreshold = self::matchThreshold();
        $cosineThreshold = self::cosineDistanceThreshold();
        $minSim = self::minSimilarityScore();
        $cosineDist = 1.0 - $bestCosineSim;
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
     * @return array{user: User, distance: float, similarity_score: float}|null
     */
    public static function identifyUserByFaceWithScore(array $incomingDescriptor): ?array
    {
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $employees = self::faceIdentificationCandidateQuery()->get();
        $minMargin = self::minSimilarityMargin();

        $passing = [];
        foreach ($employees as $user) {
            if (! $user->hasRegisteredFace()) {
                continue;
            }
            $agg = self::aggregateBestMatchForUser($user, $incomingDescriptor);
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
     * @return User|null The existing user who already has this face, or null if unique
     */
    public static function findExistingOwnerOfFace(array $incomingDescriptor, ?int $excludeUserId = null): ?User
    {
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $incomingNorm = self::l2Normalize128($incomingDescriptor);

        $employees = User::query()
            ->whereIn('role', [User::ROLE_EMPLOYEE, User::ROLE_ADMIN])
            ->where(function ($q) {
                $q->where('face_status', 'registered')
                    ->orWhereNotNull('face_descriptor_samples')
                    ->orWhereNotNull('face_embedding')
                    ->orWhereNotNull('face_descriptor');
            });
        if ($excludeUserId !== null) {
            $employees->where('id', '!=', $excludeUserId);
        }
        $employees = $employees->get();

        $useCosine = self::useCosineThreshold();
        $euclideanThreshold = self::duplicateMatchThreshold();
        $cosineThreshold = self::duplicateCosineDistanceThreshold();
        $cosineThresholdAvg = self::duplicateCosineDistanceThresholdAvg();
        $bestSimMin = self::duplicateMinBestCosineSimilarity();

        foreach ($employees as $user) {
            if (! $user->hasRegisteredFace()) {
                continue;
            }
            $rows = self::duplicateComparisonRowsForUser($user);
            if ($rows === []) {
                continue;
            }

            if ($useCosine) {
                $maxSim = 0.0;
                foreach ($rows as $row) {
                    $storedN = self::l2Normalize128($row['vec']);
                    $sim = self::cosineSimilarity($storedN, $incomingNorm);
                    if ($sim > $maxSim) {
                        $maxSim = $sim;
                    }
                }
                if ($maxSim >= $bestSimMin) {
                    return $user;
                }

                foreach ($rows as $row) {
                    $storedRaw = $row['vec'];
                    $kind = $row['kind'];
                    $storedN = self::l2Normalize128($storedRaw);
                    $cosineSim = self::cosineSimilarity($storedN, $incomingNorm);
                    $cosineDist = 1.0 - $cosineSim;
                    $t = $kind === 'avg' ? $cosineThresholdAvg : $cosineThreshold;
                    if ($cosineDist <= $t) {
                        return $user;
                    }
                }
            } else {
                foreach ($rows as $row) {
                    $storedRaw = $row['vec'];
                    $distance = self::euclideanDistance($storedRaw, $incomingDescriptor);
                    if ($distance <= $euclideanThreshold) {
                        return $user;
                    }
                }
            }
        }

        return null;
    }
}
