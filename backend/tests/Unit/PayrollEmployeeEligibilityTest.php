<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\ExecomEmployeeProfile;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\PayrollLine;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollEmployeeEligibilityService;
use App\Services\PayslipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayrollEmployeeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private PayrollEmployeeEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PayrollEmployeeEligibilityService;
    }

    public function test_employee_hired_after_payroll_period_end_is_excluded(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2026-05-20',
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertNotContains((int) $employee->id, $ids);

        $evaluation = $this->service->evaluateEmployeeEligibility(
            $employee,
            (int) $company->id,
            $periodStart,
            $periodEnd
        );
        $this->assertFalse($evaluation['included']);
        $this->assertSame(PayrollEmployeeEligibilityService::EXCLUSION_PAYROLL_EFFECTIVE_AFTER_PERIOD, $evaluation['exclusion_reason']);
    }

    public function test_employee_created_after_period_without_hire_date_is_excluded(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => null,
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertNotContains((int) $employee->id, $ids);
    }

    public function test_old_hire_date_does_not_override_created_after_period(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2023-07-03',
            'payroll_effective_date' => '2026-05-20',
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertNotContains((int) $employee->id, $ids);
    }

    public function test_employee_created_during_period_is_included_when_payroll_effective_date_is_in_period(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2023-07-03',
            'payroll_effective_date' => '2026-05-01',
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertContains((int) $employee->id, $ids);
    }

    public function test_employee_created_in_period_is_excluded_when_payroll_effective_date_is_after_period(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2023-07-03',
            'payroll_effective_date' => '2026-05-20',
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertNotContains((int) $employee->id, $ids);
    }

    public function test_employee_created_in_second_period_is_included_when_payroll_effective_date_is_before_period_end(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2023-07-03',
            'payroll_effective_date' => '2026-05-20',
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-05-11');
        $periodEnd = Carbon::parse('2026-05-25');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertContains((int) $employee->id, $ids);
    }

    public function test_employee_created_during_late_may_cutoff_is_eligible_for_that_period(): void
    {
        $company = Company::query()->create(['name' => 'MCHISI']);
        $employee = $this->employee($company, [
            'hire_date' => '2023-07-03',
            'payroll_effective_date' => '2026-05-28',
            'created_at' => Carbon::parse('2026-05-28 09:00:00'),
        ]);

        $periodStart = Carbon::parse('2026-05-26');
        $periodEnd = Carbon::parse('2026-06-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertContains((int) $employee->id, $ids);
    }

    public function test_employee_hired_mid_period_is_included_and_computation_clamps_to_hire_date(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2026-05-05',
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertContains((int) $employee->id, $ids);

        $clamped = $this->service->clampComputationStart($employee, $periodStart, $periodEnd);
        $this->assertSame('2026-05-05', $clamped->toDateString());
    }

    public function test_assignment_effective_after_payroll_period_excludes_employee(): void
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            $this->markTestSkipped('employee_organization_assignments table is not available.');
        }

        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2026-01-01',
            'company_id' => null,
        ]);

        $orgType = OrganizationType::query()->create([
            'name' => 'Department',
            'code' => 'department_'.uniqid(),
            'level_order' => 1,
            'is_system' => false,
            'is_active' => true,
        ]);
        $unit = OrganizationUnit::query()->create([
            'organization_type_id' => (int) $orgType->id,
            'company_id' => (int) $company->id,
            'name' => 'HQ',
            'is_active' => true,
            'approval_routing_rule' => OrganizationUnit::ROUTING_FIRST_ASSIGNED,
        ]);

        EmployeeOrganizationAssignment::query()->create([
            'employee_id' => (int) $employee->id,
            'organization_unit_id' => (int) $unit->id,
            'assignment_type' => EmployeeOrganizationAssignment::TYPE_PRIMARY,
            'company_id' => (int) $company->id,
            'is_primary' => true,
            'is_active' => true,
            'effective_from' => '2026-05-20',
            'effective_to' => null,
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->eligibleIds($company, $periodStart, $periodEnd);
        $this->assertNotContains((int) $employee->id, $ids);
    }

    public function test_execom_profile_effective_after_period_excludes_employee_from_execom_payroll(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $employee = $this->employee($company, [
            'hire_date' => '2026-01-01',
        ]);

        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $employee->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-20',
            'is_active' => true,
        ]);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ids = $this->service->getPayrollEligibleEmployeeIds(
            (int) $company->id,
            null,
            null,
            $periodStart,
            $periodEnd,
            null,
            null,
            PayrollBatchRun::MODULE_EXECOM
        );

        $this->assertNotContains((int) $employee->id, $ids);
    }

    public function test_stale_draft_cleanup_removes_employee_hired_after_payroll_period(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $eligible = $this->employee($company, ['hire_date' => '2026-01-01', 'employee_code' => 'OLD-001']);
        $newHire = $this->employee($company, ['hire_date' => '2026-05-20', 'employee_code' => 'NEW-001']);

        $run = PayrollBatchRun::query()->create([
            'batch_key' => 'eligibility-cleanup-'.uniqid('', true),
            'payroll_module' => PayrollBatchRun::MODULE_STANDARD,
            'company_id' => (int) $company->id,
            'pay_period_start' => '2026-04-26',
            'pay_period_end' => '2026-05-10',
            'status' => PayrollBatchRun::STATUS_DRAFT,
        ]);

        $this->createDraftPayslip($run, $eligible, 1000);
        $stale = $this->createDraftPayslip($run, $newHire, 2000);

        $aggregate = app(PayslipService::class)->aggregateForBatchRun($run);

        $this->assertSame(1, $aggregate['payslip_count']);
        $this->assertSame(1, (int) $run->fresh()->employee_count);
        $this->assertDatabaseMissing('payslips', ['id' => (int) $stale->id]);
    }

    public function test_find_ineligible_draft_employee_ids_flags_post_period_hires(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $newHire = $this->employee($company, ['hire_date' => '2026-05-20', 'employee_code' => 'NEW-002']);

        $periodStart = Carbon::parse('2026-04-26');
        $periodEnd = Carbon::parse('2026-05-10');

        $ineligible = $this->service->findIneligibleDraftEmployeeIds(
            [(int) $newHire->id],
            (int) $company->id,
            null,
            null,
            $periodStart,
            $periodEnd
        );

        $this->assertSame([(int) $newHire->id], $ineligible);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employee(Company $company, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'company_id' => (int) $company->id,
            'employee_code' => 'EMP-'.uniqid(),
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
            'exclude_from_payroll' => false,
            'hire_date' => '2026-01-01',
            'payroll_effective_date' => '2026-01-01',
            'created_at' => Carbon::parse('2026-01-01 09:00:00'),
        ], $overrides));
    }

    /**
     * @return list<int>
     */
    private function eligibleIds(Company $company, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->service->getPayrollEligibleEmployeeIds(
            (int) $company->id,
            null,
            null,
            $periodStart,
            $periodEnd
        );
    }

    private function createDraftPayslip(PayrollBatchRun $run, User $employee, float $netPay): Payslip
    {
        $payslip = Payslip::query()->create([
            'user_id' => (int) $employee->id,
            'payroll_batch_run_id' => (int) $run->id,
            'payroll_module' => PayrollBatchRun::MODULE_STANDARD,
            'company_id' => (int) $run->company_id,
            'pay_period_start' => $run->pay_period_start,
            'pay_period_end' => $run->pay_period_end,
            'period_slot' => 0,
            'gross_pay' => $netPay,
            'total_deductions' => 0,
            'net_pay' => $netPay,
            'snapshot' => ['summary' => ['net_pay_after_withholding_estimate' => $netPay]],
            'status' => Payslip::STATUS_DRAFT,
        ]);

        $payrollEmployee = PayrollEmployee::query()->create([
            'payslip_id' => (int) $payslip->id,
            'payroll_batch_run_id' => (int) $run->id,
            'user_id' => (int) $employee->id,
            'company_id' => (int) $run->company_id,
            'pay_period_start' => $run->pay_period_start,
            'pay_period_end' => $run->pay_period_end,
            'status' => PayrollEmployee::STATUS_DRAFT,
            'gross_pay' => $netPay,
            'total_deductions' => 0,
            'net_pay' => $netPay,
        ]);
        PayrollLine::query()->create([
            'payroll_employee_id' => (int) $payrollEmployee->id,
            'payslip_id' => (int) $payslip->id,
            'line_key' => 'basic',
            'component_name' => 'Basic Pay',
            'type' => PayrollLine::TYPE_EARNING,
            'amount' => $netPay,
            'status' => PayrollLine::STATUS_DRAFT,
        ]);

        return $payslip;
    }
}
