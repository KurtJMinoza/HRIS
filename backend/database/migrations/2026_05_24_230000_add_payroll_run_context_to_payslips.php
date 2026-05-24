<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            if (! Schema::hasColumn('payslips', 'payroll_batch_run_id')) {
                $table->foreignId('payroll_batch_run_id')
                    ->nullable()
                    ->after('payroll_period_id')
                    ->constrained('payroll_batch_runs')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('payslips', 'division_id')) {
                $table->unsignedBigInteger('division_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('payslips', 'section_unit_id')) {
                $table->unsignedBigInteger('section_unit_id')->nullable()->after('department_id');
            }
            if (! Schema::hasColumn('payslips', 'assignment_id')) {
                $table->unsignedBigInteger('assignment_id')->nullable()->after('section_unit_id');
            }
            if (! Schema::hasColumn('payslips', 'assignment_type')) {
                $table->string('assignment_type', 20)->nullable()->after('assignment_id');
            }
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique('payslips_user_period_slot_unique');
            $table->unique(
                ['user_id', 'company_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                'payslips_user_company_period_slot_unique'
            );
            $table->index(['payroll_batch_run_id', 'company_id'], 'payslips_run_company_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropIndex('payslips_run_company_idx');
            $table->dropUnique('payslips_user_company_period_slot_unique');
            $table->unique(
                ['user_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                'payslips_user_period_slot_unique'
            );
        });

        Schema::table('payslips', function (Blueprint $table) {
            if (Schema::hasColumn('payslips', 'payroll_batch_run_id')) {
                $table->dropForeign(['payroll_batch_run_id']);
            }
            $table->dropColumn([
                'payroll_batch_run_id',
                'division_id',
                'section_unit_id',
                'assignment_id',
                'assignment_type',
            ]);
        });
    }
};
