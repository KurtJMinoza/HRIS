<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_tax_info')) {
            return;
        }

        Schema::create('employee_tax_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('withholding_method', 32)->default('annualized');
            $table->string('period_type', 24)->default('monthly');
            $table->string('tax_table_version', 32)->default('train_2018');
            $table->unsignedTinyInteger('dependents')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_info');
    }
};
