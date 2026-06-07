<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\BranchGeofenceSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\GeofenceValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeviceGeofenceValidationTest extends TestCase
{
    use DatabaseTransactions;

    private GeofenceValidationService $service;

    private Branch $branch;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GeofenceValidationService::class);
        $company = Company::query()->create(['name' => 'Geofence Test Company']);
        $this->branch = Branch::query()->create([
            'name' => 'AGDAO',
            'company_id' => $company->id,
            'geofence_enabled' => true,
            'geofence_enforcement_mode' => 'enforce',
        ]);
        $this->employee = User::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        BranchGeofenceSetting::query()->create([
            'branch_id' => $this->branch->id,
            'allow_without_geofence' => false,
        ]);
    }

    public function test_laptop_uses_150_meter_scope_while_mobile_uses_50_meter_scope(): void
    {
        $this->createCircle('Desktop/Laptop Office Radius', 150, 'desktop_laptop');
        $this->createCircle('Mobile/Tablet Office Radius', 50, 'mobile_tablet');
        $latitudeAtAboutOneHundredMeters = 7.00090;

        $laptop = $this->validate($latitudeAtAboutOneHundredMeters, 'laptop');
        $mobile = $this->validate($latitudeAtAboutOneHundredMeters, 'mobile');

        $this->assertTrue($laptop['allowed']);
        $this->assertSame('desktop_laptop', $laptop['device_scope_matched']);
        $this->assertEquals(150, $laptop['radius_meters']);
        $this->assertFalse($mobile['allowed']);
        $this->assertSame('outside', $mobile['validation_status']);
    }

    public function test_mobile_inside_its_own_fifty_meter_geofence_is_allowed(): void
    {
        $this->createCircle('Mobile/Tablet Office Radius', 50, 'mobile_tablet');

        $result = $this->validate(7.00036, 'mobile');

        $this->assertTrue($result['allowed']);
        $this->assertSame('mobile_tablet', $result['device_scope_matched']);
    }

    public function test_laptop_is_blocked_when_only_mobile_scope_exists(): void
    {
        $this->createCircle('Mobile only', 50, 'mobile');

        $result = $this->validate(7.0, 'laptop');

        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked', $result['validation_status']);
        $this->assertStringContainsString('matches this device', $result['failure_reason']);
    }

    public function test_allowed_branch_skips_geofence_and_writes_reason_even_without_coordinates(): void
    {
        $this->branch->geofenceSettings()->update(['allow_without_geofence' => true]);

        $result = $this->service->validateForEmployee($this->employee, null, null, null, [
            'clock_type' => 'clock_in',
            'device_type' => 'mobile',
            'method' => 'face',
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame('skipped', $result['validation_status']);
        $this->assertSame('branch_allowed_without_geofence', $result['skip_reason']);
        $consumed = $this->service->consumeValidationLog(
            $this->employee,
            $result['geofence_validation_id'],
            'clock_in',
            'face',
            $this->branch,
        );
        $this->assertTrue($consumed['allowed']);
        $this->assertSame('skipped', $consumed['validation_status']);
        $this->assertDatabaseHas('geofence_validation_logs', [
            'id' => $result['geofence_validation_id'],
            'validation_status' => 'skipped',
            'skip_reason' => 'branch_allowed_without_geofence',
            'device_type' => 'mobile',
        ]);
    }

    public function test_draft_geofence_is_not_used_for_validation(): void
    {
        $this->createCircle('Draft desktop area', 150, 'desktop_laptop', 'draft');

        $result = $this->validate(7.0, 'desktop');

        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked', $result['validation_status']);
    }

    public function test_desktop_scope_does_not_match_laptop(): void
    {
        $this->createCircle('Desktop only', 150, 'desktop');

        $desktop = $this->validate(7.0, 'desktop');
        $laptop = $this->validate(7.0, 'laptop');

        $this->assertTrue($desktop['allowed']);
        $this->assertFalse($laptop['allowed']);
    }

    private function validate(float $latitude, string $deviceType): array
    {
        return $this->service->validateForEmployee($this->employee, $latitude, 125.0, 20, [
            'device_type' => $deviceType,
            'log' => false,
        ]);
    }

    private function createCircle(
        string $name,
        int $radius,
        string $deviceScope,
        string $status = 'active',
    ): BranchGeofence {
        return BranchGeofence::query()->create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'type' => 'circle',
            'device_scope' => $deviceScope,
            'center_lat' => 7.0,
            'center_lng' => 125.0,
            'radius_meters' => $radius,
            'is_active' => $status === 'active',
            'status' => $status,
            'enforcement_mode' => 'enforce',
            'priority' => 1,
            'accuracy_threshold_meters' => 150,
        ]);
    }
}
