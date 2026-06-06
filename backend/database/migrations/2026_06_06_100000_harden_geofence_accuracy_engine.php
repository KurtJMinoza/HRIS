<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                if (! Schema::hasColumn('branches', 'geofence_mobile_accuracy_threshold_meters')) {
                    $table->unsignedInteger('geofence_mobile_accuracy_threshold_meters')->default(50)->after('geofence_default_accuracy_threshold_meters');
                }
                if (! Schema::hasColumn('branches', 'geofence_desktop_accuracy_threshold_meters')) {
                    $table->unsignedInteger('geofence_desktop_accuracy_threshold_meters')->default(100)->after('geofence_mobile_accuracy_threshold_meters');
                }
                if (! Schema::hasColumn('branches', 'geofence_accuracy_buffer_mode')) {
                    $table->string('geofence_accuracy_buffer_mode', 16)->default('strict')->after('geofence_accuracy_policy');
                }
                if (! Schema::hasColumn('branches', 'geofence_minimum_samples')) {
                    $table->unsignedTinyInteger('geofence_minimum_samples')->default(3)->after('geofence_accuracy_buffer_mode');
                }
                if (! Schema::hasColumn('branches', 'geofence_maximum_samples')) {
                    $table->unsignedTinyInteger('geofence_maximum_samples')->default(5)->after('geofence_minimum_samples');
                }
                if (! Schema::hasColumn('branches', 'geofence_sample_timeout_seconds')) {
                    $table->unsignedTinyInteger('geofence_sample_timeout_seconds')->default(15)->after('geofence_maximum_samples');
                }
                if (! Schema::hasColumn('branches', 'geofence_require_backend_validation')) {
                    $table->boolean('geofence_require_backend_validation')->default(true)->after('geofence_allow_cross_branch');
                }
            });
        }

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('geofence_validation_logs', 'radius_meters')) {
                    $table->unsignedInteger('radius_meters')->nullable()->after('distance_meters');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'geofence_type')) {
                    $table->string('geofence_type', 16)->nullable()->after('radius_meters');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'sampled_readings_count')) {
                    $table->unsignedTinyInteger('sampled_readings_count')->nullable()->after('method');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'selected_best_accuracy')) {
                    $table->decimal('selected_best_accuracy', 8, 2)->nullable()->after('sampled_readings_count');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'accuracy_threshold_meters')) {
                    $table->unsignedInteger('accuracy_threshold_meters')->nullable()->after('selected_best_accuracy');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->after('accuracy_threshold_meters');
                }
            });

            $this->addIndexIfMissing('geofence_validation_logs', ['expires_at'], 'gfc_logs_expires_at_idx');
            $this->addIndexIfMissing('geofence_validation_logs', ['attendance_log_id', 'expires_at'], 'gfc_logs_reuse_expiry_idx');
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('geofence_validation_logs', 'gfc_logs_reuse_expiry_idx');
        $this->dropIndexIfExists('geofence_validation_logs', 'gfc_logs_expires_at_idx');

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                foreach ([
                    'expires_at',
                    'accuracy_threshold_meters',
                    'selected_best_accuracy',
                    'sampled_readings_count',
                    'geofence_type',
                    'radius_meters',
                ] as $column) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table): void {
                foreach ([
                    'geofence_require_backend_validation',
                    'geofence_sample_timeout_seconds',
                    'geofence_maximum_samples',
                    'geofence_minimum_samples',
                    'geofence_accuracy_buffer_mode',
                    'geofence_desktop_accuracy_threshold_meters',
                    'geofence_mobile_accuracy_threshold_meters',
                ] as $column) {
                    if (Schema::hasColumn('branches', $column)) {
                        $table->dropColumn($column);
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
