<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('rbac.permissions', []) as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'module' => (string) ($row['module'] ?? 'misc'),
                    'label' => (string) ($row['label'] ?? Str::headline(str_replace(['.', '-'], ' ', $slug))),
                    'description' => $row['description'] ?? null,
                ]
            );
        }

        $allIds = Permission::query()->pluck('id', 'slug');

        foreach (config('rbac.default_role_permissions', []) as $roleKey => $slugs) {
            if ($slugs === ['*']) {
                $slugs = $allIds->keys()->all();
            }

            DB::table('role_permissions')->where('role_key', $roleKey)->delete();

            foreach ($slugs as $slug) {
                $pid = $allIds[$slug] ?? null;
                if ($pid === null) {
                    continue;
                }
                DB::table('role_permissions')->insert([
                    'role_key' => $roleKey,
                    'permission_id' => $pid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
