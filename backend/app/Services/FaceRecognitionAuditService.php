<?php

namespace App\Services;

use App\Models\FaceRecognitionAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FaceRecognitionAuditService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(Request $request, array $data): void
    {
        try {
            FaceRecognitionAttempt::query()->create([
                'employee_id' => $data['employee_id'] ?? null,
                'matched_employee_id' => $data['matched_employee_id'] ?? null,
                'similarity_score' => $data['similarity_score'] ?? null,
                'second_best_score' => $data['second_best_score'] ?? null,
                'margin_score' => $data['margin_score'] ?? null,
                'liveness_score' => $data['liveness_score'] ?? null,
                'decision' => $data['decision'] ?? 'unknown',
                'reason' => $data['reason'] ?? null,
                'mode' => $data['mode'] ?? null,
                'device_id' => $data['device_id'] ?? FaceAttemptThrottleService::deviceId($request),
                'camera_info' => $data['camera_info'] ?? $request->input('camera_info'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to write face recognition audit log', [
                'message' => $e->getMessage(),
                'decision' => $data['decision'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]);
        }
    }
}
