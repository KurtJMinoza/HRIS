<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_approval_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_id')->constrained('overtimes')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->text('details')->nullable();
            $table->string('approver_role', 128)->nullable();
            $table->timestamps();
        });

        Schema::create('leave_approval_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
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
        Schema::dropIfExists('leave_approval_audits');
        Schema::dropIfExists('overtime_approval_audits');
    }
};
