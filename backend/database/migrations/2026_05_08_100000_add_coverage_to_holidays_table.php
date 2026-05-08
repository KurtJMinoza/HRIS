<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        Schema::table('holidays', function (Blueprint $table) {
            if (! Schema::hasColumn('holidays', 'coverage_type')) {
                $table->string('coverage_type', 30)->nullable()->after('employee_id');
            }
            if (! Schema::hasColumn('holidays', 'coverage_ids')) {
                $table->json('coverage_ids')->nullable()->after('coverage_type');
            }
            if (! Schema::hasColumn('holidays', 'is_swap')) {
                $table->boolean('is_swap')->default(false)->after('coverage_ids');
            }
            if (! Schema::hasColumn('holidays', 'original_date')) {
                $table->date('original_date')->nullable()->after('is_swap');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        Schema::table('holidays', function (Blueprint $table) {
            foreach (['original_date', 'is_swap', 'coverage_ids', 'coverage_type'] as $column) {
                if (Schema::hasColumn('holidays', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
