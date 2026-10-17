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
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName])) > 0;
            }

            foreach (Schema::getIndexes($table) as $candidate) {
                if (($candidate['name'] ?? null) === $indexName) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
