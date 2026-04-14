<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_compensation_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pay_component_id')->nullable()->constrained('pay_components')->nullOnDelete();
            $table->string('structure_name')->nullable();
            $table->string('name');
            $table->string('code');
            $table->string('type', 32);
            $table->string('category', 64)->nullable();
            $table->string('calculation_type', 32);
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->decimal('hours', 10, 2)->nullable();
            $table->text('formula')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('contributes_sss')->default(false);
            $table->boolean('contributes_philhealth')->default(false);
            $table->boolean('contributes_pagibig')->default(false);
            $table->boolean('is_proratable')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'effective_from', 'effective_to'], 'employee_comp_components_effective_idx');
            $table->index(['type', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensation_components');
    }
};
