<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_recognition_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('matched_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('similarity_score', 7, 4)->nullable();
            $table->decimal('second_best_score', 7, 4)->nullable();
            $table->decimal('margin_score', 7, 4)->nullable();
            $table->decimal('liveness_score', 7, 4)->nullable();
            $table->string('decision', 32);
            $table->string('reason', 80)->nullable();
            $table->string('mode', 32)->nullable();
            $table->string('device_id', 80)->nullable();
            $table->string('camera_info')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at'], 'face_attempt_employee_created_idx');
            $table->index(['matched_employee_id', 'created_at'], 'face_attempt_match_created_idx');
            $table->index(['decision', 'reason', 'created_at'], 'face_attempt_decision_reason_idx');
            $table->index(['device_id', 'created_at'], 'face_attempt_device_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_recognition_attempts');
    }
};
