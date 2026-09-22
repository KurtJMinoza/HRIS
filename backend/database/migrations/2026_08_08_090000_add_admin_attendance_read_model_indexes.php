<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex(['department_name', 'date'], 'ads_department_name_date_idx');
        $this->addIndex(['company_name', 'date'], 'ads_company_name_date_idx');
        $this->addIndex(['date', 'employee_name'], 'ads_date_employee_name_idx');
        $this->addIndex(['date', 'premium_type'], 'ads_date_premium_type_idx');
    }

    public function down(): void
    {
        foreach ([
            'ads_date_premium_type_idx',
            'ads_date_employee_name_idx',
            'ads_company_name_date_idx',
            'ads_department_name_date_idx',
        ] as $indexName) {
            if (Schema::hasTable('attendance_daily_summaries') && $this->indexExists($indexName)) {
                Schema::table('attendance_daily_summaries', function (Blueprint $table) use ($indexName): void {
                    $table->dropIndex($indexName);
                });
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(array $columns, string $indexName): void
    {
        if (
            ! Schema::hasTable('attendance_daily_summaries')
            || $this->indexExists($indexName)
            || ! $this->hasIndexCapacity()
            || collect($columns)->contains(fn (string $column): bool => ! Schema::hasColumn('attendance_daily_summaries', $column))
        ) {
            return;
        }

        Schema::table('attendance_daily_summaries', function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function hasIndexCapacity(): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return true;
        }

        $rows = $connection->select(
            'SELECT COUNT(DISTINCT index_name) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ?',
            [$connection->getDatabaseName(), 'attendance_daily_summaries'],
        );

        return ! isset($rows[0]) || (int) ($rows[0]->c ?? 0) < 64;
    }

    private function indexExists(string $indexName): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            return count($connection->select(
                'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
                ['index', $indexName],
            )) > 0;
        }

        $rows = $connection->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$connection->getDatabaseName(), 'attendance_daily_summaries', $indexName],
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
