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
            ['slug' => 'consultant.view', 'module' => 'consultant', 'label' => 'View Consultant payroll', 'description' => 'Open Consultant payroll pages and batch lists'],
            ['slug' => 'consultant.payroll.generate', 'module' => 'consultant', 'label' => 'Generate Consultant payroll', 'description' => 'Generate consultant-only payroll batches'],
            ['slug' => 'consultant.payroll.finalize', 'module' => 'consultant', 'label' => 'Finalize Consultant payroll', 'description' => 'Finalize consultant payroll batches separately'],
            ['slug' => 'consultant.payroll.download', 'module' => 'consultant', 'label' => 'Download Consultant payslips', 'description' => 'Download consultant payslip PDFs and bulk archives'],
            ['slug' => 'consultant.reports', 'module' => 'consultant', 'label' => 'Consultant payroll reports', 'description' => 'View and export consultant payroll reports'],
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
            'consultant.view',
            'consultant.payroll.generate',
            'consultant.payroll.finalize',
            'consultant.payroll.download',
            'consultant.reports',
        ];

        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        if (Schema::hasTable('role_permissions') && $ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }
};
