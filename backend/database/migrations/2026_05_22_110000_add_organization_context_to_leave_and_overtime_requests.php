<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['leave_requests', 'overtimes'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'assignment_id')) {
                    $table->unsignedBigInteger('assignment_id')->nullable()->after('user_id');
                }
                if (! Schema::hasColumn($tableName, 'assignment_type')) {
                    $table->string('assignment_type', 20)->nullable()->after('assignment_id');
                }
                if (! Schema::hasColumn($tableName, 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('assignment_type');
                }
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn($tableName, 'division_id')) {
                    $table->unsignedBigInteger('division_id')->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn($tableName, 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('division_id');
                }
                if (! Schema::hasColumn($tableName, 'section_unit_id')) {
                    $table->unsignedBigInteger('section_unit_id')->nullable()->after('department_id');
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->index(['assignment_id'], $tableName.'_assignment_id_idx');
                $table->index(['section_unit_id', 'status'], $tableName.'_section_status_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['leave_requests', 'overtimes'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $columns = array_values(array_filter([
                    'assignment_id',
                    'assignment_type',
                    'company_id',
                    'branch_id',
                    'division_id',
                    'department_id',
                    'section_unit_id',
                ], fn (string $column): bool => Schema::hasColumn($tableName, $column)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
