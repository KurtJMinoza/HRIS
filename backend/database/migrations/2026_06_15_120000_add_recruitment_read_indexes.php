<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{table: string, columns: list<string>, name: string}> */
    private const INDEXES = [
        ['table' => 'recruitment_applicants', 'columns' => ['created_at'], 'name' => 'recruitment_applicants_created_at_index'],
        ['table' => 'recruitment_applicants', 'columns' => ['applied_position'], 'name' => 'recruitment_applicants_applied_position_index'],
        ['table' => 'recruitment_applicants', 'columns' => ['department_id'], 'name' => 'recruitment_applicants_department_id_index'],
        ['table' => 'recruitment_interviews', 'columns' => ['applicant_id', 'interview_type'], 'name' => 'recruitment_interviews_applicant_id_interview_type_index'],
        ['table' => 'recruitment_interviews', 'columns' => ['interview_date'], 'name' => 'recruitment_interviews_interview_date_index'],
        ['table' => 'recruitment_exam_assignments', 'columns' => ['applicant_id', 'status'], 'name' => 'recruitment_exam_assignments_applicant_id_status_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            $this->addIndexIfMissing($index['table'], $index['columns'], $index['name']);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::INDEXES) as $index) {
            if (! $this->hasIndex($index['table'], $index['name'])) {
                continue;
            }

            Schema::table($index['table'], function (Blueprint $table) use ($index): void {
                $table->dropIndex($index['name']);
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if ($this->hasIndex($table, $indexName) || $this->hasIndexOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName): void {
            $blueprint->index($columns, $indexName);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($index) => ($index->name ?? '') === $indexName);
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $indexName],
        );

        return count($rows) > 0;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                $indexName = (string) ($index->name ?? '');
                if ($indexName === '') {
                    continue;
                }

                $info = $connection->select("PRAGMA index_info('{$indexName}')");
                $indexedColumns = collect($info)->pluck('name')->filter()->values()->all();
                if ($this->columnsMatchPrefix($indexedColumns, $columns)) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table],
        );

        foreach (collect($rows)->groupBy('INDEX_NAME') as $indexColumns) {
            $indexed = $indexColumns->pluck('COLUMN_NAME')->filter()->values()->all();
            if ($this->columnsMatchPrefix($indexed, $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $indexedColumns
     * @param  list<string>  $columns
     */
    private function columnsMatchPrefix(array $indexedColumns, array $columns): bool
    {
        if ($indexedColumns === $columns) {
            return true;
        }

        return array_slice($indexedColumns, 0, count($columns)) === $columns;
    }
};
