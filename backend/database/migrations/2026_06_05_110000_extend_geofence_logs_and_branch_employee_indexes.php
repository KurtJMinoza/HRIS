<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('geofence_validation_logs', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->after('employee_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'company_id')) {
                    $table->foreignId('company_id')->nullable()->after('user_id')->constrained('companies')->nullOnDelete();
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'attempted_branch_id')) {
                    $table->foreignId('attempted_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
                }
            });
        }

        $this->addIndexIfMissing('employee_organization_assignments', ['employee_id'], 'gfc_eoa_employee_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['branch_id'], 'gfc_eoa_branch_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['company_id'], 'gfc_eoa_company_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['effective_from'], 'gfc_eoa_effective_from_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['effective_to'], 'gfc_eoa_effective_to_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['is_active'], 'gfc_eoa_active_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['branch_id', 'is_active', 'effective_from', 'effective_to'], 'gfc_eoa_branch_active_window_idx');

        $this->addIndexIfMissing('users', ['branch_id'], 'gfc_users_branch_idx');
        $this->addIndexIfMissing('users', ['company_id'], 'gfc_users_company_idx');
        $this->addIndexIfMissing('users', ['is_active', 'employment_status'], 'gfc_users_active_status_idx');

        $this->addIndexIfMissing('geofence_validation_logs', ['employee_id'], 'gfc_logs_employee_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['branch_id'], 'gfc_logs_branch_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['company_id'], 'gfc_logs_company_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['created_at'], 'gfc_logs_created_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['validation_status'], 'gfc_logs_status_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['employee_id', 'branch_id', 'validation_status'], 'gfc_logs_employee_branch_status_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['user_id', 'created_at'], 'gfc_logs_user_created_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['company_id', 'created_at'], 'gfc_logs_company_created_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['attempted_branch_id', 'created_at'], 'gfc_logs_attempted_branch_created_idx');
    }

    public function down(): void
    {
        foreach ([
            ['employee_organization_assignments', 'gfc_eoa_employee_idx'],
            ['employee_organization_assignments', 'gfc_eoa_branch_idx'],
            ['employee_organization_assignments', 'gfc_eoa_company_idx'],
            ['employee_organization_assignments', 'gfc_eoa_effective_from_idx'],
            ['employee_organization_assignments', 'gfc_eoa_effective_to_idx'],
            ['employee_organization_assignments', 'gfc_eoa_active_idx'],
            ['employee_organization_assignments', 'gfc_eoa_branch_active_window_idx'],
            ['users', 'gfc_users_branch_idx'],
            ['users', 'gfc_users_company_idx'],
            ['users', 'gfc_users_active_status_idx'],
            ['geofence_validation_logs', 'gfc_logs_employee_idx'],
            ['geofence_validation_logs', 'gfc_logs_branch_idx'],
            ['geofence_validation_logs', 'gfc_logs_company_idx'],
            ['geofence_validation_logs', 'gfc_logs_created_idx'],
            ['geofence_validation_logs', 'gfc_logs_status_idx'],
            ['geofence_validation_logs', 'gfc_logs_employee_branch_status_idx'],
            ['geofence_validation_logs', 'gfc_logs_user_created_idx'],
            ['geofence_validation_logs', 'gfc_logs_company_created_idx'],
            ['geofence_validation_logs', 'gfc_logs_attempted_branch_created_idx'],
        ] as [$table, $index]) {
            $this->dropIndexIfExists($table, $index);
        }

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                foreach (['attempted_branch_id', 'company_id', 'user_id'] as $column) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $table->dropConstrainedForeignId($column);
                    }
                }
            });
        }
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
