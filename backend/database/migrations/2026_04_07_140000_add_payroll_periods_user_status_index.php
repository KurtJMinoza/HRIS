<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        if (! $this->indexExists('payroll_periods', 'payroll_periods_user_status_idx')) {
            Schema::table('payroll_periods', function (Blueprint $table) {
                if (Schema::hasColumn('payroll_periods', 'user_id') && Schema::hasColumn('payroll_periods', 'status')) {
                    $table->index(['user_id', 'status'], 'payroll_periods_user_status_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        if ($this->indexExists('payroll_periods', 'payroll_periods_user_status_idx')) {
            Schema::table('payroll_periods', function (Blueprint $table) {
                $table->dropIndex('payroll_periods_user_status_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = DB::getDatabaseName();
        $row = DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->first();

        return $row !== null;
    }
};
