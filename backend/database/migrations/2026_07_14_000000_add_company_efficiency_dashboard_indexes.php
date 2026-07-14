<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('attendance_daily_summaries', ['date', 'employee_id'], 'ads_date_employee_idx');
        $this->addIndex('attendance_daily_summaries', ['status', 'date'], 'ads_status_date_idx');
        $this->addIndex('users', ['company_id', 'id'], 'users_company_id_id_idx');
        $this->addIndex('users', ['company_id', 'department_id'], 'users_company_department_idx');
        $this->addIndex('attendance_logs', ['user_id', 'verified_at'], 'attendance_logs_user_verified_eff_idx');
    }

    public function down(): void
    {
        $this->dropIndex('attendance_logs', 'attendance_logs_user_verified_eff_idx');
        $this->dropIndex('users', 'users_company_department_idx');
        $this->dropIndex('users', 'users_company_id_id_idx');
        $this->dropIndex('attendance_daily_summaries', 'ads_status_date_idx');
        $this->dropIndex('attendance_daily_summaries', 'ads_date_employee_idx');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (
            ! Schema::hasTable($tableName)
            || $this->indexExists($tableName, $indexName)
            || ! $this->hasIndexCapacity($tableName)
        ) {
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

    private function hasIndexCapacity(string $tableName): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() !== 'mysql') {
            return true;
        }

        $rows = $conn->select(
            'SELECT COUNT(DISTINCT index_name) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ?',
            [$conn->getDatabaseName(), $tableName],
        );

        // MySQL caps InnoDB tables at 64 secondary/primary indexes; skip optional dashboard indexes at the limit.
        return ! isset($rows[0]) || (int) ($rows[0]->c ?? 0) < 64;
    }

    private function dropIndex(string $tableName, string $indexName): void
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
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            return count($conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $indexName])) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName],
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
