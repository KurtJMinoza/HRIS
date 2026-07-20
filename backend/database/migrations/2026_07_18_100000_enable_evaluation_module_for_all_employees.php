<?php

use App\Models\Permission;
use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ensure evaluation module permissions exist and every core role can open/complete evaluations.
 * Previous grant migration no-oped when evaluations.view / evaluations.create were missing from permissions.
 */
return new class extends Migration
{
    private const VIEW_CREATE = ['evaluations.view', 'evaluations.create'];

    private const ALL_EVAL_SLUGS = [
        'evaluations.view',
        'evaluations.create',
        'evaluations.review',
        'evaluations.templates.manage',
        'evaluations.assign',
    ];

    private const ROLES = [
        'employee',
        'team_lead',
        'admin',
        'admin_hr',
        'super_admin',
        'company_head',
        'branch_head',
        'division_head',
        'department_head',
        'section_unit_head',
        'area_head',
        'payroll_admin',
    ];

    public function up(): void
    {
        $configPerms = collect(config('rbac.permissions', []))->keyBy('slug');

        foreach (self::ALL_EVAL_SLUGS as $slug) {
            $row = $configPerms->get($slug);
            if (! is_array($row)) {
                continue;
            }
            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'module' => (string) ($row['module'] ?? 'evaluations'),
                    'label' => (string) ($row['label'] ?? Str::headline(str_replace(['.', '-'], ' ', $slug))),
                    'description' => $row['description'] ?? null,
                ]
            );
        }

        $ids = Permission::query()->whereIn('slug', self::VIEW_CREATE)->pluck('id', 'slug');
        if ($ids->isEmpty()) {
            return;
        }

        $roles = array_values(array_unique(array_merge(
            self::ROLES,
            DB::table('role_permissions')->distinct()->pluck('role_key')->all(),
        )));

        $now = now();
        foreach ($roles as $roleKey) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_key' => $roleKey,
                    'permission_id' => (int) $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            RbacService::forgetRoleCache($roleKey);
        }
    }

    public function down(): void
    {
        // Keep permissions; only remove the broad employee/team_lead grants added for self-service.
        $permissionIds = Permission::query()->whereIn('slug', self::VIEW_CREATE)->pluck('id');
        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_key', ['employee', 'team_lead', 'payroll_admin'])
            ->delete();

        foreach (['employee', 'team_lead', 'payroll_admin'] as $roleKey) {
            RbacService::forgetRoleCache($roleKey);
        }
    }
};
