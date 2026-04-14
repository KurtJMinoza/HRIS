<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_deduction_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 32);
            $table->boolean('is_government')->default(false);
            $table->foreignId('pay_component_id')->nullable()->constrained('pay_components')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('pay_loan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained('pay_deduction_types')->cascadeOnDelete();
            $table->decimal('requested_amount', 14, 2);
            $table->decimal('installment_amount', 12, 2)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('approval_stage', 32)->default('pending_first');
            $table->boolean('pending_approval')->default(true);
            $table->foreignId('first_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->foreignId('second_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('employee_deduction_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'pending_approval']);
            $table->index('user_id');
        });

        Schema::create('pay_employee_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained('pay_deduction_types')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->nullable()->constrained('pay_components')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('remaining_balance', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 32)->default('manual');
            $table->foreignId('loan_request_id')->nullable()->constrained('pay_loan_requests')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->foreign('employee_deduction_id')
                ->references('id')
                ->on('pay_employee_deductions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->dropForeign(['employee_deduction_id']);
        });
        Schema::dropIfExists('pay_employee_deductions');
        Schema::dropIfExists('pay_loan_requests');
        Schema::dropIfExists('pay_deduction_types');
    }
};
