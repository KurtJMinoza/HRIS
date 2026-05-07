<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        if ($this->indexExists('holidays', 'holidays_date_unique')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->dropUnique('holidays_date_unique');
            });
        }

        Schema::table('holidays', function (Blueprint $table) {
            if (! Schema::hasColumn('holidays', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('scope');
            }
            if (! Schema::hasColumn('holidays', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            }
            if (! Schema::hasColumn('holidays', 'department_id')) {
                $table->unsignedBigInteger('department_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('holidays', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('department_id');
            }
        });

        if (! $this->indexExists('holidays', 'holidays_date_scope_targets_idx')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->index(
                    ['date', 'scope', 'company_id', 'branch_id', 'department_id', 'employee_id', 'status'],
                    'holidays_date_scope_targets_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('holidays')) {
            return;
        }

        if ($this->indexExists('holidays', 'holidays_date_scope_targets_idx')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->dropIndex('holidays_date_scope_targets_idx');
            });
        }

        Schema::table('holidays', function (Blueprint $table) {
            foreach (['employee_id', 'department_id', 'branch_id', 'company_id'] as $column) {
                if (Schema::hasColumn('holidays', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (! $this->indexExists('holidays', 'holidays_date_unique')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->unique(['date'], 'holidays_date_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                $rows = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index]);

                return count($rows) > 0;
            }

            if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
                foreach (Schema::getIndexes($table) as $candidate) {
                    if (($candidate['name'] ?? null) === $index) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
