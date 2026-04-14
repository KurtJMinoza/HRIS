<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type', 32);
            $table->string('category', 64)->nullable();
            $table->string('calculation_type', 32);
            $table->decimal('default_value', 12, 2)->default(0);
            $table->text('formula')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('contributes_sss')->default(false);
            $table->boolean('contributes_philhealth')->default(false);
            $table->boolean('contributes_pagibig')->default(false);
            $table->boolean('is_proratable')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_components');
    }
};
