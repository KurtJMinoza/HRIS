<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addUsersIndexes();
        $this->addPayrollIndexes();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('users', 'users_role_idx');
        $this->dropIndexIfExists('users', 'users_company_idx');
        $this->dropIndexIfExists('users', 'users_branch_idx');
        $this->dropIndexIfExists('users', 'users_dept_idx');
        $this->dropIndexIfExists('users', 'users_active_idx');
        $this->dropIndexIfExists('users', 'users_emp_status_eff_idx');
        $this->dropIndexIfExists('users', 'users_role_org_active_idx');

        $this->dropIndexIfExists('pay_employee_deductions', 'ped_user_active_idx');
        $this->dropIndexIfExists('pay_employee_deductions', 'ped_sched_idx');
        $this->dropIndexIfExists('pay_loan_requests', 'plr_user_status_idx');
        $this->dropIndexIfExists('pay_loan_requests', 'plr_dedsched_status_idx');
        $this->dropIndexIfExists('employee_compensation_components', 'ecc_user_active_eff_idx');
    }

    private function addUsersIndexes(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role') && ! $this->indexExists('users', 'users_role_idx')) {
                $table->index('role', 'users_role_idx');
            }
            if (Schema::hasColumn('users', 'company_id') && ! $this->indexExists('users', 'users_company_idx')) {
                $table->index('company_id', 'users_company_idx');
            }
            if (Schema::hasColumn('users', 'branch_id') && ! $this->indexExists('users', 'users_branch_idx')) {
                $table->index('branch_id', 'users_branch_idx');
            }
            if (Schema::hasColumn('users', 'department_id') && ! $this->indexExists('users', 'users_dept_idx')) {
                $table->index('department_id', 'users_dept_idx');
            }
            if (Schema::hasColumn('users', 'is_active') && ! $this->indexExists('users', 'users_active_idx')) {
                $table->index('is_active', 'users_active_idx');
            }
            if (Schema::hasColumn('users', 'employment_status_effective_date') && ! $this->indexExists('users', 'users_emp_status_eff_idx')) {
                $table->index('employment_status_effective_date', 'users_emp_status_eff_idx');
            }
            if (
                Schema::hasColumn('users', 'role')
                && Schema::hasColumn('users', 'company_id')
                && Schema::hasColumn('users', 'branch_id')
                && Schema::hasColumn('users', 'department_id')
                && Schema::hasColumn('users', 'is_active')
                && ! $this->indexExists('users', 'users_role_org_active_idx')
            ) {
                $table->index(['role', 'company_id', 'branch_id', 'department_id', 'is_active'], 'users_role_org_active_idx');
            }
        });
    }

    private function addPayrollIndexes(): void
    {
        if (Schema::hasTable('pay_employee_deductions')) {
            Schema::table('pay_employee_deductions', function (Blueprint $table) {
                if (
                    Schema::hasColumn('pay_employee_deductions', 'user_id')
                    && Schema::hasColumn('pay_employee_deductions', 'is_active')
                    && ! $this->indexExists('pay_employee_deductions', 'ped_user_active_idx')
                ) {
                    $table->index(['user_id', 'is_active'], 'ped_user_active_idx');
                }
                if (
                    Schema::hasColumn('pay_employee_deductions', 'deduction_schedule')
                    && ! $this->indexExists('pay_employee_deductions', 'ped_sched_idx')
                ) {
                    $table->index('deduction_schedule', 'ped_sched_idx');
                }
            });
        }

        if (Schema::hasTable('pay_loan_requests')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                if (
                    Schema::hasColumn('pay_loan_requests', 'user_id')
                    && Schema::hasColumn('pay_loan_requests', 'status')
                    && ! $this->indexExists('pay_loan_requests', 'plr_user_status_idx')
                ) {
                    $table->index(['user_id', 'status'], 'plr_user_status_idx');
                }
                if (
                    Schema::hasColumn('pay_loan_requests', 'deduction_schedule')
                    && Schema::hasColumn('pay_loan_requests', 'status')
                    && ! $this->indexExists('pay_loan_requests', 'plr_dedsched_status_idx')
                ) {
                    $table->index(['deduction_schedule', 'status'], 'plr_dedsched_status_idx');
                }
            });
        }

        if (Schema::hasTable('employee_compensation_components')) {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                if (
                    Schema::hasColumn('employee_compensation_components', 'user_id')
                    && Schema::hasColumn('employee_compensation_components', 'is_active')
                    && Schema::hasColumn('employee_compensation_components', 'effective_from')
                    && ! $this->indexExists('employee_compensation_components', 'ecc_user_active_eff_idx')
                ) {
                    $table->index(['user_id', 'is_active', 'effective_from'], 'ecc_user_active_eff_idx');
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = DB::getDatabaseName();
        $row = DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->first();

        return $row !== null;
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }
};
