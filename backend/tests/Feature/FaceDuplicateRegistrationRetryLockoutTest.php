<?php

namespace Tests\Feature;

use App\Models\DuplicateFaceRegistrationAttempt;
use App\Models\User;
use App\Services\FaceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceDuplicateRegistrationRetryLockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_duplicate_attempt_blocks_registration_retries_for_target_user(): void
    {
        config(['attendance.face_duplicate_retry_lockout_minutes' => 1440]);

        $target = User::factory()->create();
        $existing = User::factory()->create();

        DuplicateFaceRegistrationAttempt::create([
            'attempted_for_user_id' => $target->id,
            'existing_user_id' => $existing->id,
            'similarity_score' => 0.83,
            'detection_method' => 'multi_signal_per_row_strict',
        ]);

        $this->assertTrue(FaceVerificationService::targetHasRecentDuplicateRegistrationBlock($target->id));
        $this->assertFalse(FaceVerificationService::targetHasRecentDuplicateRegistrationBlock($existing->id));
    }

    public function test_duplicate_retry_lockout_can_be_disabled(): void
    {
        config(['attendance.face_duplicate_retry_lockout_minutes' => 0]);

        $target = User::factory()->create();
        $existing = User::factory()->create();

        DuplicateFaceRegistrationAttempt::create([
            'attempted_for_user_id' => $target->id,
            'existing_user_id' => $existing->id,
            'similarity_score' => 0.83,
            'detection_method' => 'multi_signal_per_row_strict',
        ]);

        $this->assertFalse(FaceVerificationService::targetHasRecentDuplicateRegistrationBlock($target->id));
    }
}
