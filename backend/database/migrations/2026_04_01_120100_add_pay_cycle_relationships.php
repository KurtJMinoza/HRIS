<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'default_pay_cycle_id')) {
                $table->foreignId('default_pay_cycle_id')->nullable()->after('company_head_id')->constrained('pay_cycles')->nullOnDelete();
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'default_pay_cycle_id')) {
                $table->foreignId('default_pay_cycle_id')->nullable()->after('branch_manager_id')->constrained('pay_cycles')->nullOnDelete();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pay_cycle_id')) {
                $table->foreignId('pay_cycle_id')->nullable()->after('supervisor_id')->constrained('pay_cycles')->nullOnDelete();
            }
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_periods', 'pay_cycle_id')) {
                $table->foreignId('pay_cycle_id')->nullable()->after('user_id')->constrained('pay_cycles')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_periods', 'pay_cycle_code')) {
                $table->string('pay_cycle_code', 50)->nullable()->after('to_date');
            }
            if (! Schema::hasColumn('payroll_periods', 'cycle_label')) {
                $table->string('cycle_label')->nullable()->after('pay_cycle_code');
            }
            if (! Schema::hasColumn('payroll_periods', 'reference_date')) {
                $table->date('reference_date')->nullable()->after('cycle_label');
            }
            if (! Schema::hasColumn('payroll_periods', 'cut_off_start_date')) {
                $table->date('cut_off_start_date')->nullable()->after('reference_date');
            }
            if (! Schema::hasColumn('payroll_periods', 'cut_off_end_date')) {
                $table->date('cut_off_end_date')->nullable()->after('cut_off_start_date');
            }
            if (! Schema::hasColumn('payroll_periods', 'pay_date')) {
                $table->date('pay_date')->nullable()->after('cut_off_end_date');
            }
            if (! Schema::hasColumn('payroll_periods', 'pro_ration_type')) {
                $table->string('pro_ration_type', 20)->nullable()->after('pay_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            foreach ([
                'pay_cycle_id',
                'pay_cycle_code',
                'cycle_label',
                'reference_date',
                'cut_off_start_date',
                'cut_off_end_date',
                'pay_date',
                'pro_ration_type',
            ] as $column) {
                if (Schema::hasColumn('payroll_periods', $column)) {
                    if ($column === 'pay_cycle_id') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'pay_cycle_id')) {
                $table->dropConstrainedForeignId('pay_cycle_id');
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'default_pay_cycle_id')) {
                $table->dropConstrainedForeignId('default_pay_cycle_id');
            }
        });

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'default_pay_cycle_id')) {
                $table->dropConstrainedForeignId('default_pay_cycle_id');
            }
        });
    }
};
