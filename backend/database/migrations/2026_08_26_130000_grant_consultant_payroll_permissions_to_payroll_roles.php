<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $slugs = [
            'consultant.view',
            'consultant.payroll.generate',
            'consultant.payroll.finalize',
            'consultant.payroll.download',
            'consultant.reports',
        ];

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $roleKeys = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.slug', 'payslip.generate')
            ->distinct()
            ->pluck('role_permissions.role_key');

        foreach ($roleKeys as $roleKey) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => (string) $roleKey, 'permission_id' => (int) $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
            RbacService::forgetRoleCache((string) $roleKey);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: revoking would break roles that were manually granted.
    }
};
