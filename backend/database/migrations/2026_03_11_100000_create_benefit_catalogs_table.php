<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('type', 64); // health_insurance, retirement_plan, leave_benefits, allowance, other
            $table->string('name');
            $table->json('metadata')->nullable(); // plan details, coverage, amount, frequency, etc.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['department_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_catalogs');
    }
};
