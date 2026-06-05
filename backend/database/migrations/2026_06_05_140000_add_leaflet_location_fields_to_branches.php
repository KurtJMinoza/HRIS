<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'branch_latitude')) {
                $table->decimal('branch_latitude', 10, 7)->nullable()->after('address');
            }
            if (! Schema::hasColumn('branches', 'branch_longitude')) {
                $table->decimal('branch_longitude', 10, 7)->nullable()->after('branch_latitude');
            }
            if (! Schema::hasColumn('branches', 'branch_address')) {
                $table->string('branch_address')->nullable()->after('branch_longitude');
            }
            if (! Schema::hasColumn('branches', 'branch_city')) {
                $table->string('branch_city')->nullable()->after('branch_address');
            }
            if (! Schema::hasColumn('branches', 'branch_province')) {
                $table->string('branch_province')->nullable()->after('branch_city');
            }
            if (! Schema::hasColumn('branches', 'branch_postal_code')) {
                $table->string('branch_postal_code', 32)->nullable()->after('branch_province');
            }
        });

        $this->addIndexIfMissing('branches', ['branch_latitude', 'branch_longitude'], 'branches_geofence_coords_idx');
    }

    public function down(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        $this->dropIndexIfExists('branches', 'branches_geofence_coords_idx');

        Schema::table('branches', function (Blueprint $table): void {
            foreach ([
                'branch_postal_code',
                'branch_province',
                'branch_city',
                'branch_address',
                'branch_longitude',
                'branch_latitude',
            ] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
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
