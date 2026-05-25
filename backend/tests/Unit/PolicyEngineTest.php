<?php

namespace Tests\Unit;

use App\Enums\PolicyConditionKey;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeCompensationComponent;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\PayComponent;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\PolicyNdSetting;
use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\PayrollCalculatorService;
use App\Services\PayrollComputationService;
use App\Services\PayslipService;
use App\Services\PolicyResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Policy Configuration Engine tests.
 * DB-dependent tests are skipped when using sqlite :memory: (CI). Run with MySQL for full coverage.
 */
class PolicyEngineTest extends TestCase
{
    public function test_policy_condition_key_enum_has_all_codes(): void
    {
        $all = PolicyConditionKey::all();
        $this->assertCount(8, $all);
        $codes = array_column($all, 'value');
        $this->assertContains('ORD', $codes);
        $this->assertContains('RD', $codes);
        $this->assertContains('RH', $codes);
        $this->assertContains('RHRD', $codes);
        $this->assertContains('SH', $codes);
        $this->assertContains('SHRD', $codes);
        $this->assertContains('DH', $codes);
        $this->assertContains('DHRD', $codes);
    }

    public function test_config_fallback_structure(): void
    {
        $rules = Config::get('payroll.rules', []);
        $this->assertArrayHasKey('ORD', $rules);
        $ord = $rules['ORD'];
        $this->assertArrayHasKey('first_8', $ord);
        $this->assertArrayHasKey('ot', $ord);
        $this->assertSame(1.0, (float) ($ord['first_8'] ?? 0));
        $this->assertSame(1.25, (float) ($ord['ot'] ?? 0));
    }

    public function test_nd_config_structure(): void
    {
        $nd = Config::get('payroll.night_differential', []);
        $this->assertArrayHasKey('start_hour', $nd);
        $this->assertArrayHasKey('end_hour', $nd);
        $this->assertSame(22, (int) ($nd['start_hour'] ?? 0));
        $this->assertSame(6, (int) ($nd['end_hour'] ?? 0));
    }

    public function test_global_policy_resolves_when_no_company(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $policy = Policy::create([
            'name' => 'Global Default',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);

        PolicyMultiplier::create([
            'policy_id' => $policy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $resolver = app(PolicyResolverService::class);
        $active = $resolver->getActivePolicy(null, null, '2026-03-20');

        $this->assertNotNull($active);
        $this->assertSame($policy->id, $active->id);
    }

    public function test_company_policy_overrides_global(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $company = Company::create(['name' => 'Acme Corp']);

        $globalPolicy = Policy::create([
            'name' => 'Global',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $globalPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $companyPolicy = Policy::create([
            'name' => 'Acme Policy',
            'company_id' => $company->id,
            'branch_id' => null,
            'effective_date' => '2025-06-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $companyPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.50,
            'ot_multiplier' => 2.0,
            'nd_addon_multiplier' => 0.15,
        ]);

        $resolver = app(PolicyResolverService::class);
        $active = $resolver->getActivePolicy($company->id, null, '2026-03-20');

        $this->assertNotNull($active);
        $this->assertSame($companyPolicy->id, $active->id);

        $mult = $resolver->getMultipliersForRule($active, 'ORD');
        $this->assertSame(1.5, $mult['first_8']);
        $this->assertSame(2.0, $mult['ot']);
        $this->assertSame(0.15, $mult['nd_addon']);
    }

    public function test_multiplier_change_reflects_in_computation(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available (run migrations)');
        }

        $policy = Policy::create([
            'name' => 'Test',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policy->id,
            'condition_key' => 'RD',
            'first8_multiplier' => 1.30,
            'ot_multiplier' => 1.69,
            'nd_addon_multiplier' => 0.10,
        ]);

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 1000,
        ]);
        $effectiveSchedule = [
            'mon' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => null,
            'thu' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sun' => null,
        ];
        $user->update(['schedule' => $effectiveSchedule]);

        $dateKey = '2026-03-18'; // Wednesday = rest day
        $timeIn = Carbon::parse("{$dateKey} 09:00", 'Asia/Manila');
        $timeOut = Carbon::parse("{$dateKey} 18:00", 'Asia/Manila');

        $payroll = app(PayrollComputationService::class);
        $result = $payroll->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 1000);

        $this->assertSame(1.30, $result['conditions']['first_8']);
        $this->assertSame(1.69, $result['conditions']['ot']);

        PolicyMultiplier::where('policy_id', $policy->id)->where('condition_key', 'RD')->update([
            'first8_multiplier' => 1.50,
            'ot_multiplier' => 1.95,
        ]);

        $result2 = $payroll->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 1000);
        $this->assertSame(1.50, $result2['conditions']['first_8']);
        $this->assertSame(1.95, $result2['conditions']['ot']);
    }

