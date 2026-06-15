<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        ['slug' => 'recruitment.view', 'module' => 'recruitment', 'label' => 'View recruitment', 'description' => 'Open recruitment dashboard, applicant queues, exams, and hiring workflow'],
        ['slug' => 'recruitment.create', 'module' => 'recruitment', 'label' => 'Create applicants', 'description' => 'Add applicant intake records'],
        ['slug' => 'recruitment.edit', 'module' => 'recruitment', 'label' => 'Edit applicants', 'description' => 'Update applicant intake records and workflow data'],
        ['slug' => 'recruitment.delete', 'module' => 'recruitment', 'label' => 'Delete applicants', 'description' => 'Delete applicant records'],
        ['slug' => 'recruitment.documents', 'module' => 'recruitment', 'label' => 'Manage applicant documents', 'description' => 'Upload, update, verify, and download recruitment documents'],
        ['slug' => 'recruitment.interviews', 'module' => 'recruitment', 'label' => 'Manage recruitment interviews', 'description' => 'Schedule, score, and decide initial or final interviews'],
        ['slug' => 'recruitment.exams', 'module' => 'recruitment', 'label' => 'Manage recruitment exams', 'description' => 'Build exams, assign them, and score applicant answers'],
        ['slug' => 'recruitment.hiring', 'module' => 'recruitment', 'label' => 'Manage hiring decisions', 'description' => 'Approve hiring, reject applicants, or move applicants to requirements'],
        ['slug' => 'recruitment.convert', 'module' => 'recruitment', 'label' => 'Convert applicants to employees', 'description' => 'Create employee records from hired applicants'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        if (! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_column($this->permissions, 'slug'))
            ->pluck('id');

        foreach (['super_admin', 'admin', 'admin_hr'] as $roleKey) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_key' => $roleKey, 'permission_id' => (int) $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $slugs = array_column($this->permissions, 'slug');
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');

        if (Schema::hasTable('role_permissions') && $ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids->all())->delete();
        }

        DB::table('permissions')->whereIn('slug', $slugs)->delete();

        foreach (['super_admin', 'admin', 'admin_hr'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};
