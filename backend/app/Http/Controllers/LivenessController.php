<?php

namespace App\Http\Controllers;

use App\Services\RekognitionLivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LivenessController extends Controller
{
    /**
     * Create an Amazon Rekognition Face Liveness session for the Amplify UI FaceLivenessDetector.
     * Frontend uses sessionId and region to run guided liveness; then POSTs the sessionId to login/face or kiosk/face.
     */
    public function createSession(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'device_type' => ['nullable', 'string', 'in:mobile,tablet,laptop,desktop,kiosk'],
            'attendance_method' => ['nullable', 'string', 'max:32'],
        ]);

        Log::info('Face liveness session requested', [
            'employee_id' => $validated['employee_id'] ?? $request->user()?->id,
            'device_type_detected' => $validated['device_type'] ?? null,
            'attendance_method' => $validated['attendance_method'] ?? 'face',
            'liveness_session_requested_at' => now()->toIso8601String(),
            'user_agent' => $request->userAgent(),
        ]);

        $data = RekognitionLivenessService::createSession();
        if ($data === null) {
            Log::warning('Face liveness: createSession returned null (check AWS credentials)', [
                'liveness_session_finished_at' => now()->toIso8601String(),
                'liveness_session_duration' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => 'Face liveness service unavailable. Please try again later.',
                'errors' => ['session' => ['Could not create liveness session.']],
            ], 503);
        }
        if (isset($data['error'])) {
            Log::warning('Face liveness: createSession error', [
                'error' => $data['error'],
                'liveness_session_finished_at' => now()->toIso8601String(),
                'liveness_session_duration' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'message' => $data['error'],
                'errors' => ['session' => [$data['error']]],
            ], 503);
        }

        $response = [
            'sessionId' => $data['sessionId'],
            'region' => $data['region'],
        ];

        // Include Cognito Identity Pool so frontend can configure Amplify (required for FaceLivenessDetector)
        // Identity Pool MUST be in same region as Rekognition (us-east-1)
        $identityPoolId = config('services.cognito.identity_pool_id');
        if (! empty($identityPoolId)) {
            $response['cognitoIdentityPoolId'] = $identityPoolId;
            $response['cognitoRegion'] = config('services.cognito.region');
            $response['cognitoId'] = $identityPoolId; // alias for frontend hasCognitoId check
        }

        Log::info('Face liveness session created', [
            'employee_id' => $validated['employee_id'] ?? $request->user()?->id,
            'device_type_detected' => $validated['device_type'] ?? null,
            'attendance_method' => $validated['attendance_method'] ?? 'face',
            'liveness_session_created_at' => now()->toIso8601String(),
            'liveness_session_duration' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($response);
    }

    /**
     * Get Face Liveness session results (GET). Backend only – never call Rekognition directly from React.
     */
    public function getSessionResult(Request $request, string $sessionId): JsonResponse
    {
        return $this->sessionResultPayload($sessionId);
    }

    /**
     * Get Face Liveness session results (POST body). Same as GET /face/liveness/session/{id}; supports CSRF + JSON body for SPAs.
     */
    public function sessionResults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:255'],
        ]);

        return $this->sessionResultPayload($validated['session_id']);
    }

    /**
     * @return JsonResponse JSON aligned with GetFaceLivenessSessionResults (simplified for clients)
     */
    private function sessionResultPayload(string $sessionId): JsonResponse
    {
        $result = RekognitionLivenessService::getSessionResults($sessionId);
        if ($result === null) {
            Log::warning('Face liveness: getSessionResults returned null', ['sessionId' => $sessionId]);

            return response()->json([
                'message' => 'Could not retrieve liveness result.',
                'result' => 'FAIL',
            ], 503);
        }

        return response()->json([
            'result' => $result['result'] ?? ($result['is_live'] ? 'PASS' : 'FAIL'),
            'isLive' => $result['is_live'],
            'confidence' => $result['confidence'],
            'message' => $result['message'],
        ]);
    }
}
