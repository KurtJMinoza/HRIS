<?php

namespace Tests\Feature;

use App\Models\AttendanceDailySummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceListReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_attendance_uses_read_model_when_summaries_exist(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'name' => 'Read Model Employee',
        ]);

        AttendanceDailySummary::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-12',
            'employee_name' => $employee->name,
            'status' => 'present',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'formatted_time_in' => '8:00 AM',
            'formatted_time_out' => '5:00 PM',
            'total_hours' => 8.0,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance?from_date=2026-06-12&to_date=2026-06-12');

        $response->assertOk()
            ->assertJsonPath('meta.source', 'read_model')
            ->assertJsonStructure(['rows' => [['attendance_id', 'employee_name', 'status']]]);

        $first = $response->json('rows.0');
        $this->assertArrayNotHasKey('payroll_impact_hours', $first);
    }

    public function test_admin_attendance_falls_back_when_summaries_missing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/attendance?from_date=2026-06-12&to_date=2026-06-12');

        $response->assertOk();
        $this->assertNotSame('read_model', $response->json('meta.source'));
    }

    public function test_search_and_department_filter_stay_on_read_model(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'name' => 'Filter Target Employee',
        ]);

        AttendanceDailySummary::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-12',
            'employee_name' => $employee->name,
            'department_name' => 'Ops',
            'status' => 'late',
            'total_hours' => 7.5,
        ]);
        AttendanceDailySummary::query()->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-11',
            'employee_name' => 'Other Person',
            'department_name' => 'Finance',
            'status' => 'present',
            'total_hours' => 8.0,
        ]);

        $bySearch = $this->actingAs($admin)->getJson(
            '/api/admin/attendance?from_date=2026-06-11&to_date=2026-06-12&search=Filter'
        );
        $bySearch->assertOk()
            ->assertJsonPath('meta.source', 'read_model');
        $this->assertCount(1, $bySearch->json('rows'));
        $this->assertSame('Filter Target Employee', $bySearch->json('rows.0.employee_name'));

        $byDept = $this->actingAs($admin)->getJson(
            '/api/admin/attendance?from_date=2026-06-11&to_date=2026-06-12&department=Ops'
        );
        $byDept->assertOk()
            ->assertJsonPath('meta.source', 'read_model');
        $this->assertCount(1, $byDept->json('rows'));
        $this->assertSame('Ops', $byDept->json('rows.0.department'));

        // 1-char search is ignored (no 422, full list for range).
        $short = $this->actingAs($admin)->getJson(
            '/api/admin/attendance?from_date=2026-06-11&to_date=2026-06-12&search=F'
        );
        $short->assertOk()->assertJsonPath('meta.source', 'read_model');
        $this->assertCount(2, $short->json('rows'));
    }
}
