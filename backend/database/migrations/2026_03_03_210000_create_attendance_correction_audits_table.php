<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_correction_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_correction_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->dateTime('previous_time_in')->nullable();
            $table->dateTime('previous_time_out')->nullable();
            $table->dateTime('new_time_in')->nullable();
            $table->dateTime('new_time_out')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_audits');
    }
};
