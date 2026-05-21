<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'division_id')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
                $table->foreignId('division_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
                $table->boolean('hierarchy_mismatch')->default(false)->after('division_id');
                $table->index(['division_id', 'branch_id', 'company_id'], 'departments_org_hierarchy_index');
            });
        }

        DB::table('departments')
            ->whereNull('company_id')
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->each(function ($department): void {
                $companyId = DB::table('branches')->where('id', $department->branch_id)->value('company_id');
                if ($companyId !== null) {
                    DB::table('departments')->where('id', $department->id)->update(['company_id' => $companyId]);
                }
            });

        if (Schema::hasColumn('divisions', 'department_id') && $this->legacyDivisionUnderDepartmentRemains()) {
            $this->migrateDivisionUnderDepartmentToDivisionAboveDepartment();
        }

        DB::table('departments')
            ->whereNull('division_id')
            ->orderBy('id')
            ->each(function ($department): void {
                $companyId = $department->company_id;
                if ($companyId === null && $department->branch_id !== null) {
                    $companyId = DB::table('branches')->where('id', $department->branch_id)->value('company_id');
                }

                $divisionId = DB::table('divisions')->insertGetId([
                    'name' => 'General - '.$department->name,
                    'code' => null,
                    'company_id' => $companyId,
                    'branch_id' => $department->branch_id,
                    'division_head_id' => null,
                    'status' => 'active',
                    'description' => 'Auto-created during org hierarchy migration.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('departments')->where('id', $department->id)->update([
                    'division_id' => $divisionId,
                    'company_id' => $companyId,
                ]);
            });

        DB::table('users')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($user): void {
                $department = DB::table('departments')->where('id', $user->department_id)->first();
                if (! $department) {
                    return;
                }

                DB::table('users')->where('id', $user->id)->update([
                    'division_id' => $user->division_id ?? $department->division_id,
                    'branch_id' => $user->branch_id ?? $department->branch_id,
                    'company_id' => $user->company_id ?? $department->company_id,
                ]);
            });

        DB::table('sections_or_units')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->each(function ($section): void {
                $department = DB::table('departments')->where('id', $section->department_id)->first();
                if (! $department) {
                    return;
                }

                DB::table('sections_or_units')->where('id', $section->id)->update([
                    'division_id' => $section->division_id ?? $department->division_id,
                    'branch_id' => $section->branch_id ?? $department->branch_id,
                    'company_id' => $section->company_id ?? $department->company_id,
                ]);
            });

        $this->flagHierarchyMismatches();

        if (Schema::hasColumn('divisions', 'department_id')) {
            $this->dropDivisionsDepartmentIdColumn();
        }
    }

    private function dropDivisionsDepartmentIdColumn(): void
    {
        DB::table('divisions')->whereNotNull('department_id')->update(['department_id' => null]);

        if ($this->foreignKeyExists('divisions', 'divisions_department_id_foreign')) {
            Schema::table('divisions', function (Blueprint $table) {
                $table->dropForeign('divisions_department_id_foreign');
            });
        }

        if (Schema::hasColumn('divisions', 'department_id')) {
            DB::statement('ALTER TABLE `divisions` DROP COLUMN `department_id`');
        }

        if (! $this->indexExists('divisions', 'divisions_company_branch_index')) {
            Schema::table('divisions', function (Blueprint $table) {
                $table->index(['company_id', 'branch_id'], 'divisions_company_branch_index');
            });
        }
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $foreignKey, 'FOREIGN KEY'],
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }

    public function down(): void
    {
        if (! Schema::hasColumn('divisions', 'department_id')) {
            Schema::table('divisions', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
                $table->index(['company_id', 'branch_id', 'department_id']);
            });
        }

        if (Schema::hasColumn('departments', 'division_id')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropForeign(['division_id']);
                $table->dropForeign(['company_id']);
                $table->dropIndex('departments_org_hierarchy_index');
                $table->dropColumn(['division_id', 'company_id', 'hierarchy_mismatch']);
            });
        }
    }

    private function legacyDivisionUnderDepartmentRemains(): bool
    {
        if (! Schema::hasColumn('divisions', 'department_id') || ! Schema::hasColumn('departments', 'division_id')) {
            return Schema::hasColumn('divisions', 'department_id');
        }

        return DB::table('divisions')
            ->whereNotNull('department_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('departments')
                    ->whereColumn('departments.id', 'divisions.department_id')
                    ->whereNull('departments.division_id');
            })
            ->exists();
    }

    private function migrateDivisionUnderDepartmentToDivisionAboveDepartment(): void
    {
        $legacyDivisions = DB::table('divisions')
            ->whereNotNull('department_id')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('departments')
                    ->whereColumn('departments.id', 'divisions.department_id')
                    ->whereNull('departments.division_id');
            })
            ->orderBy('id')
            ->get();
        $promotedByDepartment = [];

        foreach ($legacyDivisions as $oldDivision) {
            $parentDepartment = DB::table('departments')->where('id', $oldDivision->department_id)->first();
            if (! $parentDepartment) {
                continue;
            }

            $parentDepartmentId = (int) $parentDepartment->id;
            if (! isset($promotedByDepartment[$parentDepartmentId])) {
                $companyId = $parentDepartment->company_id;
                if ($companyId === null && $parentDepartment->branch_id !== null) {
                    $companyId = DB::table('branches')->where('id', $parentDepartment->branch_id)->value('company_id');
                }

                $promotedByDepartment[$parentDepartmentId] = (int) DB::table('divisions')->insertGetId([
                    'name' => $parentDepartment->name,
                    'code' => null,
                    'company_id' => $companyId,
                    'branch_id' => $parentDepartment->branch_id,
                    'division_head_id' => $parentDepartment->department_head_id,
                    'status' => 'active',
                    'description' => $parentDepartment->description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $promotedDivisionId = $promotedByDepartment[$parentDepartmentId];
            $companyId = $oldDivision->company_id ?? $parentDepartment->company_id;
            if ($companyId === null && $parentDepartment->branch_id !== null) {
                $companyId = DB::table('branches')->where('id', $parentDepartment->branch_id)->value('company_id');
            }

            $normalizedOldName = trim((string) $oldDivision->name);
            $normalizedParentName = trim((string) $parentDepartment->name);
            if ($normalizedOldName !== '' && strcasecmp($normalizedOldName, $normalizedParentName) === 0) {
                $newDepartmentId = $parentDepartmentId;
                DB::table('departments')->where('id', $parentDepartmentId)->update([
                    'division_id' => $promotedDivisionId,
                    'company_id' => $companyId,
                    'department_head_id' => $oldDivision->division_head_id ?? $parentDepartment->department_head_id,
                    'hierarchy_mismatch' => true,
                ]);
            } else {
                $departmentName = $this->uniqueDepartmentName($normalizedOldName !== '' ? $normalizedOldName : 'Department '.$oldDivision->id);
                $newDepartmentId = (int) DB::table('departments')->insertGetId([
                    'name' => $departmentName,
                    'branch_id' => $oldDivision->branch_id ?? $parentDepartment->branch_id,
                    'company_id' => $companyId,
                    'division_id' => $promotedDivisionId,
                    'department_head_id' => $oldDivision->division_head_id,
                    'office_location' => $parentDepartment->office_location,
                    'description' => $oldDivision->description,
                    'logo' => null,
                    'hierarchy_mismatch' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('departments')->where('id', $parentDepartmentId)->update([
                    'division_id' => $promotedDivisionId,
                    'company_id' => $companyId,
                    'hierarchy_mismatch' => true,
                ]);
            }

            DB::table('sections_or_units')
                ->where('division_id', $oldDivision->id)
                ->update([
                    'division_id' => $promotedDivisionId,
                    'department_id' => $newDepartmentId,
                    'branch_id' => $oldDivision->branch_id ?? $parentDepartment->branch_id,
                    'company_id' => $companyId,
                ]);

            DB::table('users')
                ->where('division_id', $oldDivision->id)
                ->update([
                    'division_id' => $promotedDivisionId,
                    'department_id' => $newDepartmentId,
                    'branch_id' => $oldDivision->branch_id ?? $parentDepartment->branch_id,
                    'company_id' => $companyId,
                ]);

            DB::table('users')
                ->where('department_id', $parentDepartmentId)
                ->where(function ($query) use ($oldDivision): void {
                    $query->whereNull('division_id')
                        ->orWhere('division_id', $oldDivision->id);
                })
                ->update([
                    'division_id' => $promotedDivisionId,
                ]);

            DB::table('divisions')->where('id', $oldDivision->id)->delete();
        }
    }

    private function uniqueDepartmentName(string $baseName): string
    {
        $candidate = $baseName;
        $suffix = 2;

        while (DB::table('departments')->where('name', $candidate)->exists()) {
            $candidate = $baseName.' ('.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }

    private function flagHierarchyMismatches(): void
    {
        if (! Schema::hasColumn('users', 'hierarchy_mismatch')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('hierarchy_mismatch')->default(false)->after('section_unit_id');
            });
        }

        if (! Schema::hasColumn('departments', 'hierarchy_mismatch')) {
            return;
        }

        DB::table('departments')->where('hierarchy_mismatch', true)->update(['hierarchy_mismatch' => true]);

        DB::table('users')
            ->whereNotNull('section_unit_id')
            ->orderBy('id')
            ->each(function ($user): void {
                $section = DB::table('sections_or_units')->where('id', $user->section_unit_id)->first();
                if (! $section) {
                    return;
                }

                $mismatch = ($user->department_id !== null && (int) $user->department_id !== (int) ($section->department_id ?? 0))
                    || ($user->division_id !== null && (int) $user->division_id !== (int) ($section->division_id ?? 0))
                    || ($user->branch_id !== null && (int) $user->branch_id !== (int) ($section->branch_id ?? 0))
                    || ($user->company_id !== null && (int) $user->company_id !== (int) ($section->company_id ?? 0));

                if ($mismatch) {
                    DB::table('users')->where('id', $user->id)->update(['hierarchy_mismatch' => true]);
                }
            });
    }
};
