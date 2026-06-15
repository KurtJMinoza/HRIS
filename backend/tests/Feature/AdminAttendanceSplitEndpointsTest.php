<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\AdminAttendanceCacheService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AdminAttendanceSplitEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_endpoint_returns_lightweight_rows_with_attendance_id(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-06-12 09:00:00', 'Asia/Manila'));

        $schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => null,
        ];

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => $schedule,
        ]);

        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse('2026-06-12 08:05:00', 'Asia/Manila')->utc(),
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/attendance/list?from_date=2026-06-12&to_date=2026-06-12');
            $response->assertOk();

            $rows = $response->json('rows');
            $this->assertIsArray($rows);
            $this->assertNotEmpty($rows);

            $first = $rows[0];
            $this->assertArrayHasKey('attendance_id', $first);
            $this->assertArrayHasKey('employee_name', $first);
            $this->assertArrayHasKey('status', $first);
            $this->assertArrayNotHasKey('payroll_impact_hours', $first);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_details_lite_endpoint_returns_payroll_and_ot_summary(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-06-12 09:00:00', 'Asia/Manila'));

        $schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => null,
        ];

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => $schedule,
        ]);

        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse('2026-06-12 08:05:00', 'Asia/Manila')->utc(),
        ]);

        $attendanceId = AdminAttendanceCacheService::attendanceId((int) $employee->id, '2026-06-12');

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/attendance/'.$attendanceId.'/details-lite');
            $response->assertOk();
            $detail = $response->json('detail');
            $this->assertIsArray($detail);
            $this->assertArrayHasKey('overtime_summary', $detail);
            $this->assertArrayHasKey('payroll_impact', $detail);
            $this->assertArrayHasKey('nd_summary', $detail);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_search_requires_minimum_two_characters(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->getJson('/api/admin/attendance/list?from_date=2026-06-12&to_date=2026-06-12&search=a')
            ->assertStatus(422);
    }

    public function test_filters_and_counts_endpoints_respond(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->getJson('/api/admin/attendance/filters')
            ->assertOk()
            ->assertJsonStructure(['filters' => ['employees', 'companies', 'departments']]);

        $this->actingAs($admin)
            ->getJson('/api/admin/attendance/counts?from_date=2026-06-12&to_date=2026-06-12')
            ->assertOk()
            ->assertJsonStructure(['counts' => ['totals', 'total']]);
    }

    public function test_export_queue_returns_token(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance/export/queue', [
                'from_date' => '2026-06-12',
                'to_date' => '2026-06-12',
                'format' => 'csv',
            ])
            ->assertStatus(202)
            ->assertJsonStructure(['token', 'status']);
    }
}
