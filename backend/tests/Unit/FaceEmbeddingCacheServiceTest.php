<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use App\Services\FaceEmbeddingCacheService;
use App\Services\FaceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FaceEmbeddingCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.face_store' => 'array',
            'attendance.face_cosine_distance_threshold' => 0.20,
            'attendance.face_kiosk_cosine_distance_threshold' => 0.20,
            'attendance.face_min_similarity_score' => 0.70,
            'attendance.face_kiosk_min_similarity_score' => 0.70,
            'attendance.face_min_similarity_margin' => 0.08,
            'attendance.face_identity_min_similarity_score' => 0.80,
            'attendance.face_identity_max_euclidean_distance' => 0.75,
        ]);
        Cache::store('array')->flush();
    }

    public function test_employee_registration_refreshes_employee_and_company_embedding_cache(): void
    {
        $company = Company::query()->create(['name' => 'Acme HR']);
        $employee = $this->employeeWithFace($company->id, $this->vector(0));

        $payload = FaceEmbeddingCacheService::refreshEmployeeFaceCache($employee->id);
        $index = FaceEmbeddingCacheService::refreshCompanyFaceIndex($company->id);

        $this->assertNotNull($payload);
        $this->assertSame($employee->id, $payload['employee_id']);
        $this->assertSame($company->id, $payload['company_id']);
        $this->assertNotEmpty($payload['embedding_vectors']);
        $this->assertArrayHasKey($employee->id, $index['employees']);
    }

    public function test_claimed_identity_verification_only_compares_the_claimed_employee(): void
    {
        $company = Company::query()->create(['name' => 'Claimed Identity Co']);
        $claimed = $this->employeeWithFace($company->id, $this->vector(0));
        $other = $this->employeeWithFace($company->id, $this->vector(1));

        FaceEmbeddingCacheService::refreshCompanyFaceIndex($company->id);

        $otherFaceAgainstClaimed = FaceVerificationService::verifySpecificUserByFaceWithScore($claimed->fresh(), $this->vector(1), 1.0);
        $claimedFaceAgainstClaimed = FaceVerificationService::verifySpecificUserByFaceWithScore($claimed->fresh(), $this->vector(0), 1.0);

        $this->assertFalse((bool) ($otherFaceAgainstClaimed['passes'] ?? false));
        $this->assertTrue((bool) ($claimedFaceAgainstClaimed['passes'] ?? false));
        $this->assertNotSame($other->id, $claimed->id);
    }

    public function test_kiosk_recognition_uses_company_index_and_rejects_ambiguous_matches(): void
    {
        $company = Company::query()->create(['name' => 'Kiosk Co']);
        $this->employeeWithFace($company->id, $this->vector(0));
        $this->employeeWithFace($company->id, $this->vector(0));

        FaceEmbeddingCacheService::refreshCompanyFaceIndex($company->id);

        $identified = FaceVerificationService::identifyUserByFaceWithScoreFromCache($this->vector(0), true, 1.0, $company->id);

        $this->assertNull($identified);
    }

    public function test_deactivated_employee_is_excluded_from_face_cache_and_cannot_verify(): void
    {
        $company = Company::query()->create(['name' => 'Inactive Co']);
        $employee = $this->employeeWithFace($company->id, $this->vector(0), false);

        $payload = FaceEmbeddingCacheService::refreshEmployeeFaceCache($employee->id);
        $index = FaceEmbeddingCacheService::refreshCompanyFaceIndex($company->id);
        $match = FaceVerificationService::verifySpecificUserByFaceWithScore($employee->fresh(), $this->vector(0), 1.0);

        $this->assertNull($payload);
        $this->assertArrayNotHasKey($employee->id, $index['employees']);
        $this->assertNull($match);
    }

    /**
     * @return array<int, float>
     */
    private function vector(int $axis): array
    {
        $vector = array_fill(0, FaceVerificationService::EMBEDDING_DIM, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }

    private function employeeWithFace(int $companyId, array $vector, bool $active = true): User
    {
        $primary = json_encode($vector);

        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => $active,
            'company_id' => $companyId,
            'face_status' => 'registered',
            'face_descriptor' => $primary,
            'face_embedding' => $primary,
            'face_descriptor_samples' => [$vector],
            'face_registered_at' => now(),
        ]);
    }
}
