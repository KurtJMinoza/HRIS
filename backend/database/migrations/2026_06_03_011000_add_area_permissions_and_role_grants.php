<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['slug' => 'area.view', 'module' => 'organization', 'label' => 'View areas', 'description' => 'View area records and area-scoped organization data'],
            ['slug' => 'area.create', 'module' => 'organization', 'label' => 'Create areas', 'description' => 'Create areas under companies'],
            ['slug' => 'area.update', 'module' => 'organization', 'label' => 'Update areas', 'description' => 'Edit area records and assignments'],
            ['slug' => 'area.delete', 'module' => 'organization', 'label' => 'Deactivate areas', 'description' => 'Deactivate areas'],
            ['slug' => 'area.manage', 'module' => 'organization', 'label' => 'Manage areas', 'description' => 'Manage area setup, branches, and area heads'],
            ['slug' => 'area.assign_head', 'module' => 'organization', 'label' => 'Assign area head', 'description' => 'Assign Area Head or Area Manager'],
            ['slug' => 'area.view_employees', 'module' => 'organization', 'label' => 'View area employees', 'description' => 'View employees under an area'],
            ['slug' => 'area.approve_leave', 'module' => 'organization', 'label' => 'Approve area leaves', 'description' => 'Approve leave requests in assigned area scope'],
            ['slug' => 'area.approve_overtime', 'module' => 'organization', 'label' => 'Approve area overtime', 'description' => 'Approve overtime requests in assigned area scope'],
            ['slug' => 'area.approve_attendance_correction', 'module' => 'organization', 'label' => 'Approve area attendance corrections', 'description' => 'Approve attendance corrections in assigned area scope'],
            ['slug' => 'reports.view_area', 'module' => 'reports', 'label' => 'View area reports', 'description' => 'Filter and view reports by area'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                $permission + ['created_at' => $now, 'updated_at' => $now],
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
            'reports.view_area',
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
            'area.view',
            'area.view_employees',
            'area.approve_leave',
            'area.approve_overtime',
            'area.approve_attendance_correction',
            'org.branch.view',
            'org.division.view',
            'org.department.view',
            'org.section_unit.view',
        ];

        $adminPermissions = array_merge($managerPermissions, [
            'area.create',
            'area.update',
            'area.delete',
            'area.manage',
            'area.assign_head',
        ]);

        $ids = DB::table('permissions')
            ->whereIn('slug', array_values(array_unique(array_merge($managerPermissions, $adminPermissions))))
            ->pluck('id', 'slug');

        foreach ($managerPermissions as $slug) {
            $permissionId = $ids[$slug] ?? null;
            if ($permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => 'area_head', 'permission_id' => (int) $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        foreach ($adminPermissions as $slug) {
            $permissionId = $ids[$slug] ?? null;
            if ($permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => 'admin_hr', 'permission_id' => (int) $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'area.view',
            'area.create',
            'area.update',
            'area.delete',
            'area.manage',
            'area.assign_head',
            'area.view_employees',
            'area.approve_leave',
            'area.approve_overtime',
            'area.approve_attendance_correction',
            'reports.view_area',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->where('role_key', 'area_head')->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
