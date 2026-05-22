<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_position_types')) {
            Schema::create('organization_position_types', function (Blueprint $table) {
                $table->id();
                $table->string('organization_level', 40)->index();
                $table->string('position_name');
                $table->unsignedSmallInteger('approval_priority')->default(1)->index();
                $table->boolean('can_approve')->default(true)->index();
                $table->boolean('is_final_approver')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['organization_level', 'position_name'], 'org_position_type_level_name_unique');
            });
        }

        if (! Schema::hasTable('organization_position_assignments')) {
            Schema::create('organization_position_assignments', function (Blueprint $table) {
                $table->id();
                $table->string('organization_level', 40)->index();
                $table->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
                $table->foreignId('position_type_id')->constrained('organization_position_types')->restrictOnDelete();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false)->index();
                $table->unsignedSmallInteger('approval_priority')->default(1)->index();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['organization_unit_id', 'is_active', 'approval_priority'], 'org_pos_assign_unit_active_priority');
                $table->index(['employee_id', 'is_active'], 'org_pos_assign_employee_active');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'assigned_team_leader_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('assigned_team_leader_id')->nullable()->after('supervisor_id')->constrained('users')->nullOnDelete();
            });
        }

        $this->seedDefaultPositionTypes();
        $this->migrateUnitLeadersToPositionAssignments();
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'assigned_team_leader_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assigned_team_leader_id');
            });
        }

        Schema::dropIfExists('organization_position_assignments');
        Schema::dropIfExists('organization_position_types');
    }

    private function seedDefaultPositionTypes(): void
    {
        $now = now();
        $defaults = [
            'company' => ['Company Head', 'Co-Company Head', 'Officer-in-Charge', 'Assistant Company Head'],
            'branch' => ['Branch Head', 'Assistant Branch Head', 'Branch OIC'],
            'division' => ['Division Head', 'Assistant Division Head', 'Division OIC'],
            'department' => ['Department Head', 'Assistant Department Head', 'Team Leader', 'Immediate Supervisor'],
            'section_unit' => ['Section Head', 'Unit Head', 'Team Leader', 'Immediate Leader'],
        ];

        foreach ($defaults as $level => $names) {
            foreach ($names as $index => $name) {
                DB::table('organization_position_types')->updateOrInsert(
                    [
                        'organization_level' => $level,
                        'position_name' => $name,
                    ],
                    [
                        'approval_priority' => $index + 1,
                        'can_approve' => true,
                        'is_final_approver' => false,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }
    }

    private function migrateUnitLeadersToPositionAssignments(): void
    {
        if (! Schema::hasTable('organization_unit_leaders') || ! Schema::hasTable('organization_units')) {
            return;
        }

        $typeMap = DB::table('organization_position_types')
            ->get(['id', 'organization_level', 'position_name'])
            ->groupBy('organization_level');

        $legacyLevelMap = [
            'company' => 'company',
            'branch' => 'branch',
            'division' => 'division',
            'department' => 'department',
            'section_unit' => 'section_unit',
            'team' => 'section_unit',
        ];

        DB::table('organization_unit_leaders')
            ->orderBy('id')
            ->each(function ($leader) use ($typeMap, $legacyLevelMap): void {
                $unit = DB::table('organization_units')->where('id', $leader->organization_unit_id)->first();
                if (! $unit) {
                    return;
                }

                $level = $legacyLevelMap[$unit->legacy_source_type] ?? 'section_unit';
                $positionName = trim((string) $leader->leader_role) !== '' ? trim((string) $leader->leader_role) : 'Leader';

                $positionTypeId = $this->resolveOrCreatePositionTypeId($level, $positionName, (int) $leader->approval_priority);

                DB::table('organization_position_assignments')->updateOrInsert(
                    [
                        'organization_unit_id' => (int) $leader->organization_unit_id,
                        'position_type_id' => $positionTypeId,
                        'employee_id' => (int) $leader->employee_id,
                    ],
                    [
                        'organization_level' => $level,
                        'is_primary' => (bool) $leader->is_primary,
                        'approval_priority' => (int) $leader->approval_priority,
                        'effective_from' => null,
                        'effective_to' => null,
                        'is_active' => (bool) $leader->is_active,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            });
    }

    private function resolveOrCreatePositionTypeId(string $level, string $positionName, int $priority): int
    {
        $existing = DB::table('organization_position_types')
            ->where('organization_level', $level)
            ->where('position_name', $positionName)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('organization_position_types')->insertGetId([
            'organization_level' => $level,
            'position_name' => $positionName,
            'approval_priority' => $priority,
            'can_approve' => true,
            'is_final_approver' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
