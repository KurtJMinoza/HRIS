<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $slugs = [
            'refunds.view',
            'refunds.create',
            'refunds.approve',
            'refunds.process',
        ];

        $configPerms = collect(config('rbac.permissions', []))->keyBy('slug');
        $now = now();

        foreach ($slugs as $slug) {
            $def = $configPerms->get($slug);
            if (! $def) {
                continue;
            }
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'module' => $def['module'],
                    'label' => $def['label'],
                    'description' => $def['description'] ?? null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $permIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id', 'slug');

        foreach ($permIds as $permId) {
            if (! $permId) {
                continue;
            }
            foreach (['admin', 'admin_hr', 'super_admin', 'payroll_admin'] as $roleKey) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => $roleKey, 'permission_id' => $permId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $slugs = [
            'refunds.view',
            'refunds.create',
            'refunds.approve',
            'refunds.process',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
