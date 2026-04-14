<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_employee_deductions', 'is_court_ordered_garnishment')) {
                $table->boolean('is_court_ordered_garnishment')->default(false)->after('is_amortized');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'is_legally_allowed')) {
                $table->boolean('is_legally_allowed')->default(true)->after('is_court_ordered_garnishment');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'priority_override')) {
                $table->unsignedInteger('priority_override')->nullable()->after('is_legally_allowed');
            }
        });

        Schema::create('deduction_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_deduction_id')->constrained('pay_employee_deductions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('remaining_balance_after', 14, 2)->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['employee_deduction_id', 'created_at'], 'dal_deduction_created_idx');
            $table->index(['user_id', 'created_at'], 'dal_user_created_idx');
            $table->index(['action', 'created_at'], 'dal_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_audit_logs');

        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            foreach (['priority_override', 'is_legally_allowed', 'is_court_ordered_garnishment'] as $col) {
                if (Schema::hasColumn('pay_employee_deductions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
