<?php

use App\Enums\HrRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'request-loan')->value('id');
        if (! $permissionId) {
            return;
        }

        $roles = [
            HrRole::Employee->value,
            HrRole::DepartmentHead->value,
            HrRole::BranchHead->value,
            HrRole::CompanyHead->value,
            HrRole::AdminHr->value,
        ];

        foreach ($roles as $roleKey) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_key' => $roleKey, 'permission_id' => $permissionId],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'request-loan')->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_key', [
                HrRole::Employee->value,
                HrRole::DepartmentHead->value,
                HrRole::BranchHead->value,
                HrRole::CompanyHead->value,
                HrRole::AdminHr->value,
            ])
            ->delete();
    }
};
