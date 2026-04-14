<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'monthly_salary')) {
                $table->decimal('monthly_salary', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('users', 'hourly_rate')) {
                $table->decimal('hourly_rate', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('users', 'salary_effectivity_date')) {
                $table->date('salary_effectivity_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Do not drop monthly_salary here — it may have existed before this migration.
            $cols = array_filter(
                ['salary_effectivity_date', 'hourly_rate'],
                fn (string $c) => Schema::hasColumn('users', $c)
            );
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
