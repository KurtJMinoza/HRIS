<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['slug' => 'org.division.view', 'module' => 'organization', 'label' => 'View divisions', 'description' => 'List divisions in scope'],
            ['slug' => 'org.division.manage', 'module' => 'organization', 'label' => 'Manage divisions', 'description' => 'Create/update/delete divisions'],
            ['slug' => 'org.section_unit.view', 'module' => 'organization', 'label' => 'View sections/units', 'description' => 'List sections or units in scope'],
            ['slug' => 'org.section_unit.manage', 'module' => 'organization', 'label' => 'Manage sections/units', 'description' => 'Create/update/delete sections or units'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                $permission + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        $managerPermissions = [
            'dashboard.view',
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'approve-schedule',
            'employees.view',
            'attendance.view',
            'reports.view',
            'reports.export',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'leave.view',
            'leave.approve',
            'leave.notes',
            'loans.view_own',
            'request-loan',
            'loans.request',
            'loans.view',
            'loans.assign',
            'overtime.view',
            'overtime.approve',
            'government_deductions.view',
            'government_deductions.rates.view',
            'payslip.view',
            'payslip.download',
        ];

        $ids = DB::table('permissions')
            ->whereIn('slug', $managerPermissions)
            ->pluck('id', 'slug');

        foreach (['division_head', 'section_unit_head'] as $roleKey) {
            foreach ($managerPermissions as $slug) {
                $permissionId = $ids[$slug] ?? null;
                if (! $permissionId) {
                    continue;
                }
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => $roleKey, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('role_key', ['division_head', 'section_unit_head'])
            ->delete();

        $slugs = ['org.division.view', 'org.division.manage', 'org.section_unit.view', 'org.section_unit.manage'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
