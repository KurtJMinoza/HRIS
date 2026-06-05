<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_geofences')) {
            return;
        }

        Schema::table('branch_geofences', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_geofences', 'enforcement_mode')) {
                $table->string('enforcement_mode', 16)->default('enforce')->after('is_active');
            }
        });

        $this->addIndexIfMissing('branch_geofences', ['branch_id', 'is_active', 'enforcement_mode', 'priority'], 'branch_geofences_validation_idx');
    }

    public function down(): void
    {
        if (! Schema::hasTable('branch_geofences')) {
            return;
        }

        $this->dropIndexIfExists('branch_geofences', 'branch_geofences_validation_idx');

        Schema::table('branch_geofences', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_geofences', 'enforcement_mode')) {
                $table->dropColumn('enforcement_mode');
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexNameExists($tableName, $indexName) || $this->indexColumnsExist($tableName, $columns)) {
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
        if (! Schema::hasTable($tableName) || ! $this->indexNameExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    private function indexNameExists(string $tableName, string $indexName): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?', ['index', $tableName, $indexName]);

            return count($rows) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexColumnsExist(string $tableName, array $columns): bool
    {
        $conn = Schema::getConnection();
        $wanted = implode(',', $columns);

        if ($conn->getDriverName() === 'sqlite') {
            $indexes = $conn->select('PRAGMA index_list('.$tableName.')');
            foreach ($indexes as $idx) {
                $name = (string) ($idx->name ?? '');
                if ($name === '') {
                    continue;
                }
                $info = $conn->select('PRAGMA index_info('.$name.')');
                $existing = implode(',', array_map(static fn ($row): string => (string) ($row->name ?? ''), $info));
                if ($existing === $wanted) {
                    return true;
                }
            }

            return false;
        }

        $rows = $conn->select(
            'SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ",") AS cols
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name <> "PRIMARY"
             GROUP BY index_name
             HAVING cols = ?
             LIMIT 1',
            [$conn->getDatabaseName(), $tableName, $wanted]
        );

        return count($rows) > 0;
    }
};
