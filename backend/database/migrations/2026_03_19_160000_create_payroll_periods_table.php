<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('daily_rate', 14, 2)->default(0);
            $table->decimal('total_pay', 14, 2)->default(0);
            $table->integer('total_worked_minutes')->default(0);
            $table->string('status', 30)->default('draft'); // draft, computed, locked
            $table->timestamps();

            $table->index(['user_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
