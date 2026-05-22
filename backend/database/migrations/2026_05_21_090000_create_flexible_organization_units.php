<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_types')) {
            Schema::create('organization_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 80)->unique();
                $table->unsignedSmallInteger('level_order')->default(0)->index();
                $table->boolean('is_system')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('organization_units')) {
            Schema::create('organization_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_type_id')->constrained('organization_types')->restrictOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('organization_units')->nullOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->string('approval_routing_rule', 40)->default('first_assigned')->index();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->string('legacy_source_type', 40)->nullable();
                $table->unsignedBigInteger('legacy_source_id')->nullable();
                $table->boolean('hierarchy_mismatch')->default(false)->index();
                $table->timestamps();

                $table->index(['company_id', 'organization_type_id', 'is_active'], 'org_units_company_type_active_index');
                $table->index(['parent_id', 'sort_order'], 'org_units_parent_sort_index');
                $table->index(['legacy_source_type', 'legacy_source_id'], 'org_units_legacy_source_index');
            });
        }

        if (! Schema::hasTable('organization_unit_leaders')) {
            Schema::create('organization_unit_leaders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->string('leader_role')->default('Leader');
                $table->boolean('is_primary')->default(false)->index();
                $table->unsignedSmallInteger('approval_priority')->default(1)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['organization_unit_id', 'employee_id', 'leader_role'], 'org_unit_leader_unique');
                $table->index(['employee_id', 'is_active'], 'org_unit_leader_employee_active_index');
            });
        }

        if (! Schema::hasTable('employee_organization_assignments')) {
            Schema::create('employee_organization_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false)->index();
                $table->foreignId('immediate_leader_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['employee_id', 'is_primary', 'is_active'], 'employee_org_assign_primary_index');
                $table->index(['organization_unit_id', 'is_active'], 'employee_org_assign_unit_active_index');
            });
        }

        if (! Schema::hasTable('organization_migration_issues')) {
            Schema::create('organization_migration_issues', function (Blueprint $table) {
                $table->id();
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('issue_code', 80);
                $table->text('message');
                $table->json('context')->nullable();
                $table->boolean('resolved')->default(false)->index();
                $table->timestamps();

                $table->index(['source_type', 'source_id'], 'org_migration_issues_source_index');
            });
        }

        if (Schema::hasTable('org_approval_records')) {
            if (! Schema::hasColumn('org_approval_records', 'approval_label')) {
                Schema::table('org_approval_records', function (Blueprint $table) {
                    $table->string('approval_label')->nullable()->after('approval_level');
                });
            }
            if (! Schema::hasColumn('org_approval_records', 'eligible_approver_ids')) {
                Schema::table('org_approval_records', function (Blueprint $table) {
                    $table->json('eligible_approver_ids')->nullable()->after('approver_name');
                });
            }
            if (! Schema::hasColumn('org_approval_records', 'routing_rule')) {
                Schema::table('org_approval_records', function (Blueprint $table) {
                    $table->string('routing_rule', 40)->nullable()->after('eligible_approver_ids');
                });
            }
        }

        $this->seedDefaultTypes();
        $this->mirrorLegacyHierarchy();
    }

    public function down(): void
    {
        if (Schema::hasTable('org_approval_records')) {
            $approvalColumns = array_values(array_filter(
                ['approval_label', 'eligible_approver_ids', 'routing_rule'],
                fn (string $column): bool => Schema::hasColumn('org_approval_records', $column),
            ));
            if ($approvalColumns !== []) {
                Schema::table('org_approval_records', function (Blueprint $table) use ($approvalColumns) {
                    $table->dropColumn($approvalColumns);
                });
            }
        }

        Schema::dropIfExists('organization_migration_issues');
        Schema::dropIfExists('employee_organization_assignments');
        Schema::dropIfExists('organization_unit_leaders');
        Schema::dropIfExists('organization_units');
        Schema::dropIfExists('organization_types');
    }

    private function seedDefaultTypes(): void
    {
        $now = now();
        foreach ([
            ['Company', 'company', 10],
            ['Branch', 'branch', 20],
            ['Division', 'division', 30],
            ['Department', 'department', 40],
            ['Section', 'section', 50],
            ['Unit', 'unit', 60],
            ['Team', 'team', 70],
            ['Custom', 'custom', 100],
        ] as [$name, $code, $order]) {
            DB::table('organization_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'level_order' => $order,
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function mirrorLegacyHierarchy(): void
    {
        $typeIds = DB::table('organization_types')->pluck('id', 'code')->all();
        $unitIds = [
            'company' => [],
            'branch' => [],
            'division' => [],
            'department' => [],
            'section' => [],
            'team' => [],
        ];

        if (Schema::hasTable('companies')) {
            DB::table('companies')->orderBy('id')->each(function ($company) use (&$unitIds, $typeIds): void {
                $unitIds['company'][(int) $company->id] = $this->insertUnit(
                    (int) $typeIds['company'],
                    null,
                    (int) $company->id,
                    (string) $company->name,
                    null,
                    null,
                    true,
                    'company',
                    (int) $company->id,
                    (int) $company->id,
                );

                if ($company->company_head_id !== null) {
                    $this->insertLeader($unitIds['company'][(int) $company->id], (int) $company->company_head_id, 'Company Head', true, 1);
                }
            });
        }

        if (Schema::hasTable('branches')) {
            DB::table('branches')->orderBy('id')->each(function ($branch) use (&$unitIds, $typeIds): void {
                $companyId = $branch->company_id !== null ? (int) $branch->company_id : null;
                $unitIds['branch'][(int) $branch->id] = $this->insertUnit(
                    (int) $typeIds['branch'],
                    $companyId !== null ? ($unitIds['company'][$companyId] ?? null) : null,
                    $companyId,
                    (string) $branch->name,
                    null,
                    $branch->address ?? null,
                    true,
                    'branch',
                    (int) $branch->id,
                    (int) $branch->id,
                );

                if ($branch->branch_manager_id !== null) {
                    $this->insertLeader($unitIds['branch'][(int) $branch->id], (int) $branch->branch_manager_id, 'Branch Head', true, 1);
                }
            });
        }

        if (Schema::hasTable('divisions')) {
            DB::table('divisions')->orderBy('id')->each(function ($division) use (&$unitIds, $typeIds): void {
                $companyId = $division->company_id !== null
                    ? (int) $division->company_id
                    : ($division->branch_id !== null ? $this->branchCompanyId((int) $division->branch_id) : null);
                $parentId = $division->branch_id !== null
                    ? ($unitIds['branch'][(int) $division->branch_id] ?? null)
                    : ($companyId !== null ? ($unitIds['company'][$companyId] ?? null) : null);

                $unitIds['division'][(int) $division->id] = $this->insertUnit(
                    (int) $typeIds['division'],
                    $parentId,
                    $companyId,
                    (string) $division->name,
                    $division->code ?? null,
                    $division->description ?? null,
                    ($division->status ?? 'active') === 'active',
                    'division',
                    (int) $division->id,
                    (int) $division->id,
                );

                if ($division->division_head_id !== null) {
                    $this->insertLeader($unitIds['division'][(int) $division->id], (int) $division->division_head_id, 'Division Head', true, 1);
                }
            });
        }

        if (Schema::hasTable('departments')) {
            DB::table('departments')->orderBy('id')->each(function ($department) use (&$unitIds, $typeIds): void {
                $companyId = $department->company_id !== null
                    ? (int) $department->company_id
                    : ($department->branch_id !== null ? $this->branchCompanyId((int) $department->branch_id) : null);
                $parentId = $department->division_id !== null
                    ? ($unitIds['division'][(int) $department->division_id] ?? null)
                    : ($department->branch_id !== null
                        ? ($unitIds['branch'][(int) $department->branch_id] ?? null)
                        : ($companyId !== null ? ($unitIds['company'][$companyId] ?? null) : null));

                $unitIds['department'][(int) $department->id] = $this->insertUnit(
                    (int) $typeIds['department'],
                    $parentId,
                    $companyId,
                    (string) $department->name,
                    null,
                    $department->description ?? null,
                    true,
                    'department',
                    (int) $department->id,
                    (int) $department->id,
                    (bool) ($department->hierarchy_mismatch ?? false),
                );

                if ($department->department_head_id !== null) {
                    $this->insertLeader($unitIds['department'][(int) $department->id], (int) $department->department_head_id, 'Department Head', true, 1);
                }
            });
        }

        if (Schema::hasTable('department_team_leaders')) {
            DB::table('department_team_leaders')->orderBy('id')->each(function ($row) use (&$unitIds): void {
                $unitId = $unitIds['department'][(int) $row->department_id] ?? null;
                if ($unitId !== null) {
                    $this->insertLeader((int) $unitId, (int) $row->employee_id, 'Team Leader', false, 10);
                }
            });
        }

        if (Schema::hasTable('sections_or_units')) {
            DB::table('sections_or_units')->orderBy('id')->each(function ($section) use (&$unitIds, $typeIds): void {
                $companyId = $section->company_id !== null
                    ? (int) $section->company_id
                    : ($section->branch_id !== null ? $this->branchCompanyId((int) $section->branch_id) : null);
                $parentId = $section->department_id !== null
                    ? ($unitIds['department'][(int) $section->department_id] ?? null)
                    : ($section->division_id !== null
                        ? ($unitIds['division'][(int) $section->division_id] ?? null)
                        : ($section->branch_id !== null
                            ? ($unitIds['branch'][(int) $section->branch_id] ?? null)
                            : ($companyId !== null ? ($unitIds['company'][$companyId] ?? null) : null)));

                $unitIds['section'][(int) $section->id] = $this->insertUnit(
                    (int) $typeIds['section'],
                    $parentId,
                    $companyId,
                    (string) $section->name,
                    $section->code ?? null,
                    $section->description ?? null,
                    ($section->status ?? 'active') === 'active',
                    'section_unit',
                    (int) $section->id,
                    (int) $section->id,
                    (bool) ($section->hierarchy_mismatch ?? false),
                );

                if ($section->section_unit_head_id !== null) {
                    $this->insertLeader($unitIds['section'][(int) $section->id], (int) $section->section_unit_head_id, 'Section Leader', true, 1);
                }
            });
        }

        if (Schema::hasTable('section_unit_team_leaders')) {
            DB::table('section_unit_team_leaders')->orderBy('id')->each(function ($row) use (&$unitIds): void {
                $unitId = $unitIds['section'][(int) $row->section_unit_id] ?? null;
                if ($unitId !== null) {
                    $this->insertLeader((int) $unitId, (int) $row->employee_id, 'Team Leader', false, 10);
                }
            });
        }

        if (Schema::hasTable('teams')) {
            DB::table('teams')->orderBy('id')->each(function ($team) use (&$unitIds, $typeIds): void {
                $departmentId = $team->department_id !== null ? (int) $team->department_id : null;
                $department = $departmentId !== null ? DB::table('departments')->where('id', $departmentId)->first() : null;
                $companyId = $department?->company_id !== null
                    ? (int) $department->company_id
                    : ($department?->branch_id !== null ? $this->branchCompanyId((int) $department->branch_id) : null);

                $unitIds['team'][(int) $team->id] = $this->insertUnit(
                    (int) $typeIds['team'],
                    $departmentId !== null ? ($unitIds['department'][$departmentId] ?? null) : null,
                    $companyId,
                    (string) $team->name,
                    null,
                    null,
                    true,
                    'team',
                    (int) $team->id,
                    (int) $team->id,
                );

                if ($team->team_leader_id !== null) {
                    $this->insertLeader($unitIds['team'][(int) $team->id], (int) $team->team_leader_id, 'Team Leader', true, 1);
                }
            });
        }

        $this->mirrorEmployeeAssignments($unitIds);
    }

    private function mirrorEmployeeAssignments(array $unitIds): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')
            ->whereIn('role', ['employee', 'admin'])
            ->orderBy('id')
            ->each(function ($user) use ($unitIds): void {
                $source = null;
                if ($user->team_id !== null && isset($unitIds['team'][(int) $user->team_id])) {
                    $source = ['team', (int) $user->team_id];
                } elseif ($user->section_unit_id !== null && isset($unitIds['section'][(int) $user->section_unit_id])) {
                    $source = ['section', (int) $user->section_unit_id];
                } elseif ($user->department_id !== null && isset($unitIds['department'][(int) $user->department_id])) {
                    $source = ['department', (int) $user->department_id];
                } elseif ($user->division_id !== null && isset($unitIds['division'][(int) $user->division_id])) {
                    $source = ['division', (int) $user->division_id];
                } elseif ($user->branch_id !== null && isset($unitIds['branch'][(int) $user->branch_id])) {
                    $source = ['branch', (int) $user->branch_id];
                } elseif ($user->company_id !== null && isset($unitIds['company'][(int) $user->company_id])) {
                    $source = ['company', (int) $user->company_id];
                }

                if ($source === null) {
                    DB::table('organization_migration_issues')->insert([
                        'source_type' => 'user',
                        'source_id' => (int) $user->id,
                        'issue_code' => 'missing_organization_unit',
                        'message' => 'Employee has no legacy organization unit that can be mirrored.',
                        'context' => json_encode([
                            'company_id' => $user->company_id,
                            'branch_id' => $user->branch_id,
                            'division_id' => $user->division_id,
                            'department_id' => $user->department_id,
                            'section_unit_id' => $user->section_unit_id,
                            'team_id' => $user->team_id,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return;
                }

                [$kind, $legacyId] = $source;
                $unitId = $unitIds[$kind][$legacyId] ?? null;
                if ($unitId === null) {
                    return;
                }

                $unit = DB::table('organization_units')->where('id', $unitId)->first();
                DB::table('employee_organization_assignments')->updateOrInsert(
                    [
                        'employee_id' => (int) $user->id,
                        'organization_unit_id' => (int) $unitId,
                    ],
                    [
                        'is_primary' => true,
                        'immediate_leader_id' => $user->supervisor_id !== null ? (int) $user->supervisor_id : null,
                        'effective_from' => $user->hire_date ?? null,
                        'effective_to' => null,
                        'is_active' => (bool) ($user->is_active ?? true) && (bool) ($unit->is_active ?? true),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                if ((bool) ($user->hierarchy_mismatch ?? false) || (bool) ($unit->hierarchy_mismatch ?? false)) {
                    DB::table('organization_migration_issues')->insert([
                        'source_type' => 'user',
                        'source_id' => (int) $user->id,
                        'issue_code' => 'hierarchy_mismatch',
                        'message' => 'Legacy employee organization fields were inconsistent during flexible hierarchy migration.',
                        'context' => json_encode([
                            'organization_unit_id' => (int) $unitId,
                            'company_id' => $user->company_id,
                            'branch_id' => $user->branch_id,
                            'division_id' => $user->division_id,
                            'department_id' => $user->department_id,
                            'section_unit_id' => $user->section_unit_id,
                            'team_id' => $user->team_id,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function insertUnit(
        int $typeId,
        ?int $parentId,
        ?int $companyId,
        string $name,
        ?string $code,
        ?string $description,
        bool $isActive,
        string $legacySourceType,
        int $legacySourceId,
        int $sortOrder,
        bool $hierarchyMismatch = false,
    ): int {
        $now = now();
        $existing = DB::table('organization_units')
            ->where('legacy_source_type', $legacySourceType)
            ->where('legacy_source_id', $legacySourceId)
            ->value('id');

        $payload = [
            'organization_type_id' => $typeId,
            'parent_id' => $parentId,
            'company_id' => $companyId,
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'is_active' => $isActive,
            'approval_routing_rule' => 'first_assigned',
            'sort_order' => $sortOrder,
            'legacy_source_type' => $legacySourceType,
            'legacy_source_id' => $legacySourceId,
            'hierarchy_mismatch' => $hierarchyMismatch,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            DB::table('organization_units')->where('id', $existing)->update($payload);

            return (int) $existing;
        }

        $payload['created_at'] = $now;

        return (int) DB::table('organization_units')->insertGetId($payload);
    }

    private function insertLeader(int $unitId, int $employeeId, string $role, bool $primary, int $priority): void
    {
        if (! $this->userExists($employeeId)) {
            DB::table('organization_migration_issues')->insert([
                'source_type' => 'organization_unit',
                'source_id' => $unitId,
                'issue_code' => 'missing_leader',
                'message' => 'Legacy organization head points to a missing employee.',
                'context' => json_encode(['employee_id' => $employeeId, 'leader_role' => $role]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('organization_unit_leaders')->updateOrInsert(
            [
                'organization_unit_id' => $unitId,
                'employee_id' => $employeeId,
                'leader_role' => $role,
            ],
            [
                'is_primary' => $primary,
                'approval_priority' => $priority,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function branchCompanyId(int $branchId): ?int
    {
        $companyId = DB::table('branches')->where('id', $branchId)->value('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    private function userExists(int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->exists();
    }
};
