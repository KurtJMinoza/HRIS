<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            $this->addIndexIfMissing('users', 'users_role_idx', ['role']);
            $this->addIndexIfMissing('users', 'users_company_id_idx', ['company_id']);
            $this->addIndexIfMissing('users', 'users_branch_id_idx', ['branch_id']);
            $this->addIndexIfMissing('users', 'users_department_id_idx', ['department_id']);

            if (Schema::hasColumn('users', 'hr_role')) {
                $this->addIndexIfMissing('users', 'users_hr_role_idx', ['hr_role']);
                $this->addIndexIfMissing('users', 'users_profile_scope_idx', ['role', 'hr_role', 'company_id', 'branch_id', 'department_id']);
            } else {
                $this->addIndexIfMissing('users', 'users_profile_scope_idx', ['role', 'company_id', 'branch_id', 'department_id']);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $this->dropIndexIfExists('users', 'users_profile_scope_idx');
                $this->dropIndexIfExists('users', 'users_department_id_idx');
                $this->dropIndexIfExists('users', 'users_branch_id_idx');
                $this->dropIndexIfExists('users', 'users_company_id_idx');
                $this->dropIndexIfExists('users', 'users_hr_role_idx');
                $this->dropIndexIfExists('users', 'users_role_idx');
            });
        }
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! $this->indexExists($table, $indexName)) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $indexName));
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $db = DB::getDatabaseName();
        if (! $db) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
