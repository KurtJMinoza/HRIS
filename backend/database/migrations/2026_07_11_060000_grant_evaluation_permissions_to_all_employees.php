<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = ['evaluations.view', 'evaluations.create'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id', 'slug');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $roles = ['employee', 'team_lead'];

        foreach ($roles as $roleKey) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_key' => $roleKey,
                    'permission_id' => (int) $permissionId,
                ]);
            }
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['evaluations.view', 'evaluations.create'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_key', ['employee', 'team_lead'])
            ->delete();

        foreach (['employee', 'team_lead'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};
