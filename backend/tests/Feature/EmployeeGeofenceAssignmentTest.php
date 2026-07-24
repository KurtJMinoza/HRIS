<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\Company;
use App\Models\EmployeeGeofenceAssignment;
use App\Models\User;
use App\Services\EmployeeGeofenceAssignmentService;
use App\Services\GeofenceValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EmployeeGeofenceAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private GeofenceValidationService $service;

    private Branch $branch;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GeofenceValidationService::class);
        $company = Company::query()->create(['name' => 'Employee Geofence Co']);
        $this->branch = Branch::query()->create([
            'name' => 'Bajada',
            'company_id' => $company->id,
            'geofence_enabled' => true,
            'geofence_enforcement_mode' => 'enforce',
        ]);
        $this->employee = User::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
    }

    public function test_one_employee_multiple_geofences_allows_any_assigned_location(): void
    {
        $a = $this->createCircle('Bajada Office', 150);
        $b = $this->createCircle('Head Office', 150);
        $c = $this->createCircle('Client Site', 150);
        $this->assign($this->employee, $a, ['is_primary' => true]);
        $this->assign($this->employee, $b);
        $this->assign($this->employee, $c);

        $result = $this->service->validateForEmployee($this->employee, 7.0, 125.0, 20, [
            'device_type' => 'mobile',
            'log' => false,
        ]);

        $this->assertTrue($result['allowed']);
        $this->assertSame('inside', $result['validation_status']);
    }

    public function test_employee_exemption_skips_validation_for_selected_employee_only(): void
    {
        $other = User::factory()->create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $geofence = $this->createCircle('Branch Office', 50, 'mobile');
        $this->assign($other, $geofence, ['is_primary' => true]);

        app(EmployeeGeofenceAssignmentService::class)->createExemption([
            'employee_id' => (int) $this->employee->id,
            'effective_start_date' => now()->toDateString(),
            'effective_end_date' => now()->addDays(15)->toDateString(),
            'reason' => 'Official travel',
            'applicable_action' => 'both',
        ], $this->employee);

        $exempt = $this->service->validateForEmployee($this->employee, null, null, null, ['device_type' => 'mobile', 'log' => false]);
        $otherResult = $this->service->validateForEmployee($other, 7.001, 125.0, 20, ['device_type' => 'mobile', 'log' => false]);

        $this->assertTrue($exempt['allowed']);
        $this->assertSame('employee_geofence_exempt', $exempt['skip_reason']);
        $this->assertFalse($otherResult['allowed']);
    }

    public function test_temporary_geofence_expires_and_permanent_remains(): void
    {
        $permanent = $this->createCircle('Bajada Office', 150);
        $temporary = $this->createCircle('Client Site', 150);
        $this->assign($this->employee, $permanent, ['is_primary' => true]);
        $this->assign($this->employee, $temporary, [
            'assignment_type' => 'temporary',
            'effective_end_date' => now()->subDay()->toDateString(),
        ]);

        $resolver = app(\App\Services\EmployeeGeofenceResolver::class);
        $resolved = $resolver->resolveForAttendance((int) $this->employee->id, now(), 'clock_in');
        $names = collect($resolved['allowed_geofences'] ?? [])->pluck('name')->all();

        $this->assertSame(['Bajada Office'], $names);
    }

    public function test_branch_bypass_no_longer_skips_validation(): void
    {
        $this->branch->geofenceSettings()->create(['allow_without_geofence' => true]);

        $result = $this->service->validateForEmployee($this->employee, null, null, null, [
            'clock_type' => 'clock_in',
            'device_type' => 'mobile',
            'log' => false,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked', $result['validation_status']);
    }

    private function assign(User $employee, BranchGeofence $geofence, array $overrides = []): EmployeeGeofenceAssignment
    {
        return EmployeeGeofenceAssignment::query()->create([
            'employee_id' => (int) $employee->id,
            'geofence_id' => (int) $geofence->id,
            'assignment_type' => 'permanent',
            'validation_mode' => 'any_assigned_geofence',
            'is_primary' => (bool) ($overrides['is_primary'] ?? false),
            'effective_start_date' => now()->subDay()->toDateString(),
            'effective_end_date' => $overrides['effective_end_date'] ?? null,
            'status' => 'active',
            'clock_in_applies' => true,
            'clock_out_applies' => true,
            ...$overrides,
        ]);
    }

    private function createCircle(string $name, int $radius = 150, string $scope = 'all_devices'): BranchGeofence
    {
        return BranchGeofence::query()->create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'type' => 'circle',
            'device_scope' => $scope,
            'center_lat' => 7.0,
            'center_lng' => 125.0,
            'radius_meters' => $radius,
            'is_active' => true,
            'status' => 'active',
            'enforcement_mode' => 'enforce',
            'priority' => 1,
            'accuracy_threshold_meters' => 150,
        ]);
    }
}
