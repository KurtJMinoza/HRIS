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
        $clockOutOnly = User::factory()->create([
            'name' => 'Clock Out Only Employee',
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
        AttendanceLog::create([
            'user_id' => $clockOutOnly->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'verified_at' => Carbon::parse('2026-05-08 17:01:00', 'Asia/Manila')->utc(),
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
            $this->assertFalse((bool) $rowsById->get($clockOutOnly->id)['is_absent']);
            $this->assertFalse($rowsById->has($onLeave->id));
            $this->assertFalse($rowsById->has($restDay->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_dashboard_returns_today_and_upcoming_birthdays_for_active_employees(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-18 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'date_of_birth' => null,
        ]);
        $todayBirthday = User::factory()->create([
            'name' => 'Today Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'department' => 'Operations',
            'position' => 'Coordinator',
            'date_of_birth' => '1994-05-18',
        ]);
        $upcomingBirthday = User::factory()->create([
            'name' => 'Upcoming Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'department' => 'Finance',
            'position' => 'Analyst',
            'date_of_birth' => '1992-06-01',
        ]);
        $monthStartBirthday = User::factory()->create([
            'name' => 'Month Start Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1988-05-01',
        ]);
        $monthEndBirthday = User::factory()->create([
            'name' => 'Month End Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1989-05-31',
        ]);
        User::factory()->create([
            'name' => 'Deactivated Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => false,
            'date_of_birth' => '1990-05-18',
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

            $response->assertOk();
            $todayRows = collect($response->json('today_birthdays'));
            $monthRows = collect($response->json('current_month_birthdays'));
            $upcomingRows = collect($response->json('upcoming_30_days'));
            $upcoming90Rows = collect($response->json('upcoming_90_days'));

            $this->assertTrue($todayRows->contains('employee_id', $todayBirthday->id));
            $this->assertSame(0, $todayRows->firstWhere('employee_id', $todayBirthday->id)['days_until_birthday']);
            $this->assertTrue((bool) $todayRows->firstWhere('employee_id', $todayBirthday->id)['is_today']);
            $this->assertSame('05-18', $todayRows->firstWhere('employee_id', $todayBirthday->id)['birthday_month_day']);
            $this->assertSame('Monday', $todayRows->firstWhere('employee_id', $todayBirthday->id)['day_name']);
            $this->assertTrue($monthRows->contains('employee_id', $todayBirthday->id));
            $this->assertTrue($monthRows->contains('employee_id', $monthStartBirthday->id));
            $this->assertTrue($monthRows->contains('employee_id', $monthEndBirthday->id));
            $this->assertSame($monthStartBirthday->id, $monthRows->first()['employee_id']);
            $this->assertSame($monthEndBirthday->id, $monthRows->last()['employee_id']);
            $this->assertTrue($upcomingRows->contains('employee_id', $todayBirthday->id));
            $this->assertTrue($upcomingRows->contains('employee_id', $upcomingBirthday->id));
            $this->assertSame(14, $upcomingRows->firstWhere('employee_id', $upcomingBirthday->id)['days_until_birthday']);
            $this->assertTrue($upcoming90Rows->contains('employee_id', $upcomingBirthday->id));
            $this->assertFalse($todayRows->contains('full_name', 'Deactivated Celebrant'));
            $this->assertFalse($upcomingRows->contains('full_name', 'Deactivated Celebrant'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_dashboard_returns_empty_birthday_lists_when_none_are_in_range(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-18 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'date_of_birth' => null,
        ]);
        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1991-10-10',
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

            $response->assertOk()
                ->assertJsonPath('today_birthdays', [])
                ->assertJsonPath('current_month_birthdays', [])
                ->assertJsonPath('upcoming_30_days', [])
                ->assertJsonPath('upcoming_90_days', []);
        } finally {
            Carbon::setTestNow();
        }
    }
}
