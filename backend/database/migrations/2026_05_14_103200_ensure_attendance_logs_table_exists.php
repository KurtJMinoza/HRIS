<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('verified_at')->nullable();
            $table->float('overtime_hours')->nullable();
            $table->float('night_hours')->nullable();
            $table->string('premium_type', 50)->nullable();
            $table->json('calculated_pay_factor')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('similarity_score', 6, 4)->nullable();
            $table->decimal('liveness_score', 6, 4)->nullable();
            $table->string('authentication_method', 50)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'verified_at'], 'attendance_logs_user_id_verified_at_index');
            $table->index(['verified_at', 'user_id'], 'al_verified_at_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
