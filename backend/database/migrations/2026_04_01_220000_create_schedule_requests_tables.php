<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('working_schedule_id')->constrained('working_schedules')->cascadeOnDelete();
            $table->text('remarks')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('approval_stage', 32)->nullable();
            $table->boolean('pending_approval')->default(true);
            $table->timestamp('filed_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('first_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->foreignId('second_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['approval_stage', 'pending_approval']);
        });

        Schema::create('schedule_request_approval_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_request_id')->constrained('schedule_requests')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->text('details')->nullable();
            $table->string('approver_role', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_request_approval_audits');
        Schema::dropIfExists('schedule_requests');
    }
};
