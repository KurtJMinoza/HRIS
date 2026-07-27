<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_schedule_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('working_schedule_id')->constrained('working_schedules')->cascadeOnDelete();
            $table->string('day_of_week', 3);
            $table->boolean('is_working_day')->default(true);
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->unsignedSmallInteger('grace_period_minutes')->nullable();
            $table->unsignedSmallInteger('half_day_threshold_minutes')->nullable();
            $table->boolean('crosses_midnight')->default(false);
            $table->timestamps();

            $table->unique(['working_schedule_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_schedule_days');
    }
};
