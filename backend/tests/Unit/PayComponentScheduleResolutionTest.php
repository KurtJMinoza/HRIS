<?php

namespace Tests\Unit;

use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Services\DeductionScheduleService;
use App\Support\PayComponentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayComponentScheduleResolutionTest extends TestCase
{
    public function test_normalize_for_storage_rejects_unknown_and_default_aliases(): void
    {
        $this->assertNull(PayComponentSchedule::normalizeForStorage(null));
        $this->assertNull(PayComponentSchedule::normalizeForStorage('default'));
        $this->assertNull(PayComponentSchedule::normalizeForStorage('DEFAULT'));
        $this->assertNull(PayComponentSchedule::normalizeForStorage(''));
        $this->assertNull(PayComponentSchedule::normalizeForStorage('not_a_schedule'));
        $this->assertSame('first_run', PayComponentSchedule::normalizeForStorage('first_run'));
        $this->assertSame('second_run', PayComponentSchedule::normalizeForStorage('second_run'));
        $this->assertSame('split', PayComponentSchedule::normalizeForStorage('split'));
        $this->assertSame('monthly', PayComponentSchedule::normalizeForStorage('monthly'));
    }

    public function test_map_override_to_deduction_constants(): void
    {
        $this->assertSame(DeductionScheduleSetting::SCHEDULE_15TH, PayComponentSchedule::mapOverrideToDeductionScheduleType('first_run'));
        $this->assertSame(DeductionScheduleSetting::SCHEDULE_30TH, PayComponentSchedule::mapOverrideToDeductionScheduleType('second_run'));
        $this->assertSame(DeductionScheduleSetting::SCHEDULE_BOTH, PayComponentSchedule::mapOverrideToDeductionScheduleType('split'));
        $this->assertSame(DeductionScheduleSetting::SCHEDULE_BOTH, PayComponentSchedule::mapOverrideToDeductionScheduleType('monthly'));
    }

    #[DataProvider('acceptanceCases')]
    public function test_resolver_acceptance_company_default_split_with_employee_override(
        string $overrideSlug,
        string $expectResolved,
        string $expectSource
    ): void {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $payComponent = PayComponent::create([
            'name' => 'Test allowance '.$user->id,
            'code' => 'TEST_ALLOW_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 1000,
            'is_taxable' => false,
            'is_active' => true,
        ]);

        DeductionScheduleSetting::query()->updateOrCreate(
            ['company_id' => null, 'deduction_key' => 'pay_component:'.$payComponent->id],
            ['schedule_type' => DeductionScheduleSetting::SCHEDULE_BOTH]
        );

        $assignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $payComponent->id,
            'name' => $payComponent->name,
            'code' => $payComponent->code,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 5000,
            'is_taxable' => false,
            'is_active' => true,
            'schedule_override' => PayComponentSchedule::normalizeForStorage($overrideSlug),
        ]);

        try {
            $svc = app(DeductionScheduleService::class);
            $resolved = $svc->resolveEmployeePayComponentSchedule(
                (int) $payComponent->id,
                $user->getEffectiveCompanyId(),
                (int) $user->id,
                true,
                $assignment->schedule_override,
                (int) $assignment->id,
            );
            $this->assertSame($expectResolved, $resolved['resolved_schedule']);
            $this->assertSame($expectSource, $resolved['schedule_source']);
        } finally {
            $assignment->forceDelete();
            DeductionScheduleSetting::query()
                ->where('deduction_key', 'pay_component:'.$payComponent->id)
                ->delete();
            $payComponent->forceDelete();
            $user->forceDelete();
        }
    }

    public static function acceptanceCases(): array
    {
        return [
            'first_run_vs_split' => ['first_run', DeductionScheduleSetting::SCHEDULE_15TH, 'employee_override'],
            'second_run_vs_split' => ['second_run', DeductionScheduleSetting::SCHEDULE_30TH, 'employee_override'],
            'split_vs_split' => ['split', DeductionScheduleSetting::SCHEDULE_BOTH, 'employee_override'],
            'default_uses_company_split' => ['default', DeductionScheduleSetting::SCHEDULE_BOTH, 'default_schedule'],
        ];
    }

    #[DataProvider('calculationStandardCases')]
    public function test_resolve_pay_component_amount_honors_calculation_standard(
        string $standard,
        float $amount,
        string $segment,
        float $expected
    ): void {
        $svc = app(DeductionScheduleService::class);
        $resolved = $svc->resolvePayComponentAmount([
            'computed_amount' => $amount,
            'calculation_standard' => $standard,
            'resolved_schedule' => DeductionScheduleSetting::SCHEDULE_BOTH,
        ], [
            'reference_date' => $segment === 'first' ? '2026-05-15' : '2026-05-30',
            'selected_pay_date' => $segment === 'first' ? '2026-05-15' : '2026-05-30',
            'segment' => $segment,
            'pay_cycle_code' => 'semi_monthly',
        ]);

        $this->assertSame($standard, $resolved['calculation_standard']);
        $this->assertSame($expected, $resolved['applied_amount']);
    }

    public static function calculationStandardCases(): array
    {
        return [
            'test_1_monthly_1000_15th' => [PayComponent::STANDARD_MONTHLY, 1000.0, 'first', 500.0],
            'test_1_monthly_1000_30th' => [PayComponent::STANDARD_MONTHLY, 1000.0, 'second', 500.0],
            'test_2_payroll_1000_15th' => [PayComponent::STANDARD_PAYROLL, 1000.0, 'first', 1000.0],
            'test_2_payroll_1000_30th' => [PayComponent::STANDARD_PAYROLL, 1000.0, 'second', 1000.0],
            'test_3_monthly_500_15th' => [PayComponent::STANDARD_MONTHLY, 500.0, 'first', 250.0],
            'test_3_monthly_500_30th' => [PayComponent::STANDARD_MONTHLY, 500.0, 'second', 250.0],
            'test_4_payroll_500_15th' => [PayComponent::STANDARD_PAYROLL, 500.0, 'first', 500.0],
            'test_4_payroll_500_30th' => [PayComponent::STANDARD_PAYROLL, 500.0, 'second', 500.0],
        ];
    }

    /** When two active rows share one pay component, resolution must be keyed by assignment id. */
    public function test_assignment_id_disambiguates_duplicate_pay_component_rows(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $payComponent = PayComponent::create([
            'name' => 'Dup test '.$user->id,
            'code' => 'DUP_PC_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 1,
            'is_taxable' => false,
            'is_active' => true,
        ]);
        DeductionScheduleSetting::query()->updateOrCreate(
            ['company_id' => null, 'deduction_key' => 'pay_component:'.$payComponent->id],
            ['schedule_type' => DeductionScheduleSetting::SCHEDULE_BOTH]
        );

        $older = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $payComponent->id,
            'name' => $payComponent->name.' A',
            'code' => $payComponent->code.'_A',
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 100,
            'is_taxable' => false,
            'is_active' => true,
            'schedule_override' => 'first_run',
        ]);
        $newer = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $payComponent->id,
            'name' => $payComponent->name.' B',
            'code' => $payComponent->code.'_B',
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 200,
            'is_taxable' => false,
            'is_active' => true,
            'schedule_override' => null,
        ]);

        try {
            $svc = app(DeductionScheduleService::class);
            $rNewer = $svc->resolveScheduleType(
                'pay_component:'.$payComponent->id,
                $user->getEffectiveCompanyId(),
                (int) $user->id,
                (int) $payComponent->id,
                (int) $newer->id
            );
            $rOlder = $svc->resolveScheduleType(
                'pay_component:'.$payComponent->id,
                $user->getEffectiveCompanyId(),
                (int) $user->id,
                (int) $payComponent->id,
                (int) $older->id
            );
            $this->assertSame(DeductionScheduleSetting::SCHEDULE_BOTH, $rNewer);
            $this->assertSame(DeductionScheduleSetting::SCHEDULE_15TH, $rOlder);
        } finally {
            $newer->forceDelete();
            $older->forceDelete();
            DeductionScheduleSetting::query()
                ->where('deduction_key', 'pay_component:'.$payComponent->id)
                ->delete();
            $payComponent->forceDelete();
            $user->forceDelete();
        }
    }

    public function test_summarize_prefers_resolved_schedule_on_earning_line(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $payComponent = PayComponent::create([
            'name' => 'Summary line '.$user->id,
            'code' => 'SUM_LINE_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'default_value' => 2000,
            'is_taxable' => false,
            'is_active' => true,
        ]);
        DeductionScheduleSetting::query()->updateOrCreate(
            ['company_id' => null, 'deduction_key' => 'pay_component:'.$payComponent->id],
            ['schedule_type' => DeductionScheduleSetting::SCHEDULE_BOTH]
        );

        try {
            $summary = [
                'statutory' => [],
                'totals' => ['withholding_tax' => 0],
                'deductions' => [],
                'earnings' => [
                    [
                        'id' => 999001,
                        'pay_component_id' => $payComponent->id,
                        'code' => 'X',
                        'computed_amount' => 2000.0,
                        'is_proratable' => false,
                        'resolved_schedule' => DeductionScheduleSetting::SCHEDULE_15TH,
                        'schedule_override' => 'first_run',
                        'schedule_source' => 'employee_override',
                        'default_schedule' => DeductionScheduleSetting::SCHEDULE_BOTH,
                    ],
                ],
                'pay_cycle_preview' => null,
                '_attendance_proration' => null,
            ];
            $svc = app(DeductionScheduleService::class);
            $out = $svc->summarizeForPayrollComputation($user, Carbon::parse('2026-05-09', 'Asia/Manila'), $summary);
            $line = $out['earning_lines'][0] ?? null;
            $this->assertNotNull($line);
            $this->assertSame(DeductionScheduleSetting::SCHEDULE_15TH, $line['earning_schedule_type'] ?? null);
        } finally {
            DeductionScheduleSetting::query()
                ->where('deduction_key', 'pay_component:'.$payComponent->id)
                ->delete();
            $payComponent->forceDelete();
            $user->forceDelete();
        }
    }

    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM deduction_schedule_settings LIMIT 1');
            DB::select('SELECT 1 FROM pay_components LIMIT 1');
            DB::select('SELECT 1 FROM employee_compensation_components LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
