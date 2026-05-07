<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceDateSpecificCorrectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_EMPLOYEE);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('date');
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->boolean('approved')->default(false);
            $table->dateTime('approved_at')->nullable();
            $table->boolean('pending_approval')->nullable()->default(false);
            $table->dateTime('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 20);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function createEmployee(): User
    {
        return User::create([
            'name' => 'Attendance Test Employee',
            'username' => uniqid('attendance-test-', true),
            'email' => uniqid('attendance-test-', true).'@example.test',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
    }

    public function test_approved_correction_for_previous_date_does_not_complete_today(): void
    {
        config(['attendance.timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'Asia/Manila'));

        try {
            $employee = $this->createEmployee();

            AttendanceCorrection::create([
                'user_id' => $employee->id,
                'date' => '2026-04-27',
                'time_in' => Carbon::parse('2026-04-27 08:00:00', 'Asia/Manila')->utc(),
                'time_out' => Carbon::parse('2026-04-27 17:00:00', 'Asia/Manila')->utc(),
                'approved' => true,
                'pending_approval' => false,
                'approved_at' => Carbon::parse('2026-05-05 12:00:00', 'Asia/Manila')->utc(),
            ]);

            $this->assertFalse($employee->hasCompletedAttendanceToday());
            $this->assertFalse($employee->hasTimedInToday());
            $this->assertFalse($employee->hasClockOutToday());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_only_today_approved_correction_can_complete_today(): void
    {
        config(['attendance.timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'Asia/Manila'));

        try {
            $employee = $this->createEmployee();

            AttendanceCorrection::create([
                'user_id' => $employee->id,
                'date' => '2026-05-06',
                'time_in' => Carbon::parse('2026-05-06 08:00:00', 'Asia/Manila')->utc(),
                'time_out' => Carbon::parse('2026-05-06 17:00:00', 'Asia/Manila')->utc(),
                'approved' => true,
                'pending_approval' => false,
                'approved_at' => Carbon::parse('2026-05-06 18:00:00', 'Asia/Manila')->utc(),
            ]);

            $this->assertTrue($employee->hasCompletedAttendanceToday());
            $this->assertTrue($employee->hasTimedInToday());
            $this->assertFalse($employee->hasClockOutToday(), 'Corrections complete the day without creating a raw clock-out helper state.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_log_with_previous_effective_punch_date_but_today_created_at_does_not_complete_today(): void
    {
        config(['attendance.timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-05-06 09:00:00', 'Asia/Manila'));

        try {
            $employee = $this->createEmployee();

            AttendanceLog::create([
                'user_id' => $employee->id,
                'type' => AttendanceLog::TYPE_CLOCK_IN,
                'verified_at' => Carbon::parse('2026-04-27 08:00:00', 'Asia/Manila')->utc(),
                'created_at' => Carbon::parse('2026-05-06 08:30:00', 'Asia/Manila')->utc(),
                'updated_at' => Carbon::parse('2026-05-06 08:30:00', 'Asia/Manila')->utc(),
            ]);

            AttendanceLog::create([
                'user_id' => $employee->id,
                'type' => AttendanceLog::TYPE_CLOCK_OUT,
                'verified_at' => Carbon::parse('2026-04-27 17:00:00', 'Asia/Manila')->utc(),
                'created_at' => Carbon::parse('2026-05-06 08:31:00', 'Asia/Manila')->utc(),
                'updated_at' => Carbon::parse('2026-05-06 08:31:00', 'Asia/Manila')->utc(),
            ]);

            $this->assertFalse($employee->hasCompletedAttendanceToday());
            $this->assertFalse($employee->hasTimedInToday());
            $this->assertFalse($employee->hasClockOutToday());
        } finally {
            Carbon::setTestNow();
        }
    }
}
