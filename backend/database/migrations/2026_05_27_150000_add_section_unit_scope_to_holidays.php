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

        if ($this->indexExists('holidays', 'holidays_date_scope_targets_idx')) {
            Schema::table('holidays', fn (Blueprint $table) => $table->dropIndex('holidays_date_scope_targets_idx'));
        }

        Schema::table('holidays', function (Blueprint $table) {
            if (! Schema::hasColumn('holidays', 'division_id')) {
                $table->unsignedBigInteger('division_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('holidays', 'section_unit_id')) {
                $table->unsignedBigInteger('section_unit_id')->nullable()->after('department_id');
            }
        });

        if (! $this->indexExists('holidays', 'holidays_date_scope_targets_idx')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->index(
                    ['date', 'scope', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id', 'employee_id', 'status'],
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
            Schema::table('holidays', fn (Blueprint $table) => $table->dropIndex('holidays_date_scope_targets_idx'));
        }

        Schema::table('holidays', function (Blueprint $table) {
            foreach (['section_unit_id', 'division_id'] as $column) {
                if (Schema::hasColumn('holidays', $column)) {
                    $table->dropColumn($column);
                }
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

    private function indexExists(string $table, string $index): bool
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index])) > 0;
            }
            foreach (Schema::getIndexes($table) as $candidate) {
                if (($candidate['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
