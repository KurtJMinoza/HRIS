<?php

namespace App\Jobs;

use App\Models\DuplicateFaceRegistrationAttempt;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\FaceAuthService;
use App\Services\FaceEmbeddingCacheService;
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
use Throwable;

/**
 * Face liveness + InsightFace embedding + duplicate check + DB write, serialized with locks to reduce races.
 *
 * Liveness runs before embedding in {@see FaceAuthService::verifyFaceWithLivenessSession()}.
 *
 * Requires a queue worker listening on the `face-registration` queue (see .env.example).
 * The worker `--timeout` value should be greater than this job's `$timeout` (e.g. 150 seconds).
 */
class ProcessFaceRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds before the worker kills this job.
     * InsightFace ONNX inference + Rekognition liveness fetch + global duplicate scan + DB write.
     * Set higher than the Python embed timeout (8s) + duplicate scan time + lock waits.
     */
    public int $timeout = 120;

    public int $tries = 2;

    /** Seconds to wait before each retry after a failure. */
    public int $backoff = 15;

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
    ) {
        $this->onConnection('redis')->onQueue('face-registration');
    }

    /**
     * After all retries are exhausted, ensure polling clients do not hang on "processing" forever.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessFaceRegistrationJob permanently failed after retries', [
            'track_id' => $this->trackId,
            'target_user_id' => $this->targetUserId,
            'attempts' => $this->attempts(),
            'exception' => $exception instanceof Throwable
                ? $exception::class.': '.$exception->getMessage()
                : null,
        ]);

        $state = FaceRegistrationStatusService::get($this->trackId);
        $status = is_array($state) ? ($state['status'] ?? '') : '';
        if (in_array($status, ['pending', 'processing'], true)) {
            FaceRegistrationStatusService::fail(
                $this->trackId,
                'Face registration could not be completed. Ensure a queue worker is running for the face-registration queue, then try again.',
                'job_failed'
            );
        }
    }

    public function handle(): void
    {
        Log::info('ProcessFaceRegistrationJob started', [
            'track_id' => $this->trackId,
            'target_user_id' => $this->targetUserId,
            'channel' => $this->channel,
            'has_liveness_session' => $this->livenessSessionId !== null,
            'queue' => $this->queue,
        ]);

        FaceRegistrationStatusService::markProcessing($this->trackId);

        $result = $this->livenessSessionId
            ? FaceAuthService::verifyFaceWithLivenessSession($this->livenessSessionId, true, $this->targetUserId)
            : FaceAuthService::verifyFaceForRegistration((string) $this->imageBase64);

        if ($result === null) {
            Log::warning('ProcessFaceRegistrationJob: face verification returned null (service down or timeout)', [
                'track_id' => $this->trackId,
                'target_user_id' => $this->targetUserId,
            ]);
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

        if (empty($result['descriptor']) || count($result['descriptor']) !== FaceVerificationService::EMBEDDING_DIM) {
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
                    $bumpDuplicateIndex = false;
                    $registeredOk = false;
                    DB::transaction(function () use ($descriptor, $referenceImage, $result, &$bumpDuplicateIndex, &$registeredOk): void {
                        $user = User::query()->whereKey($this->targetUserId)->lockForUpdate()->first();
                        if (! $user) {
                            FaceRegistrationStatusService::fail($this->trackId, 'User not found.', 'not_found');

                            return;
                        }

                        if ($user->hasRegisteredFace()) {
                            Log::info('ProcessFaceRegistrationJob: target user already has a registered face — proceeding with secure re-registration (duplicate check still runs against all other users)', [
                                'track_id' => $this->trackId,
                                'target_user_id' => $user->id,
                                'is_reregistration' => true,
                            ]);
                        }

                        // ── Critical anti-duplicate gate (defense-in-depth) ─────────────────
                        // Two-tier check against EVERY stored embedding in the database:
                        //
                        // Tier 1 — Strict global gate (definitive same-face):
                        //   Cosine ≥ 0.88  →  blocked  (near-identical capture)
                        //   Norm-Euclidean ≤ 0.35 → blocked
                        //
                        // Tier 2 — Multi-signal gate (borderline same-person):
                        //   Per-row cosine ≥ 0.65–0.70, raw/norm Euclidean, dual-signal,
                        //   aggregate best-of-all-samples ≥ 0.65.
                        //   Catches the same person under varied lighting/angle/expression.
                        //
                        // Both tiers query the live DB (no cache) inside per-user + global
                        // lock + DB transaction to prevent race conditions.
                        Log::info('ProcessFaceRegistrationJob: starting combined duplicate scan (strict + multi-signal)', [
                            'track_id' => $this->trackId,
                            'target_user_id' => $user->id,
                            'embedding_dim' => count($descriptor),
                            'strict_cosine_gate' => (float) config('attendance.face_duplicate_strict_min_cosine_similarity', 0.88),
                            'strict_euclidean_norm_gate' => (float) config('attendance.face_duplicate_strict_max_euclidean_normalized', 0.35),
                            'multi_signal_cosine_gate' => (float) config('attendance.face_duplicate_min_best_cosine_similarity', 0.70),
                            'multi_signal_aggregate_gate' => (float) config('attendance.face_duplicate_aggregate_best_cosine_min', 0.65),
                        ]);

                        $dupResult = FaceVerificationService::findDuplicateFaceForRegistration(
                            $descriptor,
                            $user->id
                        );

                        if ($dupResult !== null) {
                            $existingOwner = $dupResult['user'];
                            DuplicateFaceRegistrationAttempt::create([
                                'attempted_for_user_id' => $user->id,
                                'existing_user_id' => $existingOwner->id,
                                'similarity_score' => $dupResult['similarity_score'] ?? null,
                                'detection_method' => $dupResult['detection_method'] ?? null,
                                'ip_address' => $this->ipAddress,
                                'user_agent' => $this->userAgent,
                            ]);
                            Log::warning('ProcessFaceRegistrationJob: DUPLICATE FACE — registration blocked', [
                                'track_id' => $this->trackId,
                                'attempted_for_user_id' => $user->id,
                                'existing_user_id' => $existingOwner->id,
                                'cosine_similarity' => $dupResult['similarity_score'] ?? null,
                                'euclidean_norm_distance' => $dupResult['euclidean_distance'] ?? null,
                                'detection_method' => $dupResult['detection_method'] ?? null,
                            ]);
                            FaceEmbeddingCacheService::invalidateFaceCache((int) $user->id, $user->company_id ? (int) $user->company_id : null);
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
                        $bumpDuplicateIndex = true;
                        $registeredOk = true;
                    });
                    if ($bumpDuplicateIndex) {
                        FaceVerificationService::bumpDuplicateEmbeddingIndexVersion();
                    }
                    if ($registeredOk) {
                        FaceEmbeddingCacheService::refreshAfterFaceChange($this->targetUserId);
                        Log::info('ProcessFaceRegistrationJob succeeded', [
                            'track_id' => $this->trackId,
                            'target_user_id' => $this->targetUserId,
                        ]);
                    }
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
                'Face registration is taking longer than expected due to high traffic. Please wait a moment and try again.',
                'registration_busy'
            );
        } catch (\Throwable $e) {
            Log::error('ProcessFaceRegistrationJob failed', [
                'track_id' => $this->trackId,
                'target_user_id' => $this->targetUserId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            FaceRegistrationStatusService::fail($this->trackId, 'Face registration failed. Please try again.', 'job_failed');
            throw $e;
        }
    }
}
