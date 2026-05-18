<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'date_of_birth') && ! $this->indexExists('users', 'users_date_of_birth_idx')) {
                $table->index('date_of_birth', 'users_date_of_birth_idx');
            }

            if (
                Schema::hasColumn('users', 'is_active')
                && Schema::hasColumn('users', 'date_of_birth')
                && ! $this->indexExists('users', 'users_active_dob_idx')
            ) {
                $table->index(['is_active', 'date_of_birth'], 'users_active_dob_idx');
            }
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('users', 'users_active_dob_idx');
        $this->dropIndexIfExists('users', 'users_date_of_birth_idx');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }
};
