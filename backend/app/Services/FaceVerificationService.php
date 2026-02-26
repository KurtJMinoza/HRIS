<?php

namespace App\Services;

use App\Models\User;

class FaceVerificationService
{
    /**
     * Face match distance threshold (Euclidean distance between 128D descriptors).
     * Match when distance <= threshold. For DTR use 0.45 or lower.
     * 0.4 = very strict, 0.45 = balanced, 0.5 = less strict.
     */
    public const MATCH_THRESHOLD = 0.45;

    /**
     * Verify that the provided descriptor matches the stored one.
     * Stored descriptor can be JSON array of 128 floats, or legacy image object (not verifiable).
     *
     * @param  string|null  $storedDescriptor  JSON string (array of 128 numbers or legacy object)
     * @param  array  $incomingDescriptor  Array of 128 numbers
     * @return bool
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

        return $distance <= self::MATCH_THRESHOLD;
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

        $stored = $user->face_descriptor;
        if (empty($stored)) {
            return null;
        }

        $decoded = json_decode($stored, true);
        if (! is_array($decoded) || isset($decoded['type']) || count($decoded) !== 128) {
            return null;
        }

        return array_map('floatval', array_values($decoded));
    }

    /**
     * Find an employee whose stored face descriptor best matches the given descriptor.
     * Returns the user with smallest distance if within MATCH_THRESHOLD, else null.
     *
     * @param  array<int, float>  $incomingDescriptor  128D descriptor
     * @return User|null
     */
    public static function identifyUserByFace(array $incomingDescriptor): ?User
    {
        if (count($incomingDescriptor) !== 128) {
            return null;
        }

        $employees = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->whereNotNull('face_descriptor')
            ->get();

        $bestUser = null;
        $bestDistance = self::MATCH_THRESHOLD + 1.0;

        foreach ($employees as $user) {
            $stored = self::getEffectiveDescriptor($user);
            if ($stored === null) {
                continue;
            }
            $distance = self::euclideanDistance($stored, $incomingDescriptor);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestUser = $user;
            }
        }

        return $bestDistance <= self::MATCH_THRESHOLD ? $bestUser : null;
    }
}
