<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pay_cycles')) {
            return;
        }

        Schema::create('pay_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('cut_off_type', 30)->default('fixed_day');
            $table->json('cut_off_value')->nullable();
            $table->string('pay_day_type', 30)->default('offset');
            $table->json('pay_day_value')->nullable();
            $table->unsignedSmallInteger('pay_day_offset')->nullable();
            $table->string('pro_ration_type', 20)->default('none');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_cycles');
    }
};
