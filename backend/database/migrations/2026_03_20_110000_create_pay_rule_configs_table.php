<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_rule_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); // null = global fallback
            $table->integer('grace_period_minutes')->default(5);
            $table->decimal('ot_multiplier_ordinary', 6, 2)->default(1.25);
            $table->decimal('rest_day_premium', 6, 2)->default(1.30);
            $table->decimal('special_holiday_premium', 6, 2)->default(1.30);
            $table->decimal('regular_holiday_premium', 6, 2)->default(2.00);
            $table->decimal('rest_on_special', 6, 2)->default(1.50);
            $table->decimal('rest_on_regular', 6, 2)->default(2.60);
            $table->decimal('nd_percentage', 5, 2)->default(0.10);
            $table->string('night_start', 5)->default('22:00');
            $table->string('night_end', 5)->default('06:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_rule_configs');
    }
};
