<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'attendance_corrections',
        'schedule_requests',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'assignment_id')) {
                    $table->foreignId('assignment_id')
                        ->nullable()
                        ->constrained('employee_organization_assignments')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'assignment_type')) {
                    $table->string('assignment_type', 32)->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'company_id')) {
                    $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'division_id')) {
                    $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'department_id')) {
                    $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'section_unit_id')) {
                    $table->foreignId('section_unit_id')->nullable()->constrained('sections_or_units')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (['assignment_id', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'] as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($column): void {
                    try {
                        $table->dropForeign([$column]);
                    } catch (Throwable) {
                        // Existing deployments may already have the column without this FK.
                    }
                });
            }

            $columns = array_values(array_filter([
                'assignment_id',
                'assignment_type',
                'company_id',
                'branch_id',
                'division_id',
                'department_id',
                'section_unit_id',
            ], static fn (string $column): bool => Schema::hasColumn($tableName, $column)));

            if ($columns !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