    public function test_nd_config_from_policy(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $policy = Policy::create([
            'name' => 'ND Policy',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyNdSetting::create([
            'policy_id' => $policy->id,
            'start_time' => '22:00',
            'end_time' => '06:00',
            'nd_addon_multiplier' => 0.12,
            'apply_to_regular' => true,
            'apply_to_ot' => true,
            'apply_to_premium_days' => true,
        ]);

        $resolver = app(PolicyResolverService::class);
        $nd = $resolver->getNdConfig($policy);

        $this->assertSame(22, $nd['start_hour']);
        $this->assertSame(6, $nd['end_hour']);
        $this->assertSame(0.12, $nd['premium_multiplier']);
    }

    public function test_version_isolation_effective_date(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $oldPolicy = Policy::create([
            'name' => 'Old',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $oldPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.20,
            'nd_addon_multiplier' => 0.10,
        ]);

        $newPolicy = Policy::create([
            'name' => 'New',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2026-06-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 2,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $newPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.30,
            'nd_addon_multiplier' => 0.10,
        ]);

        $resolver = app(PolicyResolverService::class);

        $beforeCutover = $resolver->getActivePolicy(null, null, '2026-05-15');
        $this->assertNotNull($beforeCutover);
        $this->assertSame($oldPolicy->id, $beforeCutover->id);
        $multBefore = $resolver->getMultipliersForRule($beforeCutover, 'ORD');
        $this->assertSame(1.20, $multBefore['ot']);

        $afterCutover = $resolver->getActivePolicy(null, null, '2026-06-15');
        $this->assertNotNull($afterCutover);
        $this->assertSame($newPolicy->id, $afterCutover->id);
        $multAfter = $resolver->getMultipliersForRule($afterCutover, 'ORD');
        $this->assertSame(1.30, $multAfter['ot']);
    }

    public function test_cross_company_policies(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        $policyA = Policy::create([
            'name' => 'Policy A',
            'company_id' => $companyA->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policyA->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $policyB = Policy::create([
            'name' => 'Policy B',
            'company_id' => $companyB->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policyB->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.20,
            'ot_multiplier' => 1.50,
            'nd_addon_multiplier' => 0.12,
        ]);

        $resolver = app(PolicyResolverService::class);

        $activeA = $resolver->getActivePolicy($companyA->id, null, '2026-03-20');
        $this->assertNotNull($activeA);
        $this->assertSame($policyA->id, $activeA->id);
        $multA = $resolver->getMultipliersForRule($activeA, 'ORD');
        $this->assertSame(1.25, $multA['ot']);

        $activeB = $resolver->getActivePolicy($companyB->id, null, '2026-03-20');
        $this->assertNotNull($activeB);
        $this->assertSame($policyB->id, $activeB->id);
        $multB = $resolver->getMultipliersForRule($activeB, 'ORD');
        $this->assertSame(1.50, $multB['ot']);
    }

    public function test_regular_pay_uses_actual_worked_minutes_when_undertime(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
        ]);
        $effectiveSchedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => null,
            'wed' => null,
            'thu' => null,
            'fri' => null,
            'sat' => null,
        ];

        $dateKey = '2026-03-16'; // Monday, 8 scheduled paid hours after lunch break.
        $timeIn = Carbon::parse("{$dateKey} 08:00", 'Asia/Manila');
        $timeOut = Carbon::parse("{$dateKey} 09:42", 'Asia/Manila');

        $result = app(PayrollComputationService::class)
            ->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 800, 'Asia/Manila');

        $regularLine = collect($result['breakdown'])
            ->first(fn ($line) => ($line['component'] ?? null) === 'regular_pay');
        $undertimeLine = collect($result['breakdown'])
            ->first(fn ($line) => ($line['component'] ?? null) === 'undertime_deduction');

        $this->assertSame(102, $result['worked_minutes']);
        $this->assertSame(102, $result['regular_day_minutes']);
        $this->assertSame(378, $result['undertime_deduction_minutes']);
        $this->assertSame(170.0, $result['regular_pay']);
        $this->assertSame(170.0, $result['total_pay']);
        $this->assertSame(102, $regularLine['minutes']);
        $this->assertSame(170.0, $regularLine['amount']);
        $this->assertSame(378, $undertimeLine['minutes']);
        $this->assertSame(0.0, $undertimeLine['amount']);
    }

