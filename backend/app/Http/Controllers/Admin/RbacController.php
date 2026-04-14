<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\User;
use App\Services\PermissionAuditService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RbacController extends Controller
{
    public function __construct(
        private readonly PermissionAuditService $auditService,
        private readonly RbacService $rbacService,
    ) {}

    /**
     * Matrix of roles and permission slugs (for admin UI). Requires rbac.manage.
     */
    public function matrix(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->rbacService->can($user, 'rbac.manage')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Ensure configured permissions/default grants exist so the admin matrix stays usable
        // even when a deployment has not re-run the RBAC seeder yet.
        $this->ensureConfiguredPermissionsAndDefaultGrants();

        $permissions = Permission::query()->orderBy('module')->orderBy('slug')->get();
        $roleKeys = array_map(fn (HrRole $r) => $r->value, HrRole::cases());

        $matrix = [];
        foreach ($roleKeys as $rk) {
            $matrix[$rk] = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_key', $rk)
                ->pluck('permissions.slug')
                ->values()
                ->all();
        }

        return response()->json([
            'permissions' => $permissions,
            'roles' => $roleKeys,
            'matrix' => $matrix,
        ]);
    }

    /**
     * Guarantee `profile.view`, `profile.edit`, `profile.picture.edit` exist and have baseline grants
     * for all supported roles.
     *
     * - ADMIN (HR): view/edit/picture.edit enabled
     * - Others: view + picture.edit enabled; edit disabled by default
     */
    private function ensureConfiguredPermissionsAndDefaultGrants(): void
    {
        $configuredPermissions = collect(config('rbac.permissions', []))
            ->filter(fn ($row) => is_array($row) && ! empty($row['slug']))
            ->values()
            ->all();

        $now = now();
        $slugs = array_values(array_map(fn ($d) => $d['slug'], $configuredPermissions));

        $existingPermissionSlugs = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->pluck('slug')
            ->all();
        $shouldSeedGrants = count(array_diff($slugs, $existingPermissionSlugs)) > 0;
        if (! $shouldSeedGrants) {
            $permissionIdsExisting = DB::table('permissions')
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->all();
            $rolePermissionCount = DB::table('role_permissions')
                ->whereIn('permission_id', $permissionIdsExisting)
                ->count();
            $shouldSeedGrants = $rolePermissionCount === 0;
        }

        DB::transaction(function () use ($configuredPermissions, $now, $slugs, $shouldSeedGrants) {
            // Upsert permissions (idempotent).
            foreach ($configuredPermissions as $def) {
                $slug = (string) ($def['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                DB::table('permissions')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'module' => (string) ($def['module'] ?? 'misc'),
                        'label' => (string) ($def['label'] ?? Str::headline(str_replace(['.', '-'], ' ', $slug))),
                        'description' => $def['description'] ?? null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('slug', $slugs)
                ->pluck('id', 'slug');

            if ($shouldSeedGrants) {
                foreach ((array) config('rbac.default_role_permissions', []) as $roleKey => $defaultGrants) {
                    $grants = $defaultGrants === ['*'] ? $slugs : (array) $defaultGrants;
                    foreach ($grants as $slug) {
                        $permissionId = $permissionIds[$slug] ?? null;
                        if (! $permissionId) {
                            continue;
                        }

                        $exists = DB::table('role_permissions')
                            ->where('role_key', $roleKey)
                            ->where('permission_id', $permissionId)
                            ->exists();

                        if (! $exists) {
                            DB::table('role_permissions')->insert([
                                'role_key' => $roleKey,
                                'permission_id' => $permissionId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }
            }
        });

        // Clear cached effective permissions so UI/API reflects any newly inserted grants.
        foreach (HrRole::cases() as $case) {
            RbacService::forgetRoleCache($case->value);
        }
    }

    /**
     * Recent permission grant/revoke audit entries. Requires rbac.audit.
     */
    public function auditLog(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->rbacService->can($user, 'rbac.audit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $logs = PermissionAuditLog::query()
            ->with(['actor:id,name,email', 'permission:id,slug'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['logs' => $logs]);
    }

    /**
     * Replace permissions for a role. Super admin only; writes audit rows.
     */
    public function syncRole(Request $request, string $roleKey): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json(['message' => 'Forbidden. HR administrator only.'], 403);
        }

        if (! $this->rbacService->can($user, 'rbac.manage')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! collect(HrRole::cases())->contains(fn (HrRole $r) => $r->value === $roleKey)) {
            return response()->json(['message' => 'Invalid role.'], 422);
        }

        $validated = $request->validate([
            'permission_slugs' => ['required', 'array'],
            'permission_slugs.*' => ['string', 'max:120'],
        ]);

        $wanted = collect($validated['permission_slugs'])->unique()->values();
        $allSlugs = Permission::query()->pluck('slug');
        $invalid = $wanted->diff($allSlugs);
        if ($invalid->isNotEmpty()) {
            return response()->json([
                'message' => 'Unknown permission slugs.',
                'invalid' => $invalid->values()->all(),
            ], 422);
        }

        $this->applyRolePermissionSlugs($user, $roleKey, $wanted, 'rbac.sync');

        RbacService::forgetRoleCache($roleKey);

        return response()->json(['message' => 'Role permissions updated.', 'role_key' => $roleKey]);
    }

    /**
     * Restore a role's grants from config/rbac.php defaults. Audited.
     */
    public function resetRoleToDefaults(Request $request, string $roleKey): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isAdmin()) {
            return response()->json(['message' => 'Forbidden. HR administrator only.'], 403);
        }

        if (! $this->rbacService->can($user, 'rbac.manage')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! collect(HrRole::cases())->contains(fn (HrRole $r) => $r->value === $roleKey)) {
            return response()->json(['message' => 'Invalid role.'], 422);
        }

        $defaultsMap = config('rbac.default_role_permissions', []);
        if (! array_key_exists($roleKey, $defaultsMap)) {
            return response()->json(['message' => 'No default permissions defined for this role.'], 422);
        }

        $defaults = $defaultsMap[$roleKey];
        if ($defaults === ['*']) {
            $wanted = Permission::query()->pluck('slug');
        } else {
            $wanted = collect($defaults)->unique()->values();
        }

        $allSlugs = Permission::query()->pluck('slug');
        $invalid = $wanted->diff($allSlugs);
        if ($invalid->isNotEmpty()) {
            return response()->json([
                'message' => 'Default configuration references unknown permission slugs.',
                'invalid' => $invalid->values()->all(),
            ], 422);
        }

        $this->applyRolePermissionSlugs($user, $roleKey, $wanted, 'rbac.reset_defaults');

        RbacService::forgetRoleCache($roleKey);

        return response()->json([
            'message' => 'Role permissions restored to defaults.',
            'role_key' => $roleKey,
            'permission_slugs' => $wanted->values()->all(),
        ]);
    }

    private function applyRolePermissionSlugs(User $user, string $roleKey, Collection $wanted, string $auditSource): void
    {
        $wanted = $wanted->unique()->values();
        $permissionModels = Permission::query()->whereIn('slug', $wanted)->get()->keyBy('slug');

        DB::transaction(function () use ($user, $roleKey, $wanted, $permissionModels, $auditSource) {
            $existing = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_key', $roleKey)
                ->pluck('permissions.slug');

            $toGrant = $wanted->diff($existing);
            $toRevoke = $existing->diff($wanted);

            foreach ($toGrant as $slug) {
                $perm = $permissionModels->get($slug);
                if (! $perm) {
                    continue;
                }
                DB::table('role_permissions')->insert([
                    'role_key' => $roleKey,
                    'permission_id' => $perm->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->auditService->log($user, $roleKey, $perm, 'grant', request(), [
                    'source' => $auditSource,
                ]);
            }

            foreach ($toRevoke as $slug) {
                $perm = Permission::query()->where('slug', $slug)->first();
                if (! $perm) {
                    continue;
                }
                DB::table('role_permissions')
                    ->where('role_key', $roleKey)
                    ->where('permission_id', $perm->id)
                    ->delete();
                $this->auditService->log($user, $roleKey, $perm, 'revoke', request(), [
                    'source' => $auditSource,
                ]);
            }
        });
    }
}
