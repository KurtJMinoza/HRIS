<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('benefit_catalog_id')->constrained('benefit_catalogs')->cascadeOnDelete();
            $table->date('effective_date');
            $table->string('status', 32)->default('active'); // active, inactive, suspended
            $table->json('metadata')->nullable(); // override amount, frequency, notes
            $table->timestamps();

            $table->unique(['user_id', 'benefit_catalog_id'], 'employee_benefits_user_catalog_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_benefits');
    }
};
