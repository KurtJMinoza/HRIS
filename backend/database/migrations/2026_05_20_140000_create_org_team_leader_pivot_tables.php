<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_team_leaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('section_unit_team_leaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_unit_id')->constrained('sections_or_units')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['section_unit_id', 'employee_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_unit_team_leaders');
        Schema::dropIfExists('department_team_leaders');
    }
};
