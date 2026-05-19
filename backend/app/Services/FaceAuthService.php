<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
        $data = self::postJsonToFaceService(
            '/embed',
            ['image_base64' => $imageBase64],
            (int) config('services.face_verification.embed_timeout_seconds', 8),
            'embed'
        );

        if ($data === null) {
            return null;
        }

        $descriptor = isset($data['descriptor']) && is_array($data['descriptor'])
            ? array_map('floatval', array_values($data['descriptor']))
            : null;

        return [
            'descriptor' => $descriptor,
            'message' => (string) ($data['message'] ?? ''),
        ];
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
    public static function verifyFaceWithLivenessSession(string $sessionId, bool $forRegistration = false, ?int $employeeId = null): ?array
    {
        $registrationFloor = null;
        if ($forRegistration && ! (bool) config('attendance.face_registration_light_liveness', true)) {
            $registrationFloor = (float) config('attendance.face_registration_min_liveness_score', 0.62);
        }
        $result = RekognitionLivenessService::getSessionResults($sessionId, $registrationFloor);
        if ($result === null) {
            return null;
        }
        FaceLivenessSessionCacheService::put($sessionId, $result, $employeeId);
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
        $data = self::postJsonToFaceService(
            '/verify',
            ['image_base64' => $imageBase64],
            (int) config('services.face_verification.verify_timeout_seconds', 10),
            'verification'
        );

        if ($data === null) {
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
    public static function identifyUserWithScore(array $descriptor, bool $kioskMode = false, ?float $livenessScore = null, ?int $companyId = null): ?array
    {
        return FaceVerificationService::identifyUserByFaceWithScoreFromCache($descriptor, $kioskMode, $livenessScore, $companyId);
    }

    /**
     * @return list<string>
     */
    private static function faceServiceBaseUrls(): array
    {
        $urls = [];
        $appendUrls = static function (mixed $value) use (&$urls): void {
            foreach (explode(',', (string) $value) as $url) {
                $url = rtrim(trim($url), '/');
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        };

        $configured = config('services.face_verification.urls', []);
        if (is_array($configured)) {
            foreach ($configured as $url) {
                $appendUrls($url);
            }
        }

        $runtimeUrls = getenv('FACE_VERIFICATION_URLS');
        if (is_string($runtimeUrls)) {
            $appendUrls($runtimeUrls);
        }

        $runtimeUrl = getenv('FACE_VERIFICATION_URL');
        if (is_string($runtimeUrl)) {
            $appendUrls($runtimeUrl);
        }

        if ($urls === []) {
            $appendUrls(config('services.face_verification.url', 'http://127.0.0.1:5000'));
        }

        return array_values(array_unique($urls));
    }

    /**
     * Rotate configured face service endpoints so multiple warm Python
     * processes can share real-time clock-in/out embedding extraction.
     *
     * @return list<string>
     */
    private static function orderedFaceServiceUrls(): array
    {
        $urls = self::faceServiceBaseUrls();
        $count = count($urls);
        if ($count <= 1) {
            return $urls;
        }

        try {
            $counter = (int) Redis::connection()->incr('face:service:round-robin');
            Redis::connection()->expire('face:service:round-robin', 86400);
        } catch (\Throwable $e) {
            $counter = mt_rand(1, $count);
            Log::debug('Face service Redis round-robin unavailable; using local random endpoint order.', [
                'message' => $e->getMessage(),
            ]);
        }

        $offset = ($counter - 1) % $count;

        return array_merge(array_slice($urls, $offset), array_slice($urls, 0, $offset));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function postJsonToFaceService(string $path, array $payload, int $timeoutSeconds, string $context): ?array
    {
        $path = '/'.ltrim($path, '/');
        $urls = self::orderedFaceServiceUrls();

        foreach ($urls as $baseUrl) {
            $url = $baseUrl.$path;

            try {
                $response = Http::timeout($timeoutSeconds)
                    ->connectTimeout((int) config('services.face_verification.connect_timeout_seconds', 3))
                    ->post($url, $payload);

                if (! $response->successful()) {
                    Log::warning("Face {$context} service error", [
                        'face_service_url' => $url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    if ($response->clientError()) {
                        return null;
                    }

                    continue;
                }

                $data = $response->json();
                if (is_array($data)) {
                    return $data;
                }

                Log::warning("Face {$context} service returned invalid JSON", [
                    'face_service_url' => $url,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Face {$context} service exception", [
                    'face_service_url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::error("All face {$context} service endpoints failed", [
            'face_service_urls' => $urls,
        ]);

        return null;
    }
}
