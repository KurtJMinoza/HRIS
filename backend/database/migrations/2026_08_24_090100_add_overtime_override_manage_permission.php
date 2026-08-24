<?php

use App\Enums\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $slug = 'overtime.override.manage';

        DB::table('permissions')->updateOrInsert(
            ['slug' => $slug],
            [
                'module' => 'overtime',
                'label' => 'Manage OT auto-approve',
                'description' => 'Configure per-employee overtime auto-approve overrides',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_key' => HrRole::AdminHr->value,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slug = 'overtime.override.manage';
        $id = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
