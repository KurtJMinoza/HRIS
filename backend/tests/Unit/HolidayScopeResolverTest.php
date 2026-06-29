<?php

namespace Tests\Unit;

use App\Models\EmployeeOrganizationAssignment;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayScopeResolver;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HolidayScopeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('divisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('division_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('sections_or_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('division_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('employee_organization_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('assignment_type')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('division_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('section_unit_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
    }

    public function test_selected_branch_requires_matching_branch_id_and_company(): void
    {
        [$mchisiCompany, $mchisiBajada, $mchisiOther] = $this->seedCompanyWithBranches('MCHISI');
        [$aciCompany, $aciBajada] = $this->seedCompanyWithBranches('ACI');

        $holiday = $this->holiday('branches', [$mchisiBajada]);
        $mchisiEmployee = $this->employee($mchisiCompany, $mchisiBajada);
        $sameNameOtherCompany = $this->employee($aciCompany, $aciBajada);
        $unselectedMchisiEmployee = $this->employee($mchisiCompany, $mchisiOther);
        $inconsistentEmployee = $this->employee($aciCompany, $mchisiBajada);
        $nullBranchEmployee = $this->employee($mchisiCompany, null);

        $resolver = app(HolidayScopeResolver::class);
        $date = Carbon::parse('2026-06-17');

        $this->assertTrue($resolver->appliesToEmployee($holiday, $mchisiEmployee, $date));
        $this->assertFalse($resolver->appliesToEmployee($holiday, $sameNameOtherCompany, $date));
        $this->assertFalse($resolver->appliesToEmployee($holiday, $unselectedMchisiEmployee, $date));
        $this->assertFalse($resolver->appliesToEmployee($holiday, $inconsistentEmployee, $date));
        $this->assertFalse($resolver->appliesToEmployee($holiday, $nullBranchEmployee, $date));
    }

    public function test_company_nationwide_and_employee_scopes_are_isolated(): void
    {
        [$mchisiCompany, $mchisiBranch] = $this->seedCompanyWithBranches('MCHISI');
        [$aciCompany, $aciBranch] = $this->seedCompanyWithBranches('ACI');
        $mchisiEmployee = $this->employee($mchisiCompany, $mchisiBranch);
        $aciEmployee = $this->employee($aciCompany, $aciBranch);
        $resolver = app(HolidayScopeResolver::class);
        $date = Carbon::parse('2026-06-17');

        $companyHoliday = $this->holiday('company', [$mchisiCompany]);
        $this->assertTrue($resolver->appliesToEmployee($companyHoliday, $mchisiEmployee, $date));
        $this->assertFalse($resolver->appliesToEmployee($companyHoliday, $aciEmployee, $date));

        $nationwide = new Holiday([
            'name' => 'Nationwide Day',
            'date' => $date,
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);
        $this->assertTrue($resolver->appliesToEmployee($nationwide, $mchisiEmployee, $date));
        $this->assertTrue($resolver->appliesToEmployee($nationwide, $aciEmployee, $date));

        $employeeHoliday = $this->holiday('employees', [(int) $mchisiEmployee->id]);
        $this->assertTrue($resolver->appliesToEmployee($employeeHoliday, $mchisiEmployee, $date));
        $this->assertFalse($resolver->appliesToEmployee($employeeHoliday, $aciEmployee, $date));
    }

    public function test_date_effective_primary_assignment_is_used_instead_of_stale_legacy_fields(): void
    {
        [$mchisiCompany, $mchisiBranch] = $this->seedCompanyWithBranches('MCHISI');
        [$aciCompany, $aciBranch] = $this->seedCompanyWithBranches('ACI');
        $employee = $this->employee($aciCompany, $aciBranch);

        EmployeeOrganizationAssignment::withoutEvents(function () use ($employee, $mchisiCompany, $mchisiBranch): void {
            EmployeeOrganizationAssignment::query()->create([
                'employee_id' => (int) $employee->id,
                'assignment_type' => EmployeeOrganizationAssignment::TYPE_PRIMARY,
                'company_id' => $mchisiCompany,
                'branch_id' => $mchisiBranch,
                'is_primary' => true,
                'is_active' => true,
                'effective_from' => '2026-06-01',
                'effective_to' => '2026-06-30',
            ]);
        });

        $holiday = $this->holiday('branches', [$mchisiBranch]);
        $holiday->is_recurring = true;
        $resolver = app(HolidayScopeResolver::class);

        $this->assertTrue($resolver->appliesToEmployee($holiday, $employee, Carbon::parse('2026-06-17')));
        $this->assertFalse($resolver->appliesToEmployee($holiday, $employee, Carbon::parse('2027-06-17')));
    }

    public function test_division_department_and_section_scopes_require_the_full_parent_path(): void
    {
        [$companyId, $branchId] = $this->seedCompanyWithBranches('MCHISI');
        [$otherCompanyId] = $this->seedCompanyWithBranches('ACI');
        $now = now();
        $divisionId = DB::table('divisions')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => 'Operations',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $departmentId = DB::table('departments')->insertGetId([
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'name' => 'Support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sectionId = DB::table('sections_or_units')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'name' => 'Unit A',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employee = $this->employee($companyId, $branchId);
        $employee->forceFill([
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionId,
        ]);
        $inconsistentEmployee = $this->employee($otherCompanyId, $branchId);
        $inconsistentEmployee->forceFill([
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionId,
        ]);
        $resolver = app(HolidayScopeResolver::class);
        $date = Carbon::parse('2026-06-17');

        foreach ([
            $this->holiday('divisions', [$divisionId]),
            $this->holiday('departments', [$departmentId]),
            $this->holiday('section_units', [$sectionId]),
        ] as $holiday) {
            $this->assertTrue($resolver->appliesToEmployee($holiday, $employee, $date));
            $this->assertFalse($resolver->appliesToEmployee($holiday, $inconsistentEmployee, $date));
        }
    }

    private function holiday(string $coverageType, array $coverageIds): Holiday
    {
        return new Holiday([
            'name' => 'Scoped Holiday',
            'date' => '2026-06-17',
            'type' => 'regular',
            'scope' => match ($coverageType) {
                'company' => 'company',
                'branches' => 'branch',
                'divisions' => 'division',
                'departments' => 'department',
                'section_units' => 'section_unit',
                'employees' => 'employee',
                default => 'nationwide',
            },
            'coverage_type' => $coverageType,
            'coverage_ids' => $coverageIds,
            'status' => 'active',
        ]);
    }

    private function employee(int $companyId, ?int $branchId): User
    {
        static $nextId = 1000;

        $employee = new User;
        $employee->forceFill([
            'id' => ++$nextId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'is_active' => true,
        ]);

        return $employee;
    }

    /** @return list<int> */
    private function seedCompanyWithBranches(string $companyName): array
    {
        $now = now();
        $companyId = DB::table('companies')->insertGetId([
            'name' => $companyName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $bajadaId = DB::table('branches')->insertGetId([
            'company_id' => $companyId,
            'name' => 'BAJADA',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherId = DB::table('branches')->insertGetId([
            'company_id' => $companyId,
            'name' => 'OTHER',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$companyId, $bajadaId, $otherId];
    }
}
