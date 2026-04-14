<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FaceAuthService
{
    /**
     * Runtime options passed to DeepFace service endpoints.
     *
     * @return array<string, mixed>
     */
    private static function deepfaceOptions(): array
    {
        return [
            'model_name' => (string) config('services.face_verification.model_name', 'ArcFace'),
            'detector_backend' => (string) config('services.face_verification.detector_backend', 'mediapipe'),
            'enforce_detection' => (bool) config('services.face_verification.enforce_detection', true),
            'align' => (bool) config('services.face_verification.align', true),
            'input_width' => (int) config('services.face_verification.input_width', 640),
            'input_height' => (int) config('services.face_verification.input_height', 480),
        ];
    }

    /**
     * Extract 128D face descriptor only (no anti-spoof). Used with Amazon Rekognition Face Liveness.
     * Python service /embed endpoint.
     *
     * @param  string  $imageBase64  Base64-encoded face image (e.g. Rekognition reference image)
     * @return array{descriptor: array|null, message: string}|null Null on service error
     */
    public static function embedFace(string $imageBase64): ?array
    {
        $url = config('services.face_verification.url', 'http://127.0.0.1:5000');
        $url = rtrim($url, '/').'/embed';

        try {
            // Tight timeouts: embedding is usually sub-second; long waits feel like a stuck kiosk.
            $response = Http::timeout(12)
                ->connectTimeout(5)
                ->post($url, [
                    'image_base64' => $imageBase64,
                    'options' => self::deepfaceOptions(),
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
     * Verify face using Amazon Rekognition Face Liveness session: get reference image, extract descriptor, no local anti-spoof.
     *
     * @param  string  $sessionId  Rekognition Face Liveness session ID from frontend
     * @return array{is_live: bool, descriptor: array|null, message: string, spoof_confidence?: float, reference_image_base64?: string}|null
     */
    public static function verifyFaceWithLivenessSession(string $sessionId): ?array
    {
        $result = RekognitionLivenessService::getSessionResults($sessionId);
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
     * Legacy: verify face via Python /verify (embedding only when using Rekognition liveness).
     * Kept for backward compatibility if image_base64 flow is still used elsewhere.
     *
     * @param  string  $imageBase64  Base64-encoded face image
     * @return array{is_live: bool, descriptor: array|null, message: string, spoof_confidence?: float}|null
     */
    public static function verifyFace(string $imageBase64): ?array
    {
        $url = config('services.face_verification.url', 'http://127.0.0.1:5000');
        $url = rtrim($url, '/').'/verify';

        try {
            $response = Http::timeout(15)
                ->post($url, [
                    'image_base64' => $imageBase64,
                    'options' => self::deepfaceOptions(),
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
     * Find user by face descriptor and return user with distance for similarity score.
     *
     * @return array{user: User, distance: float}|null
     */
    public static function identifyUserWithScore(array $descriptor): ?array
    {
        return FaceVerificationService::identifyUserByFaceWithScore($descriptor);
    }
}
