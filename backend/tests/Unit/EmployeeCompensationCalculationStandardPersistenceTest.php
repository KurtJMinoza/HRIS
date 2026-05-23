<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\EmployeeCompensationController;
use App\Http\Controllers\Admin\PayComponentController;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use App\Support\CalculationStandard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeCompensationCalculationStandardPersistenceTest extends TestCase
{
    private function skipUnlessOverrideColumn(): void
    {
        if (! Schema::hasTable('employee_compensation_components')
            || ! Schema::hasColumn('employee_compensation_components', 'calculation_standard_override')) {
            $this->markTestSkipped('calculation_standard_override column not available');
        }
    }

    public function test_normalize_for_storage_treats_default_as_null(): void
    {
        $this->assertNull(CalculationStandard::normalizeForStorage(null));
        $this->assertNull(CalculationStandard::normalizeForStorage('default'));
        $this->assertNull(CalculationStandard::normalizeForStorage('use_default'));
        $this->assertSame(PayComponent::STANDARD_PAYROLL, CalculationStandard::normalizeForStorage('payroll_standard'));
    }

    public function test_pay_component_create_persists_payroll_standard_default(): void
    {
        if (! Schema::hasTable('pay_components') || ! Schema::hasColumn('pay_components', 'calculation_standard')) {
            $this->markTestSkipped('pay component calculation_standard column not available');
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $code = 'PAYROLL_STD_'.$admin->id;
        $request = Request::create('/', 'POST', [
            'name' => 'Payroll Standard Component '.$admin->id,
            'code' => $code,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'calculation_standard' => PayComponent::STANDARD_PAYROLL,
            'default_value' => 17500,
            'is_taxable' => false,
            'is_proratable' => true,
            'is_active' => true,
        ]);
        $request->setUserResolver(fn () => $admin);

        try {
            app(PayComponentController::class)->store($request);
            $component = PayComponent::query()->where('code', $code)->firstOrFail();

            $this->assertSame(PayComponent::STANDARD_PAYROLL, $component->calculation_standard);
        } finally {
            PayComponent::query()->where('code', $code)->get()->each->forceDelete();
            $admin->forceDelete();
        }
    }

    public function test_patch_persists_payroll_override_independently_of_default(): void
    {
        $this->skipUnlessOverrideColumn();
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $allowance = PayComponent::create([
            'name' => 'Allowance '.$user->id,
            'code' => 'ALLOW_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'category' => 'Fixed Allowance',
            'calculation_type' => PayComponent::CALC_FIXED,
            'calculation_standard' => PayComponent::STANDARD_MONTHLY,
            'default_value' => 1000,
            'is_active' => true,
        ]);
        $deduction = PayComponent::create([
            'name' => 'Deduction '.$user->id,
            'code' => 'DED_'.$user->id,
            'type' => PayComponent::TYPE_DEDUCTION,
            'category' => 'Other Deduction',
            'calculation_type' => PayComponent::CALC_FIXED,
            'calculation_standard' => PayComponent::STANDARD_PAYROLL,
            'default_value' => 500,
            'is_active' => true,
        ]);

        $allowanceAssignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $allowance->id,
            'name' => $allowance->name,
            'code' => $allowance->code,
            'type' => PayComponent::TYPE_EARNING,
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 1000,
            'is_active' => true,
        ]);
        $deductionAssignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $deduction->id,
            'name' => $deduction->name,
            'code' => $deduction->code,
            'type' => PayComponent::TYPE_DEDUCTION,
            'calculation_type' => PayComponent::CALC_FIXED,
            'value' => 500,
            'is_active' => true,
        ]);

        try {
            $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
            $this->actingAs($admin);
            $controller = app(EmployeeCompensationController::class);

            $patchAllowance = Request::create('/', 'PATCH', [
                'calculation_standard_override' => PayComponent::STANDARD_PAYROLL,
            ]);
            $patchAllowance->setUserResolver(fn () => $admin);
            $controller->update($patchAllowance, $user->id, $allowanceAssignment->id);

            $patchDeduction = Request::create('/', 'PATCH', [
                'calculation_standard_override' => PayComponent::STANDARD_MONTHLY,
            ]);
            $patchDeduction->setUserResolver(fn () => $admin);
            $controller->update($patchDeduction, $user->id, $deductionAssignment->id);

            $allowanceAssignment->refresh();
            $deductionAssignment->refresh();

            $this->assertSame(PayComponent::STANDARD_PAYROLL, $allowanceAssignment->calculation_standard_override);
            $this->assertSame(PayComponent::STANDARD_MONTHLY, $deductionAssignment->calculation_standard_override);

            $summary = app(PayrollCalculatorService::class)->buildEmployeeCompensationSummary($user->fresh(), [
                'as_of_date' => now()->toDateString(),
                'allow_compute' => true,
            ]);

            $allowanceLine = collect($summary['earnings'])->firstWhere('id', $allowanceAssignment->id);
            $deductionLine = collect($summary['deductions'])->firstWhere('id', $deductionAssignment->id);

            $this->assertNotNull($allowanceLine);
            $this->assertNotNull($deductionLine);
            $this->assertSame(PayComponent::STANDARD_PAYROLL, $allowanceLine['resolved_calculation_standard']);
            $this->assertSame('employee_override', $allowanceLine['calculation_standard_source']);
            $this->assertSame(PayComponent::STANDARD_MONTHLY, $deductionLine['resolved_calculation_standard']);
            $this->assertSame('employee_override', $deductionLine['calculation_standard_source']);
        } finally {
            EmployeeCompensationComponent::query()->where('user_id', $user->id)->forceDelete();
            $allowance->forceDelete();
            $deduction->forceDelete();
            $user->forceDelete();
            if (isset($admin)) {
                $admin->forceDelete();
            }
        }
    }

    public function test_amount_update_preserves_calculation_standard_override(): void
    {
        $this->skipUnlessOverrideColumn();
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $component = PayComponent::create([
            'name' => 'Allowance '.$user->id,
            'code' => 'ALLOW2_'.$user->id,
            'type' => PayComponent::TYPE_EARNING,
            'calculation_type' => PayComponent::CALC_FIXED,
            'calculation_standard' => PayComponent::STANDARD_MONTHLY,
            'default_value' => 1000,
            'is_active' => true,
        ]);
        $assignment = EmployeeCompensationComponent::create([
            'user_id' => $user->id,
            'pay_component_id' => $component->id,
            'name' => $component->name,
            'code' => $component->code,
            'type' => PayComponent::TYPE_EARNING,
            'calculation_type' => PayComponent::CALC_FIXED,
            'calculation_standard_override' => PayComponent::STANDARD_PAYROLL,
            'value' => 1000,
            'is_active' => true,
        ]);

        try {
            $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
            $this->actingAs($admin);
            $patchValue = Request::create('/', 'PATCH', ['value' => 1200]);
            $patchValue->setUserResolver(fn () => $admin);
            app(EmployeeCompensationController::class)->update($patchValue, $user->id, $assignment->id);

            $assignment->refresh();
            $this->assertSame(1200.0, (float) $assignment->value);
            $this->assertSame(PayComponent::STANDARD_PAYROLL, $assignment->calculation_standard_override);
        } finally {
            $assignment->forceDelete();
            $component->forceDelete();
            $user->forceDelete();
            if (isset($admin)) {
                $admin->forceDelete();
            }
        }
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasTable('pay_components')
            && Schema::hasTable('employee_compensation_components');
    }
}
