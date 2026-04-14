<?php

use App\Enums\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $definitions = [
            [
                'slug' => 'profile.view',
                'module' => 'profile',
                'label' => 'View profile',
                'description' => 'View own profile and scoped employee profile pages',
            ],
            [
                'slug' => 'profile.edit',
                'module' => 'profile',
                'label' => 'Edit profile details',
                'description' => 'Edit personal/contact/employment profile details',
            ],
            [
                'slug' => 'profile.picture.edit',
                'module' => 'profile',
                'label' => 'Edit profile picture',
                'description' => 'Upload/remove profile picture only',
            ],
        ];

        foreach ($definitions as $row) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $row['slug']],
                [
                    'module' => $row['module'],
                    'label' => $row['label'],
                    'description' => $row['description'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $roleKeys = [
            HrRole::CompanyHead->value,
            HrRole::BranchHead->value,
            HrRole::DepartmentHead->value,
            HrRole::Employee->value,
        ];
        $grantSlugs = ['profile.view', 'profile.picture.edit'];
        $permissionIds = DB::table('permissions')->whereIn('slug', $grantSlugs)->pluck('id', 'slug');

        foreach ($roleKeys as $roleKey) {
            foreach ($grantSlugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;
                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_key' => $roleKey,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $slugs = ['profile.view', 'profile.edit', 'profile.picture.edit'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id')->all();
        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
