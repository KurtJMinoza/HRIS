<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AdminDashboardAbsentAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_todays_attendance_includes_scheduled_absent_employees_from_start_of_day(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-08 08:30:00', 'Asia/Manila'));

        $schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $absent = User::factory()->create([
            'name' => 'Absent Employee',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => $schedule,
        ]);
        $present = User::factory()->create([
            'name' => 'Present Employee',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => $schedule,
        ]);
        $onLeave = User::factory()->create([
            'name' => 'Leave Employee',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => $schedule,
        ]);
        $restDay = User::factory()->create([
            'name' => 'Rest Employee',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'schedule' => array_replace($schedule, ['fri' => null]),
        ]);

        AttendanceLog::create([
            'user_id' => $present->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse('2026-05-08 08:01:00', 'Asia/Manila')->utc(),
        ]);
        LeaveRequest::create([
            'user_id' => $onLeave->id,
            'type' => 'vacation',
            'start_date' => '2026-05-08',
            'end_date' => '2026-05-08',
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

            $response->assertOk();
            $rowsById = collect($response->json('today_logs'))->keyBy('id');

            $this->assertSame(1, $response->json('stats.absent_today'));
            $this->assertTrue((bool) $rowsById->get($absent->id)['is_absent']);
            $this->assertSame('Absent', $rowsById->get($absent->id)['absent_label']);
            $this->assertFalse((bool) $rowsById->get($present->id)['is_absent']);
            $this->assertFalse($rowsById->has($onLeave->id));
            $this->assertFalse($rowsById->has($restDay->id));
        } finally {
            Carbon::setTestNow();
        }
    }
}
