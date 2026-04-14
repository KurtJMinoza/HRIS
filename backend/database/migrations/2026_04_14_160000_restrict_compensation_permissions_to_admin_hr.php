<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'slug' => 'compensation.edit',
                'module' => 'compensation',
                'label' => 'Edit compensation',
                'description' => 'Edit compensation settings and employee compensation configurations',
            ],
            [
                'slug' => 'compensation.manage',
                'module' => 'compensation',
                'label' => 'Manage compensation',
                'description' => 'Full compensation module management access',
            ],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $p['slug']],
                [
                    'module' => $p['module'],
                    'label' => $p['label'],
                    'description' => $p['description'],
                ]
            );
        }

        $compPermissionIds = DB::table('permissions')
            ->where('slug', 'like', 'compensation.%')
            ->pluck('id')
            ->all();

        if ($compPermissionIds === []) {
            return;
        }

        // Revoke compensation.* from all non-admin HR roles (company/branch/department heads + employees).
        DB::table('role_permissions')
            ->whereIn('permission_id', $compPermissionIds)
            ->whereIn('role_key', ['company_head', 'branch_head', 'department_head', 'employee'])
            ->delete();

        // Ensure admin_hr has compensation core slugs explicitly (admin_hr also gets '*' in seeder defaults).
        $adminGrantSlugs = ['compensation.view', 'compensation.edit', 'compensation.manage'];
        $adminGrantIds = DB::table('permissions')
            ->whereIn('slug', $adminGrantSlugs)
            ->pluck('id')
            ->all();

        foreach ($adminGrantIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_key' => 'admin_hr',
                'permission_id' => (int) $permissionId,
            ]);
        }

        foreach (['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        $slugs = ['compensation.edit', 'compensation.manage'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id')->all();

        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        foreach (['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};