    public function test_payroll_impact_uses_scheduled_hours_for_full_shift_with_early_and_late_punches(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $effectiveSchedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
            'schedule' => $effectiveSchedule,
        ]);

        $dateKey = '2026-04-29'; // Wednesday, 8 scheduled paid hours after lunch break.
        $timeIn = Carbon::parse("{$dateKey} 07:01:30", 'Asia/Manila');
        $timeOut = Carbon::parse("{$dateKey} 17:05:15", 'Asia/Manila');

        $payroll = app(PayrollComputationService::class);
        $result = $payroll->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 800, 'Asia/Manila');
        $impactMinutes = $payroll->payrollImpactMinutesForAttendanceDisplay($user, $dateKey, $timeIn, $timeOut, 'Asia/Manila');

        $this->assertSame(480, $result['required_minutes']);
        $this->assertSame(480, $result['regular_day_minutes'] + $result['regular_night_minutes']);
        $this->assertSame(0, $result['ot_day_minutes'] + $result['ot_night_minutes']);
        $this->assertSame(480, $impactMinutes);
        $this->assertSame(800.0, $result['regular_pay']);
    }

    public function test_approved_ot_hours_use_approved_basis_by_default(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        config(['payroll.ot_payable_basis' => 'approved']);

        $effectiveSchedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
            'schedule' => $effectiveSchedule,
        ]);

        $dateKey = '2026-04-30'; // Thursday.
        Overtime::create([
            'user_id' => $user->id,
            'date' => $dateKey,
            'schedule_end' => '17:00:00',
            'expected_end_time' => '20:00:00',
            'computed_minutes' => 180,
            'computed_hours' => 3.00,
            'ph_ot_rule' => 'ORD',
            'status' => Overtime::STATUS_APPROVED,
        ]);

        $payroll = app(PayrollComputationService::class);
        $result = $payroll->computeDayPayroll(
            $user,
            $dateKey,
            Carbon::parse("{$dateKey} 07:51:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:01:00", 'Asia/Manila'),
            $effectiveSchedule,
            800,
            'Asia/Manila'
        );

        $this->assertSame(480, $result['regular_day_minutes'] + $result['regular_night_minutes']);
        $this->assertSame(180, $result['ot_day_minutes'] + $result['ot_night_minutes']);
        $this->assertSame(3.0, $result['approved_ot_hours']);
        $this->assertSame(3.0, $result['approved_ot_requested_hours']);
        $this->assertGreaterThan(150.0, (float) $result['ot_pay']);
    }

    public function test_approved_ot_can_still_cap_to_rendered_when_configured(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        config(['payroll.ot_payable_basis' => 'min']);

        $effectiveSchedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
            'schedule' => $effectiveSchedule,
        ]);

        $dateKey = '2026-04-30';
        Overtime::create([
            'user_id' => $user->id,
            'date' => $dateKey,
            'schedule_end' => '17:00:00',
            'expected_end_time' => '01:00:00',
            'computed_minutes' => 480,
            'computed_hours' => 8.00,
            'status' => Overtime::STATUS_APPROVED,
        ]);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $user,
            $dateKey,
            Carbon::parse("{$dateKey} 07:51:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:01:00", 'Asia/Manila'),
            $effectiveSchedule,
            800,
            'Asia/Manila'
        );

        $this->assertSame(0.17, $result['approved_ot_hours']);
        $this->assertSame(8.0, $result['approved_ot_requested_hours']);
    }

    public function test_approved_ot_without_clock_logs_includes_night_differential(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        config(['payroll.ot_payable_basis' => 'approved']);

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
            'schedule' => [
                'sun' => null,
                'mon' => null,
                'tue' => null,
                'wed' => null,
                'thu' => null,
                'fri' => null,
                'sat' => null,
            ],
        ]);

        $dateKey = '2026-05-12';
        Overtime::create([
            'user_id' => $user->id,
            'date' => $dateKey,
            'schedule_end' => '17:00:00',
            'expected_end_time' => '23:00:00',
            'computed_minutes' => 360,
            'computed_hours' => 6.00,
            'ph_ot_rule' => 'ORD',
            'status' => Overtime::STATUS_APPROVED,
        ]);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $user,
            $dateKey,
            null,
            null,
            $user->schedule ?? [],
            800,
            'Asia/Manila'
        );

        $this->assertSame(6.0, (float) $result['approved_ot_hours']);
        $this->assertSame(1.0, round(((int) ($result['ot_night_minutes'] ?? 0)) / 60, 2));
        $this->assertGreaterThan(0.0, (float) ($result['nd_pay'] ?? 0));
        $this->assertTrue((bool) ($result['nd_premium_applied'] ?? false));
    }

    public function test_multiple_approved_ot_days_sum_in_period_payroll(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        config(['payroll.ot_payable_basis' => 'approved']);

        $effectiveSchedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => null,
        ];

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 800,
            'schedule' => $effectiveSchedule,
        ]);

        foreach (['2026-05-12', '2026-05-13', '2026-05-14'] as $dateKey) {
            Overtime::create([
                'user_id' => $user->id,
                'date' => $dateKey,
                'schedule_end' => '17:00:00',
                'expected_end_time' => '20:00:00',
                'computed_minutes' => 180,
                'computed_hours' => 3.00,
                'ph_ot_rule' => 'ORD',
                'status' => Overtime::STATUS_APPROVED,
            ]);
        }

        $from = Carbon::parse('2026-05-12', 'Asia/Manila');
        $to = Carbon::parse('2026-05-14', 'Asia/Manila');
        $computed = app(PayrollComputationService::class)->computeEmployeePayroll($user, $from, $to, 800.0);

        $summary = $computed['summary'] ?? [];
        $this->assertSame(9.0, (float) ($summary['overtime_total_hours'] ?? 0));
        $this->assertCount(3, $summary['overtime_breakdown'] ?? []);
        $otLine = collect($summary['daily_computation_earning_lines'] ?? [])
            ->first(fn ($line) => ($line['key'] ?? '') === 'daily:ot_pay');
        $this->assertNotNull($otLine);
        $this->assertSame(540, (int) ($otLine['minutes_worked'] ?? 0));
    }

    public function test_regular_pay_units_show_zero_days_for_partial_work(): void
    {
        $service = app(PayslipService::class);
        $method = new \ReflectionMethod($service, 'formatPayslipUnitsFromMinutes');
        $method->setAccessible(true);

        $this->assertSame('0 days, 1 hr 42 mins', $method->invoke($service, 102));
        $this->assertSame('1 day, 0 hrs 0 mins', $method->invoke($service, 480));
    }

    public function test_stored_payslip_snapshot_repairs_regular_pay_from_actual_daily_minutes(): void
    {
        $snapshot = [
            'daily_rate' => 800,
            'summary' => [
                'daily_rate' => 800,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'units' => '2 days, 0 hrs 0 mins',
                    'amount' => 1600,
                ]],
            ],
            'daily_computation_days' => [[
                'date' => '2026-04-29',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 102,
                'regular_night_minutes' => 0,
                'undertime_deduction_minutes' => 378,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 102,
                    'rate' => 100,
                    'amount' => 170,
                ]],
            ]],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame('Regular pay', $line['label'] ?? null);
        $this->assertSame('0 days, 1 hr 42 mins', $line['units'] ?? null);
        $this->assertSame(170.0, $line['amount'] ?? null);
        $this->assertSame(102, $line['minutes_worked'] ?? null);
    }

    public function test_stored_payslip_snapshot_repair_excludes_holiday_premium_days_from_regular_pay(): void
    {
        $snapshot = [
            'daily_rate' => 800,
            'summary' => [
                'daily_rate' => 800,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'units' => '2 days, 0 hrs 0 mins',
                    'amount' => 1600,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-04-30',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'holiday_premium_pay' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => 100,
                        'amount' => 800,
                    ]],
                ],
                [
                    'date' => '2026-05-01',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'holiday_premium_pay' => 1600,
                    'breakdown' => [
                        [
                            'component' => 'regular_pay',
                            'minutes' => 0,
                            'rate' => 100,
                            'amount' => 0,
                        ],
                        [
                            'component' => 'holiday_premium',
                            'minutes' => 480,
                            'rate' => 100,
                            'amount' => 1600,
                        ],
                    ],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame('Regular pay', $line['label'] ?? null);
        $this->assertSame('1 day, 0 hrs 0 mins', $line['units'] ?? null);
        $this->assertSame(800.0, $line['amount'] ?? null);
        $this->assertSame(480, $line['minutes_worked'] ?? null);
    }

    public function test_open_clock_in_is_not_paired_with_next_workday_clock_out(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create([
            'schedule' => [
                'sun' => null,
                'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'sat' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            ],
        ]);

        AttendanceLog::create([
            'user_id' => $user->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse('2026-04-28 07:30', 'Asia/Manila')->utc(),
        ]);
        AttendanceLog::create([
            'user_id' => $user->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'verified_at' => Carbon::parse('2026-04-29 17:05', 'Asia/Manila')->utc(),
        ]);

        [$timeIn, $timeOut] = app(AttendanceSessionService::class)
            ->getTimesForDate($user, '2026-04-28', 'Asia/Manila');

        $this->assertNull($timeIn);
        $this->assertNull($timeOut);
    }

    public function test_empty_salary_tab_overrides_stale_basic_salary_assignment(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create([
            'monthly_salary' => null,
            'monthly_rate' => null,
            'daily_rate' => null,
            'hourly_rate' => null,
        ]);

        $component = PayComponent::create([
            'name' => 'Basic Salary',
            'code' => 'BASIC_SALARY_TEST_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Basic Salary',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 25000,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        $assignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $component->id,
            'name' => 'Basic Salary',
            'code' => 'BASIC_SALARY',
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Basic Salary',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 25000,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        try {
            $calculator = app(PayrollCalculatorService::class);
            $calculator->forgetCompensationSummaryCacheForUser((int) $user->id);

            $summary = $calculator->buildEmployeeCompensationSummary($user->fresh(), [
                'as_of_date' => '2026-05-10',
                'cache' => false,
            ]);

            $this->assertSame(0.0, $calculator->resolveBasicSalaryForPayroll($user->fresh(), '2026-05-10'));
            $this->assertSame(0.0, (float) ($summary['basic_salary'] ?? -1));
            $this->assertFalse(collect($summary['earnings'] ?? [])->contains(
                fn (array $line) => strtoupper((string) ($line['code'] ?? '')) === 'BASIC_SALARY'
            ));
        } finally {
            $assignment->forceDelete();
            $component->forceDelete();
        }
    }

    public function test_payroll_attendance_session_merges_missing_in_correction_with_clock_out(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create();
        $dateKey = '2026-04-30';
        AttendanceCorrection::create([
            'user_id' => $user->id,
            'date' => $dateKey,
            'time_in' => Carbon::parse("{$dateKey} 08:00", 'Asia/Manila')->utc(),
            'time_out' => null,
            'remarks' => 'Approved missing clock-in',
            'issue_kind' => 'missing_in',
            'approved' => true,
            'pending_approval' => false,
            'approval_stage' => 'approved',
            'approved_at' => Carbon::parse("{$dateKey} 09:48", 'Asia/Manila')->utc(),
        ]);
        AttendanceLog::create([
            'user_id' => $user->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'verified_at' => Carbon::parse("{$dateKey} 09:42", 'Asia/Manila')->utc(),
        ]);

        [$timeIn, $timeOut] = app(\App\Services\AttendanceSessionService::class)
            ->getTimesForDate($user, $dateKey, 'Asia/Manila');

        $this->assertSame('08:00', $timeIn?->format('H:i'));
        $this->assertSame('09:42', $timeOut?->format('H:i'));
    }

    public function test_proratable_allowance_deducts_only_unpaid_absent_days_not_partial_hours(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $workday = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 0,
            'early_timeout_minutes' => null,
            'overtime_buffer_minutes' => 15,
        ];
        $schedule = [
            'sun' => null,
            'mon' => $workday,
            'tue' => $workday,
            'wed' => $workday,
            'thu' => $workday,
            'fri' => $workday,
            'sat' => $workday,
        ];

        $user = User::factory()->create([
            'monthly_salary' => 26000,
            'schedule' => $schedule,
            'is_active' => true,
        ]);

        $component = PayComponent::create([
            'name' => 'Allowance',
            'code' => 'ALLOWANCE_TEST_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 2600,
            'is_taxable' => true,
            'is_proratable' => true,
            'is_active' => true,
        ]);

        $assignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $component->id,
            'name' => 'Allowance',
            'code' => 'ALLOWANCE_TEST_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 2600,
            'is_taxable' => true,
            'is_proratable' => true,
            'is_active' => true,
        ]);

        try {
            foreach ([
                '2026-05-04' => ['08:00', '17:00'],
                '2026-05-05' => ['08:00', '17:00'],
                '2026-05-06' => ['08:00', '17:00'],
                '2026-05-07' => ['08:00', '17:00'],
                '2026-05-08' => ['08:00', '15:54'],
            ] as $dateKey => [$in, $out]) {
                AttendanceLog::create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_IN,
                    'verified_at' => Carbon::parse("{$dateKey} {$in}", 'Asia/Manila')->utc(),
                ]);
                AttendanceLog::create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_OUT,
                    'verified_at' => Carbon::parse("{$dateKey} {$out}", 'Asia/Manila')->utc(),
                ]);
            }
            AttendanceLog::create([
                'user_id' => $user->id,
                'type' => AttendanceLog::TYPE_CLOCK_IN,
                'verified_at' => Carbon::parse('2026-05-09 08:00', 'Asia/Manila')->utc(),
            ]);
            Overtime::create([
                'user_id' => $user->id,
                'date' => '2026-05-09',
                'schedule_end' => '17:00:00',
                'computed_minutes' => 60,
                'computed_hours' => 1.0,
                'status' => Overtime::STATUS_APPROVED,
            ]);

            $payroll = app(PayrollComputationService::class)->computeEmployeePayroll(
                $user->fresh(),
                Carbon::parse('2026-05-04', 'Asia/Manila'),
                Carbon::parse('2026-05-09', 'Asia/Manila'),
                null,
                [
                    'pay_period_start' => '2026-05-04',
                    'pay_period_end' => '2026-05-09',
                    'selected_pay_date' => '2026-05-15',
                ]
            );

            $allowanceLine = collect($payroll['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance');
            $this->assertNotNull($allowanceLine);
            // Proratable allowance is payable-day based: monthly allowance / 26 * 5 payable days.
            $this->assertSame(500.0, (float) ($allowanceLine['amount'] ?? 0));
            $this->assertSame(5.0, (float) data_get($allowanceLine, 'allowance_proration.payable_day_units'));
            $this->assertSame(1.0, (float) data_get($allowanceLine, 'allowance_proration.unpaid_absent_days'));
            $this->assertSame('payable_days', data_get($allowanceLine, 'allowance_proration.proration_basis'));
            $this->assertSame(26.0, (float) data_get($allowanceLine, 'allowance_proration.monthly_divisor_days'));
            $this->assertSame(26.0, (float) data_get($allowanceLine, 'allowance_proration.base_divisor_days'));
            $this->assertSame(2600.0, (float) data_get($allowanceLine, 'allowance_proration.allowance_base_before_proration'));
            $this->assertSame(500.0, (float) data_get($allowanceLine, 'allowance_proration.original_prorated_allowance_before_schedule_adjustment'));
            $this->assertSame(1300.0, (float) data_get($allowanceLine, 'allowance_proration.scheduled_base_for_run_before_absence_deduction'));
            $this->assertSame(100.0, (float) data_get($allowanceLine, 'allowance_proration.unpaid_absence_deduction'));
            $this->assertSame(100.0, (float) data_get($allowanceLine, 'allowance_proration.daily_allowance_rate'));
            $this->assertSame(2.0, (float) data_get($allowanceLine, 'allowance_proration.schedule_adjustment_divisor'));
            $this->assertTrue(collect(data_get($allowanceLine, 'allowance_proration.unpaid_absences', []))
                ->contains(fn ($row) => ($row['date'] ?? null) === '2026-05-09'));

            DeductionScheduleSetting::query()->updateOrCreate(
                [
                    'company_id' => null,
                    'deduction_key' => 'pay_component:'.$component->id,
                ],
                ['schedule_type' => DeductionScheduleSetting::SCHEDULE_15TH]
            );
            $fifteenthOnlyPayroll = app(PayrollComputationService::class)->computeEmployeePayroll(
                $user->fresh(),
                Carbon::parse('2026-05-04', 'Asia/Manila'),
                Carbon::parse('2026-05-09', 'Asia/Manila'),
                null,
                [
                    'pay_period_start' => '2026-05-04',
                    'pay_period_end' => '2026-05-09',
                    'selected_pay_date' => '2026-05-15',
                ]
            );
            $fifteenthAllowanceLine = collect($fifteenthOnlyPayroll['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance');
            $this->assertNotNull($fifteenthAllowanceLine);
            $this->assertSame(500.0, (float) ($fifteenthAllowanceLine['amount'] ?? 0));
            $this->assertSame(1.0, (float) data_get($fifteenthAllowanceLine, 'allowance_proration.schedule_adjustment_multiplier'));

            DeductionScheduleSetting::query()->where('deduction_key', 'pay_component:'.$component->id)->delete();
            DeductionScheduleSetting::query()->create([
                'company_id' => null,
                'deduction_key' => 'pay_component:'.$component->id,
                'schedule_type' => DeductionScheduleSetting::SCHEDULE_30TH,
            ]);

            $secondRunOnlyPayroll = app(PayrollComputationService::class)->computeEmployeePayroll(
                $user->fresh(),
                Carbon::parse('2026-05-04', 'Asia/Manila'),
                Carbon::parse('2026-05-09', 'Asia/Manila'),
                null,
                [
                    'pay_period_start' => '2026-05-04',
                    'pay_period_end' => '2026-05-09',
                    'selected_pay_date' => '2026-05-15',
                ]
            );
            $secondRunOnlyAllowanceLine = collect($secondRunOnlyPayroll['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance');
            $this->assertNotNull($secondRunOnlyAllowanceLine);
            $this->assertSame(0.0, (float) ($secondRunOnlyAllowanceLine['amount'] ?? -1));
            $this->assertFalse((bool) data_get($secondRunOnlyAllowanceLine, 'allowance_proration.is_applicable_in_run'));
            $this->assertSame(0.0, (float) data_get($secondRunOnlyAllowanceLine, 'allowance_proration.allowance_base_before_proration'));
            $this->assertSame(500.0, (float) data_get($secondRunOnlyAllowanceLine, 'allowance_proration.original_prorated_allowance_before_schedule_adjustment'));
        } finally {
            DeductionScheduleSetting::query()
                ->where('deduction_key', 'pay_component:'.$component->id)
                ->delete();
            $assignment->forceDelete();
            $component->forceDelete();
        }
    }

    public function test_split_proratable_allowance_uses_only_present_payable_days_in_cutoff(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $workday = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 0,
            'early_timeout_minutes' => null,
            'overtime_buffer_minutes' => 15,
        ];
        $schedule = [
            'sun' => null,
            'mon' => $workday,
            'tue' => $workday,
            'wed' => $workday,
            'thu' => $workday,
            'fri' => $workday,
            'sat' => $workday,
        ];

        $user = User::factory()->create([
            'monthly_salary' => 26000,
            'schedule' => $schedule,
            'is_active' => true,
        ]);

        $component = PayComponent::create([
            'name' => 'Allowance',
            'code' => 'ALLOWANCE_SPLIT_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 2600,
            'is_taxable' => true,
            'is_proratable' => true,
            'is_active' => true,
        ]);

        $assignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $component->id,
            'name' => 'Allowance',
            'code' => 'ALLOWANCE_SPLIT_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 2600,
            'is_taxable' => true,
            'is_proratable' => true,
            'is_active' => true,
            'schedule_override' => 'split',
        ]);

        try {
            foreach ([
                '2026-04-27',
                '2026-04-28',
                '2026-04-29',
                '2026-04-30',
                '2026-05-02',
                '2026-05-04',
                '2026-05-05',
                '2026-05-06',
                '2026-05-07',
            ] as $dateKey) {
                AttendanceLog::create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_IN,
                    'verified_at' => Carbon::parse("{$dateKey} 08:00", 'Asia/Manila')->utc(),
                ]);
                AttendanceLog::create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_OUT,
                    'verified_at' => Carbon::parse("{$dateKey} 17:00", 'Asia/Manila')->utc(),
                ]);
            }

            $payroll = app(PayrollComputationService::class)->computeEmployeePayroll(
                $user->fresh(),
                Carbon::parse('2026-04-26', 'Asia/Manila'),
                Carbon::parse('2026-05-10', 'Asia/Manila'),
                null,
                [
                    'pay_period_start' => '2026-04-26',
                    'pay_period_end' => '2026-05-10',
                    'selected_pay_date' => '2026-05-15',
                ]
            );

            $allowanceLine = collect($payroll['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance');

            $this->assertNotNull($allowanceLine);
            $this->assertSame(100.0, (float) data_get($allowanceLine, 'allowance_proration.daily_allowance_rate'));
            $this->assertSame(9.0, (float) data_get($allowanceLine, 'allowance_proration.payable_day_units'));
            $this->assertSame(9.0, (float) data_get($allowanceLine, 'allowance_proration.present_day_units'));
            $this->assertSame('both', data_get($allowanceLine, 'allowance_proration.schedule_configuration'));
            $this->assertSame('first', data_get($allowanceLine, 'allowance_proration.current_payroll_run_type'));
            $this->assertSame(900.0, (float) ($allowanceLine['amount'] ?? 0));
        } finally {
            $assignment->forceDelete();
            $component->forceDelete();
        }
    }

    public function test_proratable_allowance_treats_paid_leave_as_payable_and_unpaid_leave_as_deductible(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $workday = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 0,
            'early_timeout_minutes' => null,
            'overtime_buffer_minutes' => 15,
        ];
        $schedule = [
            'sun' => null,
            'mon' => $workday,
            'tue' => $workday,
            'wed' => $workday,
            'thu' => $workday,
            'fri' => $workday,
            'sat' => $workday,
        ];

        $makeEmployeeWithAllowance = function (string $suffix) use ($schedule): array {
            $user = User::factory()->create([
                'monthly_salary' => 26000,
                'schedule' => $schedule,
                'is_active' => true,
            ]);
            $component = PayComponent::create([
                'name' => 'Allowance '.$suffix,
                'code' => 'ALLOWANCE_LEAVE_'.$suffix.'_'.$user->id,
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Fixed Allowance',
                'calculation_type' => PayComponent::CALC_FIXED,
                'default_value' => 2600,
                'is_taxable' => true,
                'is_proratable' => true,
                'is_active' => true,
            ]);
            $assignment = EmployeeCompensationComponent::create([
                'user_id' => $user->id,
                'pay_component_id' => $component->id,
                'name' => 'Allowance '.$suffix,
                'code' => 'ALLOWANCE_LEAVE_'.$suffix.'_'.$user->id,
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Fixed Allowance',
                'calculation_type' => PayComponent::CALC_FIXED,
                'value' => 2600,
                'is_taxable' => true,
                'is_proratable' => true,
                'is_active' => true,
            ]);

            return [$user, $component, $assignment];
        };

        $payrollFor = function (User $user): array {
            return app(PayrollComputationService::class)->computeEmployeePayroll(
                $user->fresh(),
                Carbon::parse('2026-05-04', 'Asia/Manila'),
                Carbon::parse('2026-05-09', 'Asia/Manila'),
                null,
                [
                    'pay_period_start' => '2026-05-04',
                    'pay_period_end' => '2026-05-09',
                    'selected_pay_date' => '2026-05-15',
                ]
            );
        };

        [$paidUser, $paidComponent, $paidAssignment] = $makeEmployeeWithAllowance('PAID');
        [$unpaidUser, $unpaidComponent, $unpaidAssignment] = $makeEmployeeWithAllowance('UNPAID');

        try {
            LeaveRequest::create([
                'user_id' => $paidUser->id,
                'type' => 'vacation',
                'start_date' => '2026-05-04',
                'end_date' => '2026-05-04',
                'status' => LeaveRequest::STATUS_APPROVED,
                'reviewed_at' => Carbon::parse('2026-05-03 09:00', 'Asia/Manila')->utc(),
                'leave_credits_charged' => 1,
                'leave_unpaid_credit_days' => 0,
            ]);
            LeaveRequest::create([
                'user_id' => $unpaidUser->id,
                'type' => 'vacation',
                'start_date' => '2026-05-04',
                'end_date' => '2026-05-04',
                'status' => LeaveRequest::STATUS_APPROVED,
                'reviewed_at' => Carbon::parse('2026-05-03 09:00', 'Asia/Manila')->utc(),
                'leave_credits_charged' => 0,
                'leave_unpaid_credit_days' => 1,
            ]);

            foreach ([$paidUser, $unpaidUser] as $user) {
                foreach (['2026-05-05', '2026-05-06', '2026-05-07', '2026-05-08', '2026-05-09'] as $dateKey) {
                    AttendanceLog::create([
                        'user_id' => $user->id,
                        'type' => AttendanceLog::TYPE_CLOCK_IN,
                        'verified_at' => Carbon::parse("{$dateKey} 08:00", 'Asia/Manila')->utc(),
                    ]);
                    AttendanceLog::create([
                        'user_id' => $user->id,
                        'type' => AttendanceLog::TYPE_CLOCK_OUT,
                        'verified_at' => Carbon::parse("{$dateKey} 14:00", 'Asia/Manila')->utc(),
                    ]);
                }
            }

            $paidLine = collect($payrollFor($paidUser)['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance PAID');
            $this->assertNotNull($paidLine);
            $this->assertSame(600.0, (float) ($paidLine['amount'] ?? 0));
            $this->assertSame(0.0, (float) data_get($paidLine, 'allowance_proration.unpaid_absent_days'));
            $this->assertSame(1.0, (float) data_get($paidLine, 'allowance_proration.approved_paid_leave_day_units'));
            $this->assertTrue(collect(data_get($paidLine, 'allowance_proration.attendance_counted', []))
                ->contains(fn ($row) => ($row['date'] ?? null) === '2026-05-04'
                    && ($row['reason'] ?? null) === 'approved_paid_leave'));

            $unpaidLine = collect($payrollFor($unpaidUser)['summary']['payslip_earning_lines'] ?? [])
                ->first(fn ($line) => ($line['label'] ?? null) === 'Allowance UNPAID');
            $this->assertNotNull($unpaidLine);
            $this->assertSame(500.0, (float) ($unpaidLine['amount'] ?? 0));
            $this->assertSame(1.0, (float) data_get($unpaidLine, 'allowance_proration.unpaid_absent_days'));
            $this->assertTrue(collect(data_get($unpaidLine, 'allowance_proration.unpaid_absences', []))
                ->contains(fn ($row) => ($row['date'] ?? null) === '2026-05-04'
                    && ($row['reason'] ?? null) === 'approved_unpaid_leave'));
        } finally {
            foreach ([$paidComponent, $unpaidComponent] as $component) {
                DeductionScheduleSetting::query()
                    ->where('deduction_key', 'pay_component:'.$component->id)
                    ->delete();
            }
            $paidAssignment->forceDelete();
            $unpaidAssignment->forceDelete();
            $paidComponent->forceDelete();
            $unpaidComponent->forceDelete();
        }
    }

    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM policies LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
