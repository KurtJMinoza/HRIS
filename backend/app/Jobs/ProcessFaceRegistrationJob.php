<?php

namespace App\Jobs;

use App\Models\DuplicateFaceRegistrationAttempt;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\FaceAuthService;
use App\Services\FaceRegistrationStatusService;
use App\Services\FaceVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Face liveness + DeepFace embedding + duplicate check + DB write, serialized with locks to reduce races.
 *
 * Liveness runs before embedding in {@see FaceAuthService::verifyFaceWithLivenessSession()}.
 */
class ProcessFaceRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 45;

    public int $tries = 3;

    public function __construct(
        public string $trackId,
        public int $targetUserId,
        public ?string $livenessSessionId,
        public ?string $imageBase64,
        public string $livenessType,
        public ?int $actorUserId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public string $channel,
    ) {}

    public function handle(): void
    {
        FaceRegistrationStatusService::markProcessing($this->trackId);

        $result = $this->livenessSessionId
            ? FaceAuthService::verifyFaceWithLivenessSession($this->livenessSessionId, true)
            : FaceAuthService::verifyFaceForRegistration((string) $this->imageBase64);

        if ($result === null) {
            FaceRegistrationStatusService::fail(
                $this->trackId,
                'Face verification service unavailable. Please ensure the Python face service is running.',
                'service_unavailable'
            );

            return;
        }

        if (! $result['is_live']) {
            $msg = $result['message'] ?: 'Liveness check failed. Spoof detected. Please present a real face.';
            FaceRegistrationStatusService::fail($this->trackId, $msg, 'spoof_detected');

            return;
        }

        if (empty($result['descriptor']) || count($result['descriptor']) !== 128) {
            $msg = $result['message'] ?: 'No face detected or could not extract features. Position the face clearly in frame.';
            FaceRegistrationStatusService::fail($this->trackId, $msg, 'no_face_detected');

            return;
        }

        $descriptor = array_values(array_map('floatval', $result['descriptor']));
        $referenceImage = $result['reference_image_base64'] ?? null;

        // Per registering employee + global short lock so two accounts cannot commit the same face concurrently.
        $userLock = Cache::lock('face-registration-user:'.$this->targetUserId, 60);

        try {
            $userLock->block(20, function () use ($descriptor, $referenceImage, $result): void {
                Cache::lock('face-registration-dup-global', 20)->block(10, function () use ($descriptor, $referenceImage, $result): void {
                    DB::transaction(function () use ($descriptor, $referenceImage, $result): void {
                        $user = User::query()->whereKey($this->targetUserId)->lockForUpdate()->first();
                        if (! $user) {
                            FaceRegistrationStatusService::fail($this->trackId, 'User not found.', 'not_found');

                            return;
                        }

                        $existingOwner = FaceVerificationService::findExistingOwnerOfFace($descriptor, $user->id);
                        if ($existingOwner !== null) {
                            DuplicateFaceRegistrationAttempt::create([
                                'attempted_for_user_id' => $user->id,
                                'existing_user_id' => $existingOwner->id,
                                'ip_address' => $this->ipAddress,
                                'user_agent' => $this->userAgent,
                            ]);
                            FaceRegistrationStatusService::fail(
                                $this->trackId,
                                FaceVerificationService::duplicateRegistrationUserMessage(),
                                'face_already_registered'
                            );

                            return;
                        }

                        $samples = $user->face_descriptor_samples;
                        if (! is_array($samples)) {
                            $samples = [];
                        }
                        $maxSamples = (int) config('attendance.face_samples_max', 10);
                        $samples[] = $descriptor;
                        $samples = array_slice($samples, -$maxSamples);

                        $primaryEmbedding = json_encode($samples[0]);
                        $user->face_descriptor = $primaryEmbedding;
                        $user->face_embedding = $primaryEmbedding;
                        $user->face_descriptor_samples = $samples;
                        $user->face_image = $referenceImage;
                        $user->face_registered_at = now();
                        $user->face_status = 'registered';
                        $user->face_liveness_type = $this->livenessType ?: 'rekognition';
                        $user->save();

                        UserAdminActivityLog::query()->create([
                            'subject_user_id' => $user->id,
                            'actor_user_id' => $this->actorUserId ?? $user->id,
                            'action' => 'face_registered',
                            'meta' => [
                                'channel' => $this->channel,
                                'liveness_type' => $user->face_liveness_type,
                                'queued_job' => true,
                            ],
                            'ip_address' => $this->ipAddress,
                        ]);

                        FaceRegistrationStatusService::complete($this->trackId);
                    });
                });
            });
        } catch (LockTimeoutException $e) {
            Log::warning('ProcessFaceRegistrationJob lock timeout', [
                'track_id' => $this->trackId,
                'target_user_id' => $this->targetUserId,
                'message' => $e->getMessage(),
            ]);
            FaceRegistrationStatusService::fail(
                $this->trackId,
                'Face registration is taking longer than expected due to high traffic. Please retry in a moment or use QR code.',
                'registration_busy'
            );
        } catch (\Throwable $e) {
            Log::error('ProcessFaceRegistrationJob failed', [
                'track_id' => $this->trackId,
                'target_user_id' => $this->targetUserId,
                'message' => $e->getMessage(),
            ]);
            FaceRegistrationStatusService::fail($this->trackId, 'Face registration failed. Please try again.', 'job_failed');
            throw $e;
        }
    }
}
