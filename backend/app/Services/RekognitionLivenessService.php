<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Facades\Log;

class RekognitionLivenessService
{
    /**
     * Create a Face Liveness session for the frontend Amplify FaceLivenessDetector.
     * Returns sessionId and region. Frontend streams video to Rekognition using this session.
     *
     * @return array{sessionId: string, region: string}|array{error: string}|null Success, or error payload (when config('app.debug')), or null
     */
    public static function createSession(): ?array
    {
        $config = config('services.rekognition');
        if (empty($config['key']) || empty($config['secret'])) {
            Log::warning('Rekognition Face Liveness: AWS credentials not configured');
            $msg = 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set in .env. Run: php artisan config:clear';

            return config('app.debug') ? ['error' => $msg] : null;
        }

        // Face Liveness ONLY in us-east-1, us-east-2. Force us-east-1 if config has wrong region.
        $region = $config['region'] ?? 'us-east-1';
        if (! in_array($region, ['us-east-1', 'us-east-2'], true)) {
            $region = 'us-east-1';
        }

        try {
            $client = new RekognitionClient([
                'version' => 'latest',
                'region' => $region,
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            // ClientRequestToken must match ^[a-zA-Z0-9-_]+$ (no dots). uniqid(..., true) adds a dot.
            $result = $client->createFaceLivenessSession([
                'ClientRequestToken' => 'hr-liveness-'.bin2hex(random_bytes(8)),
            ]);

            $sessionId = $result->get('SessionId');
            if (! $sessionId) {
                return config('app.debug') ? ['error' => 'Rekognition returned no SessionId'] : null;
            }

            return [
                'sessionId' => $sessionId,
                'region' => $region,
            ];
        } catch (\Throwable $e) {
            $awsCode = $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : null;
            Log::error('Rekognition CreateFaceLivenessSession failed', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'code' => $awsCode ?? $e->getCode(),
            ]);
            $msg = $e->getMessage();
            if ($awsCode) {
                $msg = "[{$awsCode}] {$msg}";
            }

            return config('app.debug') ? ['error' => $msg] : null;
        }
    }

    /**
     * Get Face Liveness session results. Call after frontend completes the liveness flow.
     * Returns isLive, confidence (0-100), and referenceImageBase64 (JPEG) for face matching.
     *
     * @return array{is_live: bool, confidence: float|null, reference_image_base64: string|null, message: string}|null
     */
    public static function getSessionResults(string $sessionId): ?array
    {
        $config = config('services.rekognition');
        if (empty($config['key']) || empty($config['secret'])) {
            Log::warning('Rekognition Face Liveness: AWS credentials not configured');

            return null;
        }

        $region = $config['region'] ?? 'us-east-1';
        if (! in_array($region, ['us-east-1', 'us-east-2'], true)) {
            $region = 'us-east-1';
        }

        try {
            $client = new RekognitionClient([
                'version' => 'latest',
                'region' => $region,
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $result = $client->getFaceLivenessSessionResults([
                'SessionId' => $sessionId,
            ]);

            $status = $result->get('Status'); // CREATED | IN_PROGRESS | SUCCEEDED | FAILED | EXPIRED
            if ($status !== 'SUCCEEDED') {
                return [
                    'is_live' => false,
                    'confidence' => null,
                    'reference_image_base64' => null,
                    'message' => $status === 'FAILED' ? 'Liveness check failed.' : 'Session not completed.',
                    'result' => 'FAIL',
                ];
            }

            $confidence = $result->get('Confidence');
            $referenceImage = $result->get('ReferenceImage');
            $referenceBase64 = null;
            if ($referenceImage && isset($referenceImage['Bytes'])) {
                $bytes = $referenceImage['Bytes'];
                if (is_object($bytes) && method_exists($bytes, 'getContents')) {
                    $bytes = $bytes->getContents();
                }
                $referenceBase64 = is_string($bytes) ? base64_encode($bytes) : null;
            }

            // Validate confidence: Rekognition Face Liveness Confidence is 0–100 (real face vs spoof).
            // Lower threshold here (see config attendance.face_min_liveness_score) reduces false rejects
            // from lighting/angle; anti-spoof still relies on AWS + reference image embedding match.
            $minConfidence = (float) config('attendance.face_min_liveness_score', 0.52) * 100;
            $confidenceVal = $confidence !== null ? (float) $confidence : null;
            $isLive = $confidenceVal !== null && $confidenceVal >= $minConfidence;

            if (! $isLive && $confidenceVal !== null) {
                Log::info('Rekognition liveness confidence below threshold', [
                    'session_id' => $sessionId,
                    'confidence' => round($confidenceVal, 2),
                    'min_required' => $minConfidence,
                ]);
            }

            return [
                'is_live' => $isLive,
                'confidence' => $confidence !== null ? (float) $confidence : null,
                'reference_image_base64' => $referenceBase64,
                'message' => $isLive ? 'OK' : 'Liveness confidence too low.',
                'result' => $isLive ? 'PASS' : 'FAIL',
            ];
        } catch (\Throwable $e) {
            Log::error('Rekognition GetFaceLivenessSessionResults failed', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'code' => $e instanceof \Aws\Exception\AwsException ? $e->getAwsErrorCode() : $e->getCode(),
            ]);

            return null;
        }
    }
}
