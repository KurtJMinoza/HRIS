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
            $this->assertSame(32, $todayRows->firstWhere('employee_id', $todayBirthday->id)['current_age']);
            $this->assertSame(32, $todayRows->firstWhere('employee_id', $todayBirthday->id)['next_age']);
            $this->assertSame('today', $todayRows->firstWhere('employee_id', $todayBirthday->id)['birthday_status']);
            $this->assertSame(14, $upcomingRows->firstWhere('employee_id', $upcomingBirthday->id)['days_until_birthday']);
            $this->assertSame(34, $upcomingRows->firstWhere('employee_id', $upcomingBirthday->id)['next_age']);
            $this->assertSame('upcoming', $upcomingRows->firstWhere('employee_id', $upcomingBirthday->id)['birthday_status']);
            $this->assertTrue($monthRows->contains('employee_id', $todayBirthday->id));
            $this->assertTrue($monthRows->contains('employee_id', $monthStartBirthday->id));
            $this->assertTrue($monthRows->contains('employee_id', $monthEndBirthday->id));
            $this->assertSame($monthStartBirthday->id, $monthRows->first()['employee_id']);
            $this->assertSame($monthEndBirthday->id, $monthRows->last()['employee_id']);
            $this->assertTrue($upcomingRows->contains('employee_id', $todayBirthday->id));
            $this->assertTrue($upcomingRows->contains('employee_id', $upcomingBirthday->id));
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

    public function test_admin_dashboard_birthdays_endpoint_returns_past_month_birthdays(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-18 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $aprilBirthday = User::factory()->create([
            'name' => 'April Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1990-04-12',
        ]);
        User::factory()->create([
            'name' => 'May Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1991-05-10',
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard/birthdays?year=2026&month=4');

            $response->assertOk()
                ->assertJsonPath('year', 2026)
                ->assertJsonPath('month', 4)
                ->assertJsonPath('is_past_month', true)
                ->assertJsonPath('is_current_month', false)
                ->assertJsonPath('can_go_next', true)
                ->assertJsonPath('birthday_month_label', 'April 2026');

            $rows = collect($response->json('birthdays'));
            $this->assertTrue($rows->contains('employee_id', $aprilBirthday->id));
            $this->assertFalse($rows->contains('full_name', 'May Celebrant'));
            $this->assertTrue((bool) $rows->firstWhere('employee_id', $aprilBirthday->id)['birthday_passed_in_view']);
            $this->assertSame('Sunday', $rows->firstWhere('employee_id', $aprilBirthday->id)['day_name']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_dashboard_birthdays_endpoint_returns_future_month_birthdays(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-18 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $juneBirthday = User::factory()->create([
            'name' => 'June Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1993-06-01',
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard/birthdays?year=2026&month=6');

            $response->assertOk()
                ->assertJsonPath('year', 2026)
                ->assertJsonPath('month', 6)
                ->assertJsonPath('is_future_month', true)
                ->assertJsonPath('is_current_month', false)
                ->assertJsonPath('birthday_month_label', 'June 2026');

            $rows = collect($response->json('birthdays'));
            $this->assertTrue($rows->contains('employee_id', $juneBirthday->id));
            $this->assertFalse((bool) $rows->firstWhere('employee_id', $juneBirthday->id)['birthday_passed_in_view']);
            $this->assertTrue((bool) $rows->firstWhere('employee_id', $juneBirthday->id)['birthday_upcoming_in_view']);
            $this->assertSame(14, $rows->firstWhere('employee_id', $juneBirthday->id)['days_until_birthday']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_dashboard_birthdays_include_upcoming_age_fields(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-19 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $todayCelebrant = User::factory()->create([
            'name' => 'Maria Santos',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1996-05-19',
        ]);
        $tomorrowCelebrant = User::factory()->create([
            'name' => 'Ana Garcia',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1997-05-20',
        ]);
        $upcomingCelebrant = User::factory()->create([
            'name' => 'Juan Dela Cruz',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '2000-05-29',
        ]);
        User::factory()->create([
            'name' => 'Past Celebrant',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'date_of_birth' => '1990-05-10',
        ]);

        try {
            $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');
            $response->assertOk();

            $todayRow = collect($response->json('today_birthdays'))
                ->firstWhere('employee_id', $todayCelebrant->id);
            $this->assertNotNull($todayRow);
            $this->assertSame(30, $todayRow['current_age']);
            $this->assertSame(30, $todayRow['next_age']);
            $this->assertSame(0, $todayRow['days_until_birthday']);
            $this->assertSame('today', $todayRow['birthday_status']);

            $tomorrowRow = collect($response->json('upcoming_30_days'))
                ->firstWhere('employee_id', $tomorrowCelebrant->id);
            $this->assertNotNull($tomorrowRow);
            $this->assertSame(28, $tomorrowRow['current_age']);
            $this->assertSame(29, $tomorrowRow['next_age']);
            $this->assertSame(1, $tomorrowRow['days_until_birthday']);
            $this->assertSame('tomorrow', $tomorrowRow['birthday_status']);

            $upcomingRow = collect($response->json('upcoming_30_days'))
                ->firstWhere('employee_id', $upcomingCelebrant->id);
            $this->assertNotNull($upcomingRow);
            $this->assertSame(25, $upcomingRow['current_age']);
            $this->assertSame(26, $upcomingRow['next_age']);
            $this->assertSame(10, $upcomingRow['days_until_birthday']);
            $this->assertSame('upcoming', $upcomingRow['birthday_status']);
            $this->assertSame('May 29, 2026', $upcomingRow['next_birthday_formatted']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_dashboard_birthdays_endpoint_rejects_month_beyond_future_window(): void
    {
        Config::set('attendance.timezone', 'Asia/Manila');
        Carbon::setTestNow(Carbon::parse('2026-05-18 09:00:00', 'Asia/Manila'));

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        try {
            $this->actingAs($admin)
                ->getJson('/api/admin/dashboard/birthdays?year=2027&month=6')
                ->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }
}
