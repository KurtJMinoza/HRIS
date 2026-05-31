<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('users', ['email'], 'idx_users_email_login');
        $this->addIndexIfMissing('users', ['username'], 'idx_users_username_login');
        $this->addIndexIfMissing('users', ['employee_id'], 'idx_users_employee_id_login');
        $this->addIndexIfMissing('users', ['employee_code'], 'idx_users_employee_code');
        $this->addIndexIfMissing('users', ['is_active', 'employment_status'], 'idx_users_active_status');

        $this->addIndexIfMissing('personal_access_tokens', ['tokenable_id', 'tokenable_type'], 'idx_tokens_tokenable');
        $this->addIndexIfMissing('personal_access_tokens', ['last_used_at'], 'idx_tokens_last_used');
    }

    public function down(): void
    {
        foreach ([
            ['users', 'idx_users_email_login'],
            ['users', 'idx_users_username_login'],
            ['users', 'idx_users_employee_id_login'],
            ['users', 'idx_users_employee_code'],
            ['users', 'idx_users_active_status'],
            ['personal_access_tokens', 'idx_tokens_tokenable'],
            ['personal_access_tokens', 'idx_tokens_last_used'],
        ] as [$table, $index]) {
            $this->dropIndexIfExists($table, $index);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexExists($tableName, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $conn = DB::connection();
        if ($conn->getDriverName() === 'sqlite') {
            return count($conn->select('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?', ['index', $tableName, $indexName])) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
