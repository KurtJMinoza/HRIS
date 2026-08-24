<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Overtime;
use App\Models\OvertimeAutoApproveOverride;
use App\Models\User;
use App\Services\OvertimeAutoApproveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeAutoApproveOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
    }

    private function makePendingOvertime(User $employee, string $date, float $hours, ?User $hr = null): Overtime
    {
        $hr ??= User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $minutes = (int) round($hours * 60);

        return Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => $date,
            'schedule_end' => '17:00:00',
            'expected_end_time' => sprintf('%02d:%02d:00', 17 + intdiv($minutes, 60), $minutes % 60),
            'computed_minutes' => $minutes,
            'computed_hours' => $hours,
            'ot_type' => 'regular',
            'reason' => 'Project deadline',
            'status' => Overtime::STATUS_PENDING,
            'approval_stage' => 'pending_second',
            'pending_approval' => true,
            'second_approver_id' => $hr->id,
            'filed_at' => now(),
            'filed_by' => $employee->id,
            'created_by' => $employee->id,
        ]);
    }

    public function test_auto_approve_requires_presence(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 2,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        $overtime = $this->makePendingOvertime($employee, '2026-08-20', 2);

        $service = app(OvertimeAutoApproveService::class);
        $this->assertFalse($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
        $this->assertSame(Overtime::STATUS_PENDING, $overtime->fresh()->status);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-20 08:00:00',
            'verified_at' => '2026-08-20 08:00:00',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'created_at' => '2026-08-20 17:00:00',
            'verified_at' => '2026-08-20 17:00:00',
        ]);

        $this->assertTrue($service->employeeIsPresentForDate((int) $employee->id, '2026-08-20'));
        $this->assertTrue($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
        $fresh = $overtime->fresh();
        $this->assertSame(Overtime::STATUS_APPROVED, $fresh->status);
        $this->assertEquals(2.0, (float) $fresh->approved_ot_hours);
        $this->assertEquals(2.0, (float) $fresh->payable_ot_hours);
    }

    public function test_auto_approve_skips_when_attendance_missing_clock_out(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 1,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-19 08:00:00',
            'verified_at' => '2026-08-19 08:00:00',
        ]);

        $overtime = $this->makePendingOvertime($employee, '2026-08-19', 1);
        $service = app(OvertimeAutoApproveService::class);

        $this->assertFalse($service->employeeIsPresentForDate((int) $employee->id, '2026-08-19'));
        $this->assertFalse($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
        $this->assertFalse($service->ensureStandingOvertimeForDate($employee, '2026-08-19'));
    }

    public function test_auto_approve_counts_manual_attendance_for_presence(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 1,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        \App\Models\AttendanceCorrection::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-08-24',
            'time_in' => '2026-08-24 08:00:00',
            'time_out' => '2026-08-24 17:00:00',
            'approved' => true,
            'pending_approval' => false,
            'is_manual' => true,
            'source_type' => \App\Models\AttendanceCorrection::SOURCE_ADMIN_MANUAL,
            'approved_at' => now(),
        ]);

        $overtime = $this->makePendingOvertime($employee, '2026-08-24', 1);
        $service = app(OvertimeAutoApproveService::class);

        $this->assertTrue($service->employeeIsPresentForDate((int) $employee->id, '2026-08-24'));
        $this->assertTrue($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
    }

    public function test_auto_approved_payable_survives_min_payroll_basis(): void
    {
        config(['payroll.ot_payable_basis' => 'min']);

        $employee = $this->makeEmployee();
        $overtime = $this->makePendingOvertime($employee, '2026-08-25', 1);
        $overtime->status = Overtime::STATUS_APPROVED;
        $overtime->approved_ot_hours = 1;
        $overtime->payable_ot_hours = 1;
        $overtime->ph_ot_rule = 'ORD';
        $overtime->save();

        /** @var \App\Services\OvertimePayrollService $payroll */
        $payroll = app(\App\Services\OvertimePayrollService::class);
        $result = $payroll->computeCompensationFromRecords(
            [$overtime->fresh()],
            100.0,
            null,
            'ORD',
            0,
            '2026-08-25',
            ['in' => '08:00', 'out' => '17:00'],
            'Asia/Manila',
        );

        $this->assertEquals(1.0, (float) $result['payable_hours']);
        $this->assertGreaterThan(0.0, (float) $result['ot_pay']);
    }

    public function test_standing_overtime_revoked_when_attendance_incomplete(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 1,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        $overtime = Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-08-18',
            'schedule_end' => '17:00:00',
            'expected_end_time' => '18:00:00',
            'computed_minutes' => 60,
            'computed_hours' => 1,
            'approved_ot_hours' => 1,
            'payable_ot_hours' => 1,
            'ot_type' => 'regular',
            'reason' => 'Standing OT (auto-approve override — complete attendance day).',
            'status' => Overtime::STATUS_APPROVED,
            'approval_stage' => 'approved',
            'pending_approval' => false,
            'filed_at' => now(),
            'filed_by' => $employee->id,
            'created_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-18 08:00:00',
            'verified_at' => '2026-08-18 08:00:00',
        ]);

        $service = app(OvertimeAutoApproveService::class);
        $this->assertSame(1, $service->revokeInvalidStandingOvertimeForDate($employee, '2026-08-18'));
        $this->assertSame(Overtime::STATUS_REJECTED, $overtime->fresh()->status);
    }

    public function test_standing_overtime_accrues_for_present_override_day(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 1,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        \App\Models\AttendanceCorrection::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-08-24',
            'time_in' => '2026-08-24 08:00:00',
            'time_out' => '2026-08-24 17:00:00',
            'approved' => true,
            'pending_approval' => false,
            'is_manual' => true,
            'source_type' => \App\Models\AttendanceCorrection::SOURCE_ADMIN_MANUAL,
            'approved_at' => now(),
        ]);

        $service = app(OvertimeAutoApproveService::class);
        $this->assertTrue($service->ensureStandingOvertimeForDate($employee, '2026-08-24'));
        $this->assertSame(1, Overtime::query()->where('user_id', $employee->id)->whereDate('date', '2026-08-24')->where('status', Overtime::STATUS_APPROVED)->count());
        $this->assertFalse($service->ensureStandingOvertimeForDate($employee, '2026-08-24'));
    }

    public function test_standing_overtime_syncs_when_max_hours_per_day_increases(): void
    {
        $employee = $this->makeEmployee();

        $override = OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 1,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-22 08:00:00',
            'verified_at' => '2026-08-22 08:00:00',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'created_at' => '2026-08-22 17:00:00',
            'verified_at' => '2026-08-22 17:00:00',
        ]);

        $overtime = Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-08-22',
            'schedule_end' => '17:00:00',
            'expected_end_time' => '18:00:00',
            'approved_ot_start' => '17:00:00',
            'approved_ot_end' => '18:00:00',
            'computed_minutes' => 60,
            'computed_hours' => 1,
            'approved_ot_hours' => 1,
            'payable_ot_hours' => 1,
            'ot_type' => 'regular',
            'reason' => 'Standing OT (auto-approve override — complete attendance day).',
            'status' => Overtime::STATUS_APPROVED,
            'approval_stage' => 'approved',
            'pending_approval' => false,
            'filed_at' => now(),
            'filed_by' => $employee->id,
            'created_by' => $employee->id,
        ]);

        $override->max_hours_per_day = 4;
        $override->save();

        $service = app(OvertimeAutoApproveService::class);
        $this->assertTrue($service->syncStandingOvertimeForDate($employee, '2026-08-22', $override->fresh()));

        $fresh = $overtime->fresh();
        $this->assertEquals(4.0, (float) $fresh->approved_ot_hours);
        $this->assertEquals(4.0, (float) $fresh->payable_ot_hours);
        $this->assertSame('21:00:00', $fresh->approved_ot_end?->format('H:i:s'));
    }

    public function test_auto_approve_caps_to_max_hours_per_day(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 2,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-21 08:00:00',
            'verified_at' => '2026-08-21 08:00:00',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'created_at' => '2026-08-21 17:00:00',
            'verified_at' => '2026-08-21 17:00:00',
        ]);

        $overtime = $this->makePendingOvertime($employee, '2026-08-21', 4);
        $service = app(OvertimeAutoApproveService::class);

        $this->assertTrue($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
        $fresh = $overtime->fresh();
        $this->assertSame(Overtime::STATUS_APPROVED, $fresh->status);
        $this->assertEquals(2.0, (float) $fresh->approved_ot_hours);
        $this->assertSame('17:00:00', $fresh->approved_ot_start?->format('H:i:s'));
        $this->assertSame('19:00:00', $fresh->approved_ot_end?->format('H:i:s'));
    }

    public function test_auto_approve_skips_when_daily_limit_already_used(): void
    {
        $employee = $this->makeEmployee();
        $hr = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 2,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-22 08:00:00',
            'verified_at' => '2026-08-22 08:00:00',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'created_at' => '2026-08-22 17:00:00',
            'verified_at' => '2026-08-22 17:00:00',
        ]);

        Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => '2026-08-22',
            'schedule_end' => '17:00:00',
            'expected_end_time' => '19:00:00',
            'computed_minutes' => 120,
            'computed_hours' => 2,
            'approved_ot_hours' => 2,
            'ot_type' => 'regular',
            'reason' => 'Earlier OT',
            'status' => Overtime::STATUS_APPROVED,
            'approval_stage' => 'approved',
            'pending_approval' => false,
            'second_approver_id' => $hr->id,
            'filed_at' => now(),
            'filed_by' => $employee->id,
            'created_by' => $employee->id,
        ]);

        $second = $this->makePendingOvertime($employee, '2026-08-22', 1, $hr);
        $service = app(OvertimeAutoApproveService::class);

        $this->assertFalse($service->tryAutoApproveAfterFiling($second->fresh(), $employee));
        $this->assertSame(Overtime::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_holiday_ot_rule_compatibility_requires_same_family(): void
    {
        $service = app(OvertimeAutoApproveService::class);

        $this->assertTrue($service->holidayOtRulesCompatible('RH', 'RH'));
        $this->assertTrue($service->holidayOtRulesCompatible('RH', 'RHRD'));
        $this->assertTrue($service->holidayOtRulesCompatible('SHRD', 'SH'));
        $this->assertFalse($service->holidayOtRulesCompatible('RH', 'ORD'));
        $this->assertFalse($service->holidayOtRulesCompatible('RH', 'SH'));
        $this->assertFalse($service->holidayOtRulesCompatible('RH', 'RD'));
        $this->assertTrue($service->isHolidayOtRule('RHRD'));
        $this->assertFalse($service->isHolidayOtRule('ORD'));
    }

    public function test_auto_approve_skips_holiday_ot_when_employee_not_in_holiday_scope(): void
    {
        $employee = $this->makeEmployee();

        OvertimeAutoApproveOverride::query()->create([
            'user_id' => $employee->id,
            'is_active' => true,
            'max_hours_per_day' => 4,
            'created_by' => $employee->id,
            'updated_by' => $employee->id,
        ]);

        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'created_at' => '2026-08-23 08:00:00',
            'verified_at' => '2026-08-23 08:00:00',
        ]);
        AttendanceLog::query()->create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'created_at' => '2026-08-23 17:00:00',
            'verified_at' => '2026-08-23 17:00:00',
        ]);

        // Filed as Regular Holiday OT, but with no in-scope holiday the expected rule is ORD/RD.
        $overtime = $this->makePendingOvertime($employee, '2026-08-23', 2);
        $overtime->ph_ot_rule = 'RH';
        $overtime->save();

        $service = app(OvertimeAutoApproveService::class);
        $expected = $service->resolveExpectedPhOtRule($employee, '2026-08-23');
        $this->assertFalse($service->isHolidayOtRule($expected));
        $this->assertFalse($service->tryAutoApproveAfterFiling($overtime->fresh(), $employee));
        $this->assertSame(Overtime::STATUS_PENDING, $overtime->fresh()->status);
    }
}
