<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_cycles')) {
            return;
        }

        if ($this->indexExists('pay_cycles', 'pay_cycles_code_unique')) {
            Schema::table('pay_cycles', function (Blueprint $table) {
                $table->dropUnique('pay_cycles_code_unique');
            });
        }

        if (! $this->indexExists('pay_cycles', 'pay_cycles_company_id_code_index')) {
            Schema::table('pay_cycles', function (Blueprint $table) {
                $table->index(['company_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_cycles')) {
            return;
        }

        if (! $this->indexExists('pay_cycles', 'pay_cycles_code_unique')) {
            Schema::table('pay_cycles', function (Blueprint $table) {
                $table->unique('code');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();
        $result = DB::select(
            'SELECT COUNT(*) AS aggregate
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }
};
