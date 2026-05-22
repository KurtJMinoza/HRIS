<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RbacService
{
    private const MANAGEMENT_PERMISSION_FLAGS = [
        'dashboard.view' => 'can_view_admin_dashboard',
        'employees.view' => 'can_view_employee_module',
        'attendance.view' => 'can_view_subordinate_attendance',
    ];

    /** Legacy report slugs resolved via {@see self::canAccessReportsModule()}. */
    private const REPORTS_PERMISSION_SLUGS = [
        'reports.view',
        'reports.export',
        'reports.payroll',
    ];

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

        if (in_array($permissionSlug, self::REPORTS_PERMISSION_SLUGS, true)) {
            return match ($permissionSlug) {
                'reports.payroll' => $this->canAccessReportsModule($user)
                    && ($this->canViewAllReports($user) || $this->canViewSubordinateReports($user)),
                default => $this->canAccessReportsModule($user),
            };
        }

        if ($permissionSlug === 'can_access_reports_module') {
            return $this->canAccessReportsModule($user);
        }

        if ($permissionSlug === 'can_view_all_reports') {
            return $this->canViewAllReports($user);
        }

        if ($permissionSlug === 'can_view_own_reports') {
            return $this->canViewOwnReports($user);
        }

        if ($permissionSlug === 'can_view_subordinate_reports') {
            return $this->canViewSubordinateReports($user);
        }

        if (array_key_exists($permissionSlug, self::MANAGEMENT_PERMISSION_FLAGS)) {
            return $this->rawPermissionsForUser($user)->contains(self::MANAGEMENT_PERMISSION_FLAGS[$permissionSlug]);
        }

        return $this->getPermissionsForUser($user)->contains($permissionSlug);
    }

    public function canAccessReportsModule(User $user): bool
    {
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return true;
        }

        if ($user->isExcludedFromReports()) {
            return false;
        }

        $raw = $this->rawPermissionsForUser($user);

        return $raw->contains('can_access_reports_module')
            || $raw->contains('can_view_all_reports')
            || $raw->contains('can_view_subordinate_reports')
            || $raw->contains('can_view_own_reports');
    }

    public function canViewOwnReports(User $user): bool
    {
        if ($user->isExcludedFromReports()) {
            return false;
        }

        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return true;
        }

        $raw = $this->rawPermissionsForUser($user);

        return $raw->contains('can_view_own_reports')
            || $raw->contains('can_access_reports_module')
            || $raw->contains('can_view_all_reports')
            || $raw->contains('can_view_subordinate_reports');
    }

    public function canViewSubordinateReports(User $user): bool
    {
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return true;
        }

        return $this->rawPermissionsForUser($user)->contains('can_view_subordinate_reports');
    }

    public function canViewAllReports(User $user): bool
    {
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return true;
        }

        return $this->rawPermissionsForUser($user)->contains('can_view_all_reports');
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

        $raw = $this->rawPermissionsForUser($user);
        $managementSlugs = array_keys(self::MANAGEMENT_PERMISSION_FLAGS);

        return $raw->reject(function (string $slug) use ($raw, $managementSlugs): bool {
            if (! in_array($slug, $managementSlugs, true)) {
                return false;
            }

            return ! $raw->contains(self::MANAGEMENT_PERMISSION_FLAGS[$slug]);
        })->values();
    }

    public function accessFlagsForUser(User $user): array
    {
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return [
                'can_view_employee_module' => true,
                'can_view_subordinate_attendance' => true,
                'can_view_subordinate_reports' => true,
                'can_view_admin_dashboard' => true,
                'can_approve_requests' => true,
                'can_view_my_filings' => true,
                'can_view_assigned_approvals' => true,
                'can_view_team_filings' => true,
                'can_view_own_attendance' => ! $user->isExcludedFromAttendance(),
                'can_access_reports_module' => ! $user->isExcludedFromReports(),
                'can_view_own_reports' => ! $user->isExcludedFromReports(),
                'can_view_all_reports' => true,
            ];
        }

        $raw = $this->rawPermissionsForUser($user);

        return [
            'can_view_employee_module' => $raw->contains('can_view_employee_module'),
            'can_view_subordinate_attendance' => $raw->contains('can_view_subordinate_attendance'),
            'can_view_subordinate_reports' => $raw->contains('can_view_subordinate_reports'),
            'can_view_admin_dashboard' => $raw->contains('can_view_admin_dashboard'),
            'can_approve_requests' => $raw->intersect([
                'leave.approve',
                'overtime.approve',
                'attendance.corrections.approve',
                'approve-schedule',
            ])->isNotEmpty(),
            'can_view_my_filings' => $raw->contains('can_view_my_filings'),
            'can_view_assigned_approvals' => $raw->contains('can_view_assigned_approvals'),
            'can_view_team_filings' => $raw->contains('can_view_team_filings'),
            'can_view_own_attendance' => ! $user->isExcludedFromAttendance(),
            'can_access_reports_module' => $this->canAccessReportsModule($user),
            'can_view_own_reports' => $this->canViewOwnReports($user),
            'can_view_all_reports' => $this->canViewAllReports($user),
        ];
    }

    public function rawPermissionsForUser(User $user): \Illuminate\Support\Collection
    {
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
