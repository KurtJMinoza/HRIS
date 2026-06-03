<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['slug' => 'branch.approve_leave', 'module' => 'organization', 'label' => 'Approve branch leaves', 'description' => 'Approve leave requests in assigned branch scope'],
            ['slug' => 'branch.approve_overtime', 'module' => 'organization', 'label' => 'Approve branch overtime', 'description' => 'Approve overtime requests in assigned branch scope'],
            ['slug' => 'branch.approve_attendance_correction', 'module' => 'organization', 'label' => 'Approve branch attendance corrections', 'description' => 'Approve attendance corrections in assigned branch scope'],
            ['slug' => 'company.approve_leave', 'module' => 'organization', 'label' => 'Approve company leaves', 'description' => 'Approve leave requests in assigned company scope'],
            ['slug' => 'company.approve_overtime', 'module' => 'organization', 'label' => 'Approve company overtime', 'description' => 'Approve overtime requests in assigned company scope'],
            ['slug' => 'company.approve_attendance_correction', 'module' => 'organization', 'label' => 'Approve company attendance corrections', 'description' => 'Approve attendance corrections in assigned company scope'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    'module' => $permission['module'],
                    'label' => $permission['label'],
                    'description' => $permission['description'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $this->grant('branch_head', [
            'branch.approve_leave',
            'branch.approve_overtime',
            'branch.approve_attendance_correction',
        ]);

        $this->grant('company_head', [
            'company.approve_leave',
            'company.approve_overtime',
            'company.approve_attendance_correction',
        ]);
    }

    public function down(): void
    {
        $slugs = [
            'branch.approve_leave',
            'branch.approve_overtime',
            'branch.approve_attendance_correction',
            'company.approve_leave',
            'company.approve_overtime',
            'company.approve_attendance_correction',
        ];

        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', $slugs)->delete();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function grant(string $roleKey, array $slugs): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                [
                    'role_key' => $roleKey,
                    'permission_id' => (int) $permissionId,
                ],
                [
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
};
