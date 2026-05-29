<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ExecomEmployeeProfile;
use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\PayrollLine;
use App\Models\Payslip;
use App\Models\User;
use App\Services\FinalizePayrollService;
use App\Services\PayrollEmployeeEligibilityService;
use App\Services\PayrollReportService;
use App\Services\PayslipService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecomPayrollSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_and_execom_eligibility_scopes_are_mutually_exclusive(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $regular = $this->employee($company, 'REG-001');
        $execom = $this->employee($company, 'EXE-001');
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $service = new PayrollEmployeeEligibilityService;

        $regularIds = $service
            ->query((int) $company->id, null, null, Carbon::parse('2026-05-11'), Carbon::parse('2026-05-25'), null, null, PayrollBatchRun::MODULE_STANDARD)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $execomIds = $service
            ->query((int) $company->id, null, null, Carbon::parse('2026-05-11'), Carbon::parse('2026-05-25'), null, null, PayrollBatchRun::MODULE_EXECOM)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $regular->id, $regularIds);
        $this->assertNotContains((int) $execom->id, $regularIds);
        $this->assertSame([(int) $execom->id], $execomIds);
    }

    public function test_execom_shared_resolver_count_matches_list_query(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $execomA = $this->employee($company, 'EXE-A');
        $execomB = $this->employee($company, 'EXE-B');
        foreach ([$execomA, $execomB] as $employee) {
            ExecomEmployeeProfile::query()->create([
                'employee_id' => (int) $employee->id,
                'company_id' => (int) $company->id,
                'fixed_salary' => 80000,
                'effective_from' => '2026-05-01',
                'is_active' => true,
            ]);
        }

        $service = new PayrollEmployeeEligibilityService;
        $periodStart = Carbon::parse('2026-05-11');
        $periodEnd = Carbon::parse('2026-05-25');
        $ids = $service->getExecomPayrollEligibleEmployeeIds(
            (int) $company->id,
            null,
            null,
            $periodStart,
            $periodEnd
        );
        $listCount = (int) $service->getExecomPayrollEligibleEmployees(
            (int) $company->id,
            null,
            null,
            $periodStart,
            $periodEnd
        )->count();

        $this->assertCount(2, $ids);
        $this->assertSame(2, $listCount);
        $this->assertSame($listCount, count($ids));
    }

    public function test_regular_eligibility_excludes_employee_flagged_as_execom_even_without_profile(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $regular = $this->employee($company, 'REG-FLAG');
        $flaggedExecom = $this->employee($company, 'EXE-FLAG', ['is_execom' => true]);

        $service = new PayrollEmployeeEligibilityService;
        $regularIds = $service
            ->query((int) $company->id, null, null, Carbon::parse('2026-05-11'), Carbon::parse('2026-05-25'), null, null, PayrollBatchRun::MODULE_STANDARD)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $execomIds = $service
            ->query((int) $company->id, null, null, Carbon::parse('2026-05-11'), Carbon::parse('2026-05-25'), null, null, PayrollBatchRun::MODULE_EXECOM)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $regular->id, $regularIds);
        $this->assertNotContains((int) $flaggedExecom->id, $regularIds);
        $this->assertNotContains((int) $flaggedExecom->id, $execomIds);
    }

    public function test_batch_aggregates_filter_out_payslips_from_the_wrong_payroll_module_membership(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $regular = $this->employee($company, 'REG-002');
        $execom = $this->employee($company, 'EXE-002');
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $regularRun = $this->batchRun($company, PayrollBatchRun::MODULE_STANDARD);
        $execomRun = $this->batchRun($company, PayrollBatchRun::MODULE_EXECOM);
        $this->payslip($regularRun, $regular, PayrollBatchRun::MODULE_STANDARD, 1000);
        $this->payslip($regularRun, $execom, PayrollBatchRun::MODULE_STANDARD, 2000);
        $this->payslip($execomRun, $execom, PayrollBatchRun::MODULE_EXECOM, 3000);
        $this->payslip($execomRun, $regular, PayrollBatchRun::MODULE_EXECOM, 4000);

        $service = app(PayslipService::class);
        $regularAggregate = $service->aggregateForBatchRun($regularRun);
        $execomAggregate = $service->aggregateForBatchRun($execomRun);

        $this->assertSame(1, $regularAggregate['payslip_count']);
        $this->assertSame(1000.0, $regularAggregate['total_net_pay']);
        $this->assertSame(1, $execomAggregate['payslip_count']);
        $this->assertSame(3000.0, $execomAggregate['total_net_pay']);
    }

    public function test_regular_company_scope_excludes_active_execom_even_when_profile_context_differs(): void
    {
        $aci = Company::query()->create(['name' => 'ACI']);
        $otherCompany = Company::query()->create(['name' => 'Other Co']);
        $execom = $this->employee($otherCompany, 'EXE-CROSS');
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $aci->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $service = new PayrollEmployeeEligibilityService;
        $regularOtherCompanyIds = $service
            ->query((int) $otherCompany->id, null, null, Carbon::parse('2026-05-11'), Carbon::parse('2026-05-25'), null, null, PayrollBatchRun::MODULE_STANDARD)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertNotContains((int) $execom->id, $regularOtherCompanyIds);
    }

    public function test_regular_draft_cleanup_removes_active_execom_payslip_and_payroll_lines(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $regular = $this->employee($company, 'REG-003');
        $execom = $this->employee($company, 'EXE-003');
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $run = $this->batchRun($company, PayrollBatchRun::MODULE_STANDARD, PayrollBatchRun::STATUS_DRAFT);
        $this->payslip($run, $regular, PayrollBatchRun::MODULE_STANDARD, 1000, Payslip::STATUS_DRAFT);
        $stalePayslip = $this->payslip($run, $execom, PayrollBatchRun::MODULE_STANDARD, 2000, Payslip::STATUS_DRAFT);
        $payrollEmployee = PayrollEmployee::query()->create([
            'payslip_id' => (int) $stalePayslip->id,
            'payroll_batch_run_id' => (int) $run->id,
            'user_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'pay_period_start' => '2026-05-11',
            'pay_period_end' => '2026-05-25',
            'status' => PayrollEmployee::STATUS_DRAFT,
            'gross_pay' => 2000,
            'total_deductions' => 0,
            'net_pay' => 2000,
        ]);
        PayrollLine::query()->create([
            'payroll_employee_id' => (int) $payrollEmployee->id,
            'payslip_id' => (int) $stalePayslip->id,
            'line_key' => 'basic',
            'component_name' => 'Basic Pay',
            'type' => PayrollLine::TYPE_EARNING,
            'amount' => 2000,
            'status' => PayrollLine::STATUS_DRAFT,
        ]);

        $aggregate = app(PayslipService::class)->aggregateForBatchRun($run);

        $this->assertSame(1, $aggregate['payslip_count']);
        $this->assertSame(1000.0, $aggregate['total_net_pay']);
        $this->assertDatabaseMissing('payslips', ['id' => (int) $stalePayslip->id]);
        $this->assertDatabaseMissing('payroll_employees', ['id' => (int) $payrollEmployee->id]);
        $this->assertDatabaseMissing('payroll_lines', ['payroll_employee_id' => (int) $payrollEmployee->id]);
    }

    public function test_execom_batch_cleanup_removes_standard_module_draft_rows(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $execom = $this->employee($company, 'EXE-005');
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $run = PayrollBatchRun::query()->create([
            'batch_key' => 'execom-cleanup-'.uniqid('', true),
            'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
            'company_id' => null,
            'pay_period_start' => '2026-05-26',
            'pay_period_end' => '2026-06-10',
            'status' => PayrollBatchRun::STATUS_DRAFT,
        ]);
        $this->payslip($run, $execom, PayrollBatchRun::MODULE_STANDARD, 2000, Payslip::STATUS_DRAFT, '2026-05-26', '2026-06-10');
        $this->payslip($run, $execom, PayrollBatchRun::MODULE_EXECOM, 3000, Payslip::STATUS_DRAFT, '2026-05-26', '2026-06-10');

        $aggregate = app(PayslipService::class)->aggregateForBatchRun($run);

        $this->assertSame(1, $aggregate['payslip_count']);
        $this->assertSame(3000.0, $aggregate['total_net_pay']);
    }

    public function test_execom_finalize_requires_execom_draft_rows_after_standard_cleanup(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $execom = $this->employee($company, 'EXE-006');
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $run = PayrollBatchRun::query()->create([
            'batch_key' => 'execom-block-'.uniqid('', true),
            'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
            'company_id' => null,
            'pay_period_start' => '2026-05-26',
            'pay_period_end' => '2026-06-10',
            'status' => PayrollBatchRun::STATUS_DRAFT,
        ]);
        $this->payslip($run, $execom, PayrollBatchRun::MODULE_STANDARD, 2000, Payslip::STATUS_DRAFT, '2026-05-26', '2026-06-10');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot finalize: no execom draft payslips are linked');

        app(FinalizePayrollService::class)->finalizeQueuedRun($run, $admin);
    }

    public function test_regular_finalize_blocks_if_active_execom_still_exists_in_draft(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $regular = $this->employee($company, 'REG-004');
        $execom = $this->employee($company, 'EXE-004');
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);
        ExecomEmployeeProfile::query()->create([
            'employee_id' => (int) $execom->id,
            'company_id' => (int) $company->id,
            'fixed_salary' => 100000,
            'effective_from' => '2026-05-01',
            'is_active' => true,
        ]);

        $run = $this->batchRun($company, PayrollBatchRun::MODULE_STANDARD, PayrollBatchRun::STATUS_DRAFT);
        $this->payslip($run, $regular, PayrollBatchRun::MODULE_STANDARD, 1000, Payslip::STATUS_DRAFT);
        $this->payslip($run, $execom, PayrollBatchRun::MODULE_STANDARD, 2000, Payslip::STATUS_DRAFT);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Regular payroll contains EXECOM employees. Please regenerate Regular Payroll.');

        app(FinalizePayrollService::class)->finalizeQueuedRun($run, $admin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employee(Company $company, string $code, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'company_id' => (int) $company->id,
            'employee_code' => $code,
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
            'exclude_from_payroll' => false,
        ], $overrides));
    }

    private function batchRun(Company $company, string $payrollModule, string $status = PayrollBatchRun::STATUS_FINALIZED): PayrollBatchRun
    {
        return PayrollBatchRun::query()->create([
            'batch_key' => $payrollModule.'-'.uniqid('', true),
            'payroll_module' => $payrollModule,
            'company_id' => (int) $company->id,
            'pay_period_start' => '2026-05-11',
            'pay_period_end' => '2026-05-25',
            'status' => $status,
        ]);
    }

    public function test_execom_report_resolves_company_from_payslips_when_batch_has_no_company(): void
    {
        $company = Company::query()->create(['name' => 'ACI']);
        $execom = $this->employee($company, 'EXE-007');

        $run = PayrollBatchRun::query()->create([
            'batch_key' => 'execom-report-'.uniqid('', true),
            'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
            'company_id' => null,
            'pay_period_start' => '2026-05-26',
            'pay_period_end' => '2026-06-10',
            'status' => PayrollBatchRun::STATUS_FINALIZED,
        ]);
        $this->payslip($run, $execom, PayrollBatchRun::MODULE_EXECOM, 50000);

        $resolved = app(PayrollReportService::class)->resolveReportCompany($run);

        $this->assertSame((int) $company->id, (int) $resolved->id);
        $this->assertSame((int) $company->id, (int) $run->fresh()->company_id);
    }

    public function test_execom_report_can_render_batch_that_spans_multiple_companies(): void
    {
        $aci = Company::query()->create(['name' => 'ACI']);
        $cjm = Company::query()->create(['name' => 'CJM']);
        $firstExecom = $this->employee($aci, 'EXE-008');
        $secondExecom = $this->employee($cjm, 'EXE-009');
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);

        $run = PayrollBatchRun::query()->create([
            'batch_key' => 'execom-report-all-'.uniqid('', true),
            'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
            'company_id' => null,
            'pay_period_start' => '2026-05-26',
            'pay_period_end' => '2026-06-10',
            'status' => PayrollBatchRun::STATUS_FINALIZED,
        ]);
        $this->payslip($run, $firstExecom, PayrollBatchRun::MODULE_EXECOM, 50000);
        $this->payslip($run, $secondExecom, PayrollBatchRun::MODULE_EXECOM, 60000);

        $payload = app(PayrollReportService::class)->buildReportPayloadForRun($run, $admin);

        $this->assertTrue($payload['isExecomPayroll']);
        $this->assertNull($payload['company']);
        $this->assertSame('Execom', $payload['reportCompanyName']);
        $this->assertCount(2, $payload['rows']);
    }

    private function payslip(
        PayrollBatchRun $run,
        User $employee,
        string $payrollModule,
        float $netPay,
        string $status = Payslip::STATUS_FINALIZED,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): Payslip {
        return Payslip::query()->create([
            'user_id' => (int) $employee->id,
            'payroll_batch_run_id' => (int) $run->id,
            'payroll_module' => $payrollModule,
            'company_id' => $run->company_id !== null ? (int) $run->company_id : (int) $employee->company_id,
            'pay_period_start' => $periodStart ?? '2026-05-11',
            'pay_period_end' => $periodEnd ?? '2026-05-25',
            'period_slot' => 0,
            'gross_pay' => $netPay,
            'total_deductions' => 0,
            'net_pay' => $netPay,
            'snapshot' => ['summary' => ['net_pay_after_withholding_estimate' => $netPay]],
            'status' => $status,
            'finalized_at' => $status === Payslip::STATUS_FINALIZED ? now() : null,
        ]);
    }
}
