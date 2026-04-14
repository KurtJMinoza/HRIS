<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['slug' => 'payslip.view', 'module' => 'payslip', 'label' => 'View payslips', 'description' => 'View payslip previews and list pages'],
            ['slug' => 'payslip.generate', 'module' => 'payslip', 'label' => 'Generate payslips', 'description' => 'Generate payslips for selected scope'],
            ['slug' => 'payslip.finalize', 'module' => 'payslip', 'label' => 'Finalize payslips', 'description' => 'Finalize payroll and send payslips'],
            ['slug' => 'payslip.download', 'module' => 'payslip', 'label' => 'Download payslips', 'description' => 'Download payslip PDF files'],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $p['slug']],
                [
                    'module' => $p['module'],
                    'label' => $p['label'],
                    'description' => $p['description'],
                ]
            );
        }

        $idBySlug = DB::table('permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        $grant = static function (string $roleKey, array $slugs) use ($idBySlug): void {
            foreach ($slugs as $slug) {
                $pid = (int) ($idBySlug[$slug] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                DB::table('role_permissions')->insertOrIgnore([
                    'role_key' => $roleKey,
                    'permission_id' => $pid,
                ]);
            }
        };

        $revoke = static function (string $roleKey, array $slugs) use ($idBySlug): void {
            $ids = [];
            foreach ($slugs as $slug) {
                $pid = (int) ($idBySlug[$slug] ?? 0);
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
            if ($ids !== []) {
                DB::table('role_permissions')
                    ->where('role_key', $roleKey)
                    ->whereIn('permission_id', $ids)
                    ->delete();
            }
        };

        // Admin + HR full payslip access
        $grant('admin_hr', ['payslip.view', 'payslip.generate', 'payslip.finalize', 'payslip.download']);

        // Org heads: view + download only
        foreach (['company_head', 'branch_head', 'department_head'] as $roleKey) {
            $grant($roleKey, ['payslip.view', 'payslip.download']);
            $revoke($roleKey, ['payslip.generate', 'payslip.finalize']);
        }

        // Employee: view only (own payslips)
        $grant('employee', ['payslip.view']);
        $revoke('employee', ['payslip.generate', 'payslip.finalize', 'payslip.download']);

        foreach (['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        $slugs = ['payslip.view', 'payslip.generate', 'payslip.finalize', 'payslip.download'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id')->all();

        if ($ids !== []) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        foreach (['admin_hr', 'company_head', 'branch_head', 'department_head', 'employee'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};

