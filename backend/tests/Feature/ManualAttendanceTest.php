<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ManualAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_immediately_approved_manual_attendance_on_workday(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Manila'));

        $schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        try {
            $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
            $employee = User::factory()->create([
                'role' => User::ROLE_EMPLOYEE,
                'is_active' => true,
                'schedule' => $schedule,
            ]);

            $response = $this->actingAs($admin)->postJson('/api/admin/attendance/manual', [
                'employee_id' => $employee->id,
                'date' => '2026-08-05',
                'segments' => [['time_in' => '08:00', 'time_out' => '17:00']],
                'reason_code' => 'administrative_correction',
                'manual_remarks' => 'Device offline',
                'conflict_action' => 'create',
            ]);

            $response->assertCreated();

            $correction = AttendanceCorrection::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', '2026-08-05')
                ->first();

            $this->assertNotNull($correction);
            $this->assertTrue($correction->approved);
            $this->assertFalse((bool) $correction->pending_approval);
            $this->assertSame(AttendanceCorrection::SOURCE_ADMIN_MANUAL, $correction->source_type);
            $this->assertTrue($correction->is_manual);
            $this->assertSame($admin->id, $correction->approved_by_admin_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_attendance_list_requires_no_employee_portal_route(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);
        $this->actingAs($employee)->getJson('/api/admin/attendance/manual')->assertForbidden();
    }
}
