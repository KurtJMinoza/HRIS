<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RbacService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    public function resolveHrRole(User $user): HrRole
    {
        return $this->hrRoleResolver->resolve($user);
    }

    public function can(User $user, string $permissionSlug): bool
    {
        // Admin (HR) super-role: full module access (highest priority over org-head roles).
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return true;
        }

        return $this->getPermissionsForUser($user)->contains($permissionSlug);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    public function canAny(User $user, array $permissionSlugs): bool
    {
        foreach ($permissionSlugs as $slug) {
            if ($this->can($user, $slug)) {
                return true;
            }
        }

        return false;
    }

    /** Self-service loan submit: canonical {@see Permission} slug `request-loan`; legacy `loans.request` still honored. */
    public function canRequestLoan(User $user): bool
    {
        // Keep legacy/new slugs plus own-view fallback for environments with partially synced role grants.
        return $this->canAny($user, ['request-loan', 'loans.request', 'loans.view_own']);
    }

    /**
     * Effective permission slugs for API / UI (not including super-admin wildcard expansion in listing).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function getPermissionsForUser(User $user): \Illuminate\Support\Collection
    {
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return Permission::query()->orderBy('slug')->pluck('slug');
        }

        $roleKey = $this->roleKeyForUser($user);

        return Cache::remember(
            'rbac.permissions.'.$roleKey,
            3600,
            function () use ($roleKey) {
                return DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role_permissions.role_key', $roleKey)
                    ->orderBy('permissions.slug')
                    ->pluck('permissions.slug');
            }
        );
    }

    public function roleKeyForUser(User $user): string
    {
        return $this->hrRoleResolver->resolve($user)->value;
    }

    public static function forgetRoleCache(string $roleKey): void
    {
        Cache::forget('rbac.permissions.'.$roleKey);
    }

    public static function forgetAllRoleCache(): void
    {
        foreach (HrRole::cases() as $case) {
            Cache::forget('rbac.permissions.'.$case->value);
        }
    }
}
