<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'dashboard.view')->value('id');
        if (! $permissionId) {
            return;
        }

        foreach (['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_key' => $roleKey,
                'permission_id' => (int) $permissionId,
            ]);
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'dashboard.view')->value('id');
        if (! $permissionId) {
            return;
        }

        // Keep admin_hr grant intact; only rollback non-admin additions from this migration.
        DB::table('role_permissions')
            ->where('permission_id', (int) $permissionId)
            ->whereIn('role_key', ['company_head', 'branch_head', 'department_head', 'employee'])
            ->delete();

        foreach (['company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};

