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
            if (! Schema::hasColumn('employee_organization_assignments', 'include_in_payroll')) {
                $table->boolean('include_in_payroll')->default(false)->after('is_primary');
            }
        });

        Schema::table('employee_organization_assignments', function (Blueprint $table): void {
            $table->index(
                ['company_id', 'include_in_payroll', 'is_active'],
                'employee_org_assign_payroll_eligible_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return;
        }

        Schema::table('employee_organization_assignments', function (Blueprint $table): void {
            $table->dropIndex('employee_org_assign_payroll_eligible_idx');
            if (Schema::hasColumn('employee_organization_assignments', 'include_in_payroll')) {
                $table->dropColumn('include_in_payroll');
            }
        });
    }
};
