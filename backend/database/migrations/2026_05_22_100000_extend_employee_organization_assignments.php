<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return;
        }

        Schema::table('employee_organization_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_organization_assignments', 'assignment_type')) {
                $table->string('assignment_type', 20)->default('primary')->after('organization_unit_id');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('assignment_type');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'division_id')) {
                $table->unsignedBigInteger('division_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable()->after('division_id');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'section_unit_id')) {
                $table->unsignedBigInteger('section_unit_id')->nullable()->after('department_id');
            }
            if (! Schema::hasColumn('employee_organization_assignments', 'remarks')) {
                $table->text('remarks')->nullable()->after('is_active');
            }
        });

        if (Schema::hasColumn('employee_organization_assignments', 'assignment_type')) {
            Schema::table('employee_organization_assignments', function (Blueprint $table): void {
                $table->index(['employee_id', 'organization_unit_id', 'is_active'], 'employee_org_assign_unit_active_emp_index');
                $table->index(['department_id', 'is_active'], 'employee_org_assign_dept_active_index');
                $table->index(['division_id', 'is_active'], 'employee_org_assign_div_active_index');
                $table->index(['section_unit_id', 'is_active'], 'employee_org_assign_section_active_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return;
        }

        Schema::table('employee_organization_assignments', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['assignment_type', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id', 'remarks'],
                fn (string $column): bool => Schema::hasColumn('employee_organization_assignments', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
