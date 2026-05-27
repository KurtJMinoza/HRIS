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
            ['slug' => 'execom.view', 'module' => 'execom', 'label' => 'View EXECOM management', 'description' => 'List EXECOM employees and payroll settings'],
            ['slug' => 'execom.manage', 'module' => 'execom', 'label' => 'Manage EXECOM employees', 'description' => 'Add, update, deactivate EXECOM payroll profiles and settings'],
            ['slug' => 'execom.payroll.generate', 'module' => 'execom', 'label' => 'Generate EXECOM payroll', 'description' => 'Generate fixed-salary EXECOM payroll batches'],
            ['slug' => 'execom.payroll.finalize', 'module' => 'execom', 'label' => 'Finalize EXECOM payroll', 'description' => 'Finalize EXECOM payroll batches separately'],
            ['slug' => 'execom.payroll.download', 'module' => 'execom', 'label' => 'Download EXECOM payslips', 'description' => 'Download EXECOM payslip PDFs and bulk archives'],
            ['slug' => 'execom.reports', 'module' => 'execom', 'label' => 'EXECOM payroll reports', 'description' => 'View and export EXECOM payroll reports'],
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
            'execom.view',
            'execom.manage',
            'execom.payroll.generate',
            'execom.payroll.finalize',
            'execom.payroll.download',
            'execom.reports',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        if (Schema::hasTable('role_permissions') && $ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
