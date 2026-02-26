<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('working_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('time_in');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->time('time_out');
            $table->unsignedInteger('grace_period_minutes')->default(0);
            $table->json('rest_days')->nullable(); // e.g. ["sat","sun"]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_schedules');
    }
};

