<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 50)->nullable(); // worked, absent, leave, rest_or_unscheduled
            $table->boolean('is_rest_day')->default(false);
            $table->string('holiday_type', 30)->nullable(); // regular, special, double, company
            $table->string('holiday_name', 255)->nullable();
            $table->integer('required_minutes')->default(0);
            $table->integer('worked_minutes')->default(0);
            $table->integer('regular_day_minutes')->default(0);
            $table->integer('regular_night_minutes')->default(0);
            $table->integer('ot_day_minutes')->default(0);
            $table->integer('ot_night_minutes')->default(0);
            $table->integer('late_deduction_minutes')->default(0);
            $table->integer('undertime_deduction_minutes')->default(0);
            $table->json('conditions')->nullable(); // audit: multiplier values used
            $table->json('breakdown')->nullable();   // audit: per-component amounts
            $table->decimal('total_pay', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_period_id', 'date']);
            $table->index('payroll_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_breakdowns');
    }
};
