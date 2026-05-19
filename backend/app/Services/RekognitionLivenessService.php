<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Rekognition\RekognitionClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class RekognitionLivenessService
{
    /** Face Liveness API is only available in these regions. */
    private const LIVENESS_REGIONS = ['us-east-1', 'us-east-2'];

    /**
     * Create a Face Liveness session for the frontend Amplify FaceLivenessDetector.
     * Returns sessionId and region. Frontend streams video to Rekognition using this session.
     *
     * @return array{sessionId: string, region: string}|array{error: string}|null
     */
    public static function createSession(): ?array
    {
        $config = config('services.rekognition');
        if (empty($config['key']) || empty($config['secret'])) {
            Log::warning('Rekognition Face Liveness: AWS credentials not configured');
            $msg = 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY must be set in .env. Run: php artisan config:clear';

            return self::failurePayload($msg);
        }

        $region = self::resolveApiRegion($config['region'] ?? 'us-east-1');

        try {
            $client = self::makeClient($config, $region);

            // ClientRequestToken must match ^[a-zA-Z0-9-_]+$ (no dots). uniqid(..., true) adds a dot.
            $result = $client->createFaceLivenessSession([
                'ClientRequestToken' => 'hr-liveness-'.bin2hex(random_bytes(8)),
            ]);

            $sessionId = $result->get('SessionId');
            if (! $sessionId) {
                return self::failurePayload('Rekognition returned no SessionId');
            }

            return [
                'sessionId' => $sessionId,
                'region' => $region,
            ];
        } catch (\Throwable $e) {
            $awsCode = $e instanceof AwsException ? $e->getAwsErrorCode() : null;
            Log::error('Rekognition CreateFaceLivenessSession failed', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'code' => $awsCode ?? $e->getCode(),
                'region' => $region,
            ]);

            return self::failurePayload(self::formatExceptionMessage($e, $awsCode));
        }
    }

    /**
     * Get Face Liveness session results. Call after frontend completes the liveness flow.
     *
     * @param  float|null  $minimumConfidenceFraction  Optional 0–1 floor (stricter than global min). Used for registration.
     * @return array{is_live: bool, confidence: float|null, reference_image_base64: string|null, message: string, result?: string}|null
     */
    public static function getSessionResults(string $sessionId, ?float $minimumConfidenceFraction = null): ?array
    {
        $cached = FaceLivenessSessionCacheService::get($sessionId);
        if ($cached !== null) {
            return [
                'is_live' => (bool) ($cached['is_live'] ?? false),
                'confidence' => $cached['confidence'] ?? null,
                'reference_image_base64' => $cached['reference_image_base64'] ?? null,
                'message' => (string) ($cached['message'] ?? ''),
                'result' => (string) ($cached['result'] ?? ($cached['status'] ?? 'FAIL')),
            ];
        }

        $config = config('services.rekognition');
        if (empty($config['key']) || empty($config['secret'])) {
            Log::warning('Rekognition Face Liveness: AWS credentials not configured');

            return null;
        }

        $region = self::resolveApiRegion($config['region'] ?? 'us-east-1');

        try {
            $client = self::makeClient($config, $region);

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

            $base = (float) config('attendance.face_min_liveness_score', 0.52);
            $extra = $minimumConfidenceFraction !== null ? (float) $minimumConfidenceFraction : $base;
            $minFraction = max($base, $extra);
            $minConfidence = $minFraction * 100;
            $confidenceVal = $confidence !== null ? (float) $confidence : null;
            $isLive = $confidenceVal !== null && $confidenceVal >= $minConfidence;

            if (! $isLive && $confidenceVal !== null) {
                Log::info('Rekognition liveness confidence below threshold', [
                    'session_id' => $sessionId,
                    'confidence' => round($confidenceVal, 2),
                    'min_required' => $minConfidence,
                ]);
            }

            $payload = [
                'is_live' => $isLive,
                'confidence' => $confidence !== null ? (float) $confidence : null,
                'reference_image_base64' => $referenceBase64,
                'message' => $isLive ? 'OK' : 'Liveness confidence too low.',
                'result' => $isLive ? 'PASS' : 'FAIL',
            ];

            FaceLivenessSessionCacheService::put($sessionId, $payload);

            return $payload;
        } catch (\Throwable $e) {
            Log::error('Rekognition GetFaceLivenessSessionResults failed', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'code' => $e instanceof AwsException ? $e->getAwsErrorCode() : $e->getCode(),
                'region' => $region,
            ]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    public static function supportedLivenessRegions(): array
    {
        return self::LIVENESS_REGIONS;
    }

    /**
     * Region used for CreateFaceLivenessSession / GetFaceLivenessSessionResults.
     */
    public static function resolveApiRegion(?string $configuredRegion): string
    {
        $configuredRegion = is_string($configuredRegion) ? trim($configuredRegion) : '';
        if ($configuredRegion !== '' && in_array($configuredRegion, self::LIVENESS_REGIONS, true)) {
            return $configuredRegion;
        }

        return 'us-east-1';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function makeClient(array $config, string $region): RekognitionClient
    {
        $connectTimeout = max(1, (int) ($config['connect_timeout_seconds'] ?? 10));
        $timeout = max($connectTimeout, (int) ($config['timeout_seconds'] ?? 30));

        return new RekognitionClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'http' => [
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
            ],
        ]);
    }

    /**
     * @return array{error: string}|null
     */
    private static function failurePayload(string $message): ?array
    {
        if (config('app.debug') || (function_exists('app') && app()->runningInConsole())) {
            return ['error' => $message];
        }

        return null;
    }

    private static function formatExceptionMessage(\Throwable $e, ?string $awsCode): string
    {
        if (self::isTimeoutException($e)) {
            $connect = max(1, (int) config('services.rekognition.connect_timeout_seconds', 10));
            $total = max($connect, (int) config('services.rekognition.timeout_seconds', 30));

            return "Timed out reaching AWS Rekognition (connect {$connect}s, request {$total}s). "
                .'Check internet access, firewall/proxy, and that REKOGNITION_REGION is us-east-1 or us-east-2. '
                .'Run: php artisan config:clear';
        }

        $msg = $e->getMessage();
        if ($awsCode) {
            $msg = "[{$awsCode}] {$msg}";
        }

        return $msg;
    }

    private static function isTimeoutException(\Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $ctx = $e->getHandlerContext();
            if (is_array($ctx) && isset($ctx['errno']) && (int) $ctx['errno'] === 28) {
                return true;
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
