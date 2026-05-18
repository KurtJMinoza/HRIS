<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addUsersNameIndex();
        $this->addCompensationComponentLookupIndex();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('users', 'users_last_first_name_idx');
        $this->dropIndexIfExists('employee_compensation_components', 'ecc_user_pay_component_idx');
    }

    private function addUsersNameIndex(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (
                Schema::hasColumn('users', 'last_name')
                && Schema::hasColumn('users', 'first_name')
                && ! $this->indexExists('users', 'users_last_first_name_idx')
            ) {
                $table->index(['last_name', 'first_name'], 'users_last_first_name_idx');
            }
        });
    }

    private function addCompensationComponentLookupIndex(): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        Schema::table('employee_compensation_components', function (Blueprint $table) {
            if (
                Schema::hasColumn('employee_compensation_components', 'user_id')
                && Schema::hasColumn('employee_compensation_components', 'pay_component_id')
                && ! $this->indexExists('employee_compensation_components', 'ecc_user_pay_component_idx')
            ) {
                $table->index(['user_id', 'pay_component_id'], 'ecc_user_pay_component_idx');
            }
        });
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

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }
};
