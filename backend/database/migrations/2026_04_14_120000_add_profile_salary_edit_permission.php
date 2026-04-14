<?php

use App\Enums\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $slug = 'profile.salary.edit';

        DB::table('permissions')->updateOrInsert(
            ['slug' => $slug],
            [
                'module' => 'profile',
                'label' => 'Edit salary details',
                'description' => 'Edit compensation and salary fields on employee profile (Admin/HR)',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        if (! $permissionId) {
            return;
        }

        // Admin HR role receives all permissions via seeder (*); ensure DB grant exists for environments that skip full reseed.
        DB::table('role_permissions')->insertOrIgnore([
            'role_key' => HrRole::AdminHr->value,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $slug = 'profile.salary.edit';
        $id = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
