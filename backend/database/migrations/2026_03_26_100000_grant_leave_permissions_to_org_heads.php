<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = ['leave.view', 'leave.approve', 'leave.notes'];
        $roles = ['company_head', 'branch_head', 'department_head'];

        foreach ($roles as $roleKey) {
            foreach ($slugs as $slug) {
                $pid = DB::table('permissions')->where('slug', $slug)->value('id');
                if (! $pid) {
                    continue;
                }
                $exists = DB::table('role_permissions')
                    ->where('role_key', $roleKey)
                    ->where('permission_id', $pid)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('role_permissions')->insert([
                    'role_key' => $roleKey,
                    'permission_id' => $pid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        $slugs = ['leave.view', 'leave.approve', 'leave.notes'];
        $roles = ['company_head', 'branch_head', 'department_head'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('role_permissions')
            ->whereIn('role_key', $roles)
            ->whereIn('permission_id', $ids->all())
            ->delete();
        foreach ($roles as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};
