<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('execom_employee_profiles')) {
            Schema::create('execom_employee_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('fixed_salary', 14, 2)->default(0);
                $table->string('pay_schedule', 50)->default('per_period');
                $table->boolean('is_active')->default(true);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['employee_id', 'is_active'], 'execom_profiles_employee_active_idx');
                $table->index(['company_id', 'is_active', 'effective_from', 'effective_to'], 'execom_profiles_company_active_dates_idx');
                $table->index(['branch_id', 'department_id'], 'execom_profiles_org_idx');
            });
        }

        if (! Schema::hasTable('execom_payroll_settings')) {
            Schema::create('execom_payroll_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('apply_government_deductions')->default(true);
                $table->boolean('apply_custom_deductions')->default(true);
                $table->boolean('apply_allowances')->default(true);
                $table->boolean('allow_overtime')->default(false);
                $table->boolean('allow_holiday_pay')->default(false);
                $table->boolean('auto_present_attendance_reports')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique('company_id', 'execom_payroll_settings_company_unique');
            });
        }

        $this->addPayrollModuleColumn('payroll_batch_runs', withIndex: true);
        $this->addPayrollModuleColumn('payslips', withIndex: true);
        $this->addPayrollModuleColumn('payroll_periods', withIndex: false);
        $this->addPayslipModuleUnique();
    }

    public function down(): void
    {
        $this->dropPayslipModuleUnique();
        $this->dropPayrollModuleColumn('payroll_periods');
        $this->dropPayrollModuleColumn('payslips');
        $this->dropPayrollModuleColumn('payroll_batch_runs');

        Schema::dropIfExists('execom_payroll_settings');
        Schema::dropIfExists('execom_employee_profiles');
    }

    private function addPayrollModuleColumn(string $tableName, bool $withIndex): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'payroll_module')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $withIndex): void {
            $table->string('payroll_module', 30)->default('standard')->after('id');
            if ($withIndex) {
                $table->index(['payroll_module'], "{$tableName}_payroll_module_idx");
            }
        });
    }

    private function dropPayrollModuleColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'payroll_module')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            try {
                $table->dropIndex("{$tableName}_payroll_module_idx");
            } catch (Throwable) {
                // Some environments may not have the optional index due to guarded creates.
            }
            $table->dropColumn('payroll_module');
        });
    }

    private function addPayslipModuleUnique(): void
    {
        if (! Schema::hasTable('payslips') || ! Schema::hasColumn('payslips', 'payroll_module')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table): void {
            foreach (['payslips_user_company_period_slot_unique', 'payslips_user_period_slot_unique'] as $index) {
                if ($this->indexExists('payslips', $index)) {
                    $table->dropUnique($index);
                }
            }
            if (! $this->indexExists('payslips', 'payslips_user_company_module_period_slot_unique')) {
                $table->unique(
                    ['user_id', 'company_id', 'payroll_module', 'pay_period_start', 'pay_period_end', 'period_slot'],
                    'payslips_user_company_module_period_slot_unique'
                );
            }
        });
    }

    private function dropPayslipModuleUnique(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table): void {
            if ($this->indexExists('payslips', 'payslips_user_company_module_period_slot_unique')) {
                $table->dropUnique('payslips_user_company_module_period_slot_unique');
            }
            if (! $this->indexExists('payslips', 'payslips_user_company_period_slot_unique')
                && Schema::hasColumn('payslips', 'company_id')
                && Schema::hasColumn('payslips', 'period_slot')) {
                $table->unique(
                    ['user_id', 'company_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                    'payslips_user_company_period_slot_unique'
                );
            }
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (! Schema::hasTable($tableName)) {
            return false;
        }

        $rows = DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
