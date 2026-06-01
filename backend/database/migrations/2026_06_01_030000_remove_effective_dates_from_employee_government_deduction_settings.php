<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_government_deduction_settings')) {
            if ($this->indexExists('employee_government_deduction_settings', 'egd_settings_effective_dates_idx')) {
                Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
                    $table->dropIndex('egd_settings_effective_dates_idx');
                });
            }

            Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
                if (Schema::hasColumn('employee_government_deduction_settings', 'effective_from')) {
                    $table->dropColumn('effective_from');
                }
                if (Schema::hasColumn('employee_government_deduction_settings', 'effective_to')) {
                    $table->dropColumn('effective_to');
                }
            });
        }

        if (Schema::hasTable('employee_government_deduction_setting_audits')) {
            Schema::table('employee_government_deduction_setting_audits', function (Blueprint $table) {
                if (Schema::hasColumn('employee_government_deduction_setting_audits', 'effective_from')) {
                    $table->dropColumn('effective_from');
                }
                if (Schema::hasColumn('employee_government_deduction_setting_audits', 'effective_to')) {
                    $table->dropColumn('effective_to');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_government_deduction_settings')) {
            Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_government_deduction_settings', 'effective_from')) {
                    $table->date('effective_from')->nullable()->after('exemption_reason');
                }
                if (! Schema::hasColumn('employee_government_deduction_settings', 'effective_to')) {
                    $table->date('effective_to')->nullable()->after('effective_from');
                }
                if (! $this->indexExists('employee_government_deduction_settings', 'egd_settings_effective_dates_idx')) {
                    $table->index(['effective_from', 'effective_to'], 'egd_settings_effective_dates_idx');
                }
            });
        }

        if (Schema::hasTable('employee_government_deduction_setting_audits')) {
            Schema::table('employee_government_deduction_setting_audits', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_government_deduction_setting_audits', 'effective_from')) {
                    $table->date('effective_from')->nullable()->after('new_value');
                }
                if (! Schema::hasColumn('employee_government_deduction_setting_audits', 'effective_to')) {
                    $table->date('effective_to')->nullable()->after('effective_from');
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index])) > 0;
            }

            if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
                foreach (Schema::getIndexes($table) as $candidate) {
                    if (($candidate['name'] ?? null) === $index) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
