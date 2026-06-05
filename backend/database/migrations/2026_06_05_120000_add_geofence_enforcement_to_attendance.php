<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'geofence_enforcement_mode')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->string('geofence_enforcement_mode', 16)->default('enforce')->after('geofence_enabled');
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('attendance_logs', 'accuracy_meters')) {
                    $table->decimal('accuracy_meters', 8, 2)->nullable()->after('longitude');
                }
                if (! Schema::hasColumn('attendance_logs', 'geofence_validation_id')) {
                    $table->foreignId('geofence_validation_id')->nullable()->after('accuracy_meters')->constrained('geofence_validation_logs')->nullOnDelete();
                }
                if (! Schema::hasColumn('attendance_logs', 'geofence_status')) {
                    $table->string('geofence_status', 32)->nullable()->after('geofence_validation_id');
                }
                if (! Schema::hasColumn('attendance_logs', 'matched_geofence_id')) {
                    $table->foreignId('matched_geofence_id')->nullable()->after('geofence_status')->constrained('branch_geofences')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('geofence_validation_logs', 'clock_type')) {
                    $table->string('clock_type', 16)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'enforcement_mode')) {
                    $table->string('enforcement_mode', 16)->nullable()->after('validation_status');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'distance_meters')) {
                    $table->decimal('distance_meters', 10, 2)->nullable()->after('distance_to_center');
                }
            });
        }

        $this->addIndexIfMissing('attendance_logs', ['geofence_validation_id'], 'gfc_att_logs_validation_idx');
        $this->addIndexIfMissing('attendance_logs', ['geofence_status'], 'gfc_att_logs_status_idx');
        $this->addIndexIfMissing('geofence_validation_logs', ['employee_id', 'clock_type', 'method', 'validation_status', 'created_at'], 'gfc_logs_employee_clock_method_idx');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('geofence_validation_logs', 'gfc_logs_employee_clock_method_idx');
        $this->dropIndexIfExists('attendance_logs', 'gfc_att_logs_status_idx');
        $this->dropIndexIfExists('attendance_logs', 'gfc_att_logs_validation_idx');

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                foreach (['distance_meters', 'enforcement_mode', 'clock_type'] as $column) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                if (Schema::hasColumn('attendance_logs', 'matched_geofence_id')) {
                    $table->dropConstrainedForeignId('matched_geofence_id');
                }
                foreach (['geofence_status', 'geofence_validation_id', 'accuracy_meters'] as $column) {
                    if (Schema::hasColumn('attendance_logs', $column)) {
                        $column === 'geofence_validation_id'
                            ? $table->dropConstrainedForeignId($column)
                            : $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'geofence_enforcement_mode')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropColumn('geofence_enforcement_mode');
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexNameExists($tableName, $indexName)) {
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
};
