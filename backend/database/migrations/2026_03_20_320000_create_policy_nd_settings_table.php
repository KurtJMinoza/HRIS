<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_nd_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->string('start_time', 5)->default('22:00'); // HH:MM 24h
            $table->string('end_time', 5)->default('06:00');
            $table->decimal('nd_addon_multiplier', 5, 2)->default(0.10); // +10%
            $table->boolean('apply_to_regular')->default(true);
            $table->boolean('apply_to_ot')->default(true);
            $table->boolean('apply_to_premium_days')->default(true);
            $table->timestamps();

            $table->unique('policy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_nd_settings');
    }
};
