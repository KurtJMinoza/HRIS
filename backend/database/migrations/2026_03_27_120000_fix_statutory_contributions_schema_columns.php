<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statutory_contributions')) {
            return;
        }

        Schema::table('statutory_contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('statutory_contributions', 'min_salary')) {
                $table->decimal('min_salary', 14, 2)->nullable()->after('employee_rate');
            }
            if (! Schema::hasColumn('statutory_contributions', 'max_salary')) {
                $table->decimal('max_salary', 14, 2)->nullable()->after('min_salary');
            }
            if (! Schema::hasColumn('statutory_contributions', 'msc')) {
                $table->decimal('msc', 14, 2)->nullable()->after('max_salary');
            }
            if (! Schema::hasColumn('statutory_contributions', 'salary_floor')) {
                $table->decimal('salary_floor', 14, 2)->nullable()->after('msc');
            }
            if (! Schema::hasColumn('statutory_contributions', 'salary_ceiling')) {
                $table->decimal('salary_ceiling', 14, 2)->nullable()->after('salary_floor');
            }
            if (! Schema::hasColumn('statutory_contributions', 'tier_threshold')) {
                $table->decimal('tier_threshold', 14, 2)->nullable()->after('salary_ceiling');
            }
            if (! Schema::hasColumn('statutory_contributions', 'monthly_cap')) {
                $table->decimal('monthly_cap', 14, 2)->nullable()->after('tier_threshold');
            }
            if (! Schema::hasColumn('statutory_contributions', 'brackets')) {
                $table->json('brackets')->nullable()->after('monthly_cap');
            }
            if (! Schema::hasColumn('statutory_contributions', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('company_id');
            }
        });

        if (! Schema::hasTable('sss_brackets')) {
            Schema::create('sss_brackets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('statutory_contribution_id')->nullable()->constrained('statutory_contributions')->nullOnDelete();
                $table->decimal('salary_min', 14, 2);
                $table->decimal('salary_max', 14, 2);
                $table->decimal('msc', 14, 2);
                $table->date('effective_from');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['effective_from', 'is_active']);
                $table->index(['salary_min', 'salary_max']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sss_brackets');

        if (! Schema::hasTable('statutory_contributions')) {
            return;
        }

        Schema::table('statutory_contributions', function (Blueprint $table) {
            foreach (['min_salary', 'max_salary', 'msc', 'salary_floor', 'salary_ceiling', 'tier_threshold', 'monthly_cap', 'brackets', 'is_active'] as $column) {
                if (Schema::hasColumn('statutory_contributions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
