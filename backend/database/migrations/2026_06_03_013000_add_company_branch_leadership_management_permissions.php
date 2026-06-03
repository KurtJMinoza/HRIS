<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['slug' => 'company.assign_head', 'module' => 'organization', 'label' => 'Assign Company Heads', 'description' => 'Assign company heads and acting company leaders.'],
            ['slug' => 'company.manage_scope', 'module' => 'organization', 'label' => 'Manage Company Approval Scope', 'description' => 'Configure company head approval scopes.'],
            ['slug' => 'company.manage_request_types', 'module' => 'organization', 'label' => 'Manage Company Request Type Scope', 'description' => 'Configure company head request type restrictions.'],
            ['slug' => 'branch.assign_head', 'module' => 'organization', 'label' => 'Assign Branch Heads', 'description' => 'Assign branch heads and acting branch leaders.'],
            ['slug' => 'branch.manage_scope', 'module' => 'organization', 'label' => 'Manage Branch Approval Scope', 'description' => 'Configure branch head approval scopes.'],
            ['slug' => 'branch.manage_request_types', 'module' => 'organization', 'label' => 'Manage Branch Request Type Scope', 'description' => 'Configure branch head request type restrictions.'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                array_merge($permission, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        $ids = DB::table('permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        foreach (['admin_hr', 'super_admin'] as $roleKey) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => $roleKey, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $slugs = [
            'company.assign_head',
            'company.manage_scope',
            'company.manage_request_types',
            'branch.assign_head',
            'branch.manage_scope',
            'branch.manage_request_types',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
