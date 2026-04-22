<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaceAuthService
{
    /**
     * Extract 512D face embedding via InsightFace ArcFace (no anti-spoof).
     * Used with Amazon Rekognition Face Liveness: liveness is verified first
     * by Rekognition, then the reference image is passed here for embedding.
     *
     * @param  string  $imageBase64  Base64-encoded face image (Rekognition reference image)
     * @return array{descriptor: array|null, message: string}|null  Null on service error
     */
    public static function embedFace(string $imageBase64): ?array
    {
        $url = rtrim((string) config('services.face_verification.url', 'http://127.0.0.1:5000'), '/').'/embed';

        try {
            $response = Http::timeout((int) config('services.face_verification.embed_timeout_seconds', 8))
                ->connectTimeout((int) config('services.face_verification.connect_timeout_seconds', 3))
                ->post($url, [
                    'image_base64' => $imageBase64,
                ]);

            if (! $response->successful()) {
                Log::warning('Face embed service error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            $descriptor = isset($data['descriptor']) && is_array($data['descriptor'])
                ? array_map('floatval', array_values($data['descriptor']))
                : null;

            return [
                'descriptor' => $descriptor,
                'message' => (string) ($data['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::error('Face embed service exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Verify face using Amazon Rekognition Face Liveness session.
     * Gets reference image from the session, then extracts InsightFace embedding.
     * Liveness is evaluated before embedding (Rekognition session must PASS before /embed runs).
     *
     * @param  string  $sessionId  Rekognition Face Liveness session ID from frontend
     * @param  bool  $forRegistration  When true and face_registration_light_liveness is false, applies stricter registration floor
     * @return array{is_live: bool, descriptor: array|null, message: string, spoof_confidence?: float, reference_image_base64?: string}|null
     */
    public static function verifyFaceWithLivenessSession(string $sessionId, bool $forRegistration = false): ?array
    {
        $registrationFloor = null;
        if ($forRegistration && ! (bool) config('attendance.face_registration_light_liveness', true)) {
            $registrationFloor = (float) config('attendance.face_registration_min_liveness_score', 0.62);
        }
        $result = RekognitionLivenessService::getSessionResults($sessionId, $registrationFloor);
        if ($result === null) {
            return null;
        }
        if (! $result['is_live']) {
            return [
                'is_live' => false,
                'descriptor' => null,
                'message' => $result['message'] ?? 'Liveness check failed.',
                'spoof_confidence' => $result['confidence'] !== null ? $result['confidence'] / 100 : null,
            ];
        }
        $referenceBase64 = $result['reference_image_base64'] ?? null;
        if (empty($referenceBase64)) {
            return [
                'is_live' => true,
                'descriptor' => null,
                'message' => 'No reference image from liveness session.',
                'spoof_confidence' => $result['confidence'] !== null ? $result['confidence'] / 100 : null,
            ];
        }
        $embed = self::embedFace($referenceBase64);
        if ($embed === null) {
            return null;
        }
        $confidenceNorm = $result['confidence'] !== null ? (float) $result['confidence'] / 100 : null;

        return [
            'is_live' => true,
            'descriptor' => $embed['descriptor'],
            'message' => $embed['message'] ?: 'OK',
            'spoof_confidence' => $confidenceNorm,
            'reference_image_base64' => $referenceBase64,
        ];
    }

    /**
     * Legacy image path: verify + embed via Python /verify endpoint.
     * Kept for backward compatibility when the Rekognition liveness flow is unavailable.
     *
     * @return array{is_live: bool, descriptor: array|null, message: string, spoof_confidence?: float}|null
     */
    public static function verifyFaceForRegistration(string $imageBase64): ?array
    {
        $base = self::verifyFace($imageBase64);
        if ($base === null || empty($base['descriptor'])) {
            return $base;
        }
        $spoof = $base['spoof_confidence'] ?? null;
        if ($spoof !== null) {
            $light = (bool) config('attendance.face_registration_light_liveness', true);
            $min = $light
                ? (float) config('attendance.face_min_liveness_score', 0.52)
                : max(
                    (float) config('attendance.face_min_liveness_score', 0.52),
                    (float) config('attendance.face_registration_min_liveness_score', 0.62)
                );
            if ($spoof < $min) {
                return [
                    'is_live' => false,
                    'descriptor' => null,
                    'message' => 'Liveness confidence too low for registration. Please use a clearer capture or complete guided liveness.',
                    'spoof_confidence' => $spoof,
                ];
            }
        }

        return $base;
    }

    /**
     * Legacy: extract 512D descriptor via Python /verify endpoint.
     * Returns is_live=True and spoof_confidence=1.0 (liveness is always handled by Rekognition).
     *
     * @param  string  $imageBase64  Base64-encoded face image
     * @return array{is_live: bool, descriptor: array|null, message: string, spoof_confidence?: float}|null
     */
    public static function verifyFace(string $imageBase64): ?array
    {
        $url = rtrim((string) config('services.face_verification.url', 'http://127.0.0.1:5000'), '/').'/verify';

        try {
            $response = Http::timeout((int) config('services.face_verification.verify_timeout_seconds', 10))
                ->connectTimeout((int) config('services.face_verification.connect_timeout_seconds', 3))
                ->post($url, [
                    'image_base64' => $imageBase64,
                ]);

            if (! $response->successful()) {
                Log::warning('Face verification service error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            return [
                'is_live' => (bool) ($data['is_live'] ?? false),
                'descriptor' => isset($data['descriptor']) && is_array($data['descriptor'])
                    ? array_map('floatval', array_values($data['descriptor']))
                    : null,
                'message' => (string) ($data['message'] ?? ''),
                'spoof_confidence' => isset($data['spoof_confidence'])
                    ? (float) $data['spoof_confidence']
                    : null,
            ];
        } catch (\Throwable $e) {
            Log::error('Face verification service exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find user by face descriptor using FaceVerificationService.
     */
    public static function identifyUser(array $descriptor): ?User
    {
        return FaceVerificationService::identifyUserByFace($descriptor);
    }

    /**
     * Find user by face descriptor and return user with similarity score.
     *
     * @return array{user: User, distance: float, similarity_score: float}|null
     */
    public static function identifyUserWithScore(array $descriptor, bool $kioskMode = false, ?float $livenessScore = null): ?array
    {
        return FaceVerificationService::identifyUserByFaceWithScore($descriptor, $kioskMode, $livenessScore);
    }
}
