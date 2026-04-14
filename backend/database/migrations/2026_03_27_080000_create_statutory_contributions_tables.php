<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statutory_contributions')) {
            Schema::create('statutory_contributions', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('code', 30); // SSS, PhilHealth, PagIBIG, EC
                $table->decimal('employer_rate', 12, 6)->default(0);
                $table->decimal('employee_rate', 12, 6)->default(0);
                $table->decimal('min_salary', 14, 2)->nullable();
                $table->decimal('max_salary', 14, 2)->nullable();
                $table->decimal('msc', 14, 2)->nullable();
                $table->decimal('salary_floor', 14, 2)->nullable();
                $table->decimal('salary_ceiling', 14, 2)->nullable();
                $table->decimal('tier_threshold', 14, 2)->nullable();
                $table->decimal('monthly_cap', 14, 2)->nullable();
                $table->json('brackets')->nullable();
                $table->date('effective_from');
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['code', 'effective_from']);
                $table->index(['company_id', 'code', 'effective_from']);
            });
        }

        if (! Schema::hasTable('employee_statutory_contributions')) {
            Schema::create('employee_statutory_contributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 30); // SSS, PhilHealth, PagIBIG, EC
                $table->unsignedTinyInteger('period_month');
                $table->unsignedSmallInteger('period_year');
                $table->decimal('basic_salary_used', 14, 2)->default(0);
                $table->decimal('msc_used', 14, 2)->nullable();
                $table->string('bracket_range', 255)->nullable();
                $table->decimal('employer_amount', 14, 2)->default(0);
                $table->decimal('employee_amount', 14, 2)->default(0);
                $table->decimal('ec_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->boolean('remitted')->default(false);
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                // Explicit short names to avoid MySQL identifier-length errors.
                $table->index(['employee_id', 'period_year', 'period_month'], 'esc_emp_period_idx');
                $table->index(['type', 'period_year', 'period_month'], 'esc_type_period_idx');
                $table->unique(['employee_id', 'type', 'period_year', 'period_month'], 'esc_employee_type_period_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_statutory_contributions');
        Schema::dropIfExists('statutory_contributions');
    }
};
