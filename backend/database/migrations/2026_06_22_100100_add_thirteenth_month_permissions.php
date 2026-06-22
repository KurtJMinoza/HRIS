<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissions = [
            ['slug' => 'thirteenth_month.view', 'module' => 'thirteenth_month', 'label' => 'View 13th Month Pay', 'description' => 'View 13th month pay runs, previews, and settings'],
            ['slug' => 'thirteenth_month.manage', 'module' => 'thirteenth_month', 'label' => 'Manage 13th Month Pay', 'description' => 'Create and compute 13th month pay runs'],
            ['slug' => 'thirteenth_month.finalize', 'module' => 'thirteenth_month', 'label' => 'Finalize 13th Month Pay', 'description' => 'Finalize 13th month pay runs'],
            ['slug' => 'thirteenth_month.reports', 'module' => 'thirteenth_month', 'label' => '13th Month Pay reports', 'description' => 'View and export 13th month pay reports'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                array_merge($permission, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        if (! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_key' => 'admin_hr', 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $slugs = [
            'thirteenth_month.view',
            'thirteenth_month.manage',
            'thirteenth_month.finalize',
            'thirteenth_month.reports',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        if (Schema::hasTable('role_permissions') && $ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
