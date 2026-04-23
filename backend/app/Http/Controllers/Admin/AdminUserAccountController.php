<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\HrRoleResolver;
use App\Services\RbacService;
use App\Services\UserRoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserAccountController extends Controller
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly RbacService $rbacService,
        private readonly UserRoleAssignmentService $roleAssignmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $q = trim((string) $request->query('q', ''));
        $roleFilter = $request->query('hr_role');
        $isActive = $request->query('is_active');
        $departmentId = $request->query('department_id');

        $query = User::query()->orderBy('name');

        if ($departmentId !== null && $departmentId !== '') {
            $query->where('department_id', (int) $departmentId);
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('employee_code', 'like', $like);
            });
        }

        if ($isActive !== null && $isActive !== '') {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if (is_string($roleFilter) && $roleFilter !== '') {
            $this->applyHrRoleFilter($query, $roleFilter);
        }

        $paginator = $query->with(['departmentRelation:id,name'])->paginate($perPage);

        $items = collect($paginator->items())->map(function (User $u) {
            return $this->serializeUser($u);
        })->values();

        return response()->json([
            'users' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'hr_role' => ['required', 'string', Rule::in(array_map(fn (HrRole $r) => $r->value, HrRole::cases()))],
            'is_hr_admin' => ['sometimes', 'boolean'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $hrRole = HrRole::from($validated['hr_role']);
        $isHrAdmin = (bool) ($validated['is_hr_admin'] ?? false);
        if ($hrRole === HrRole::AdminHr) {
            $isHrAdmin = true;
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $this->assertAssignable($actor, $hrRole, $isHrAdmin);
        $this->validateRoleContext($hrRole, $validated, $isHrAdmin);

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => trim($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $this->roleAssignmentService->applyRole(
            $user,
            $hrRole,
            $validated['company_id'] ?? null,
            $validated['branch_id'] ?? null,
            $validated['department_id'] ?? null,
            $isHrAdmin,
        );

        $user->refresh();
        $this->logActivity($actor, $user, 'user.created', ['hr_role' => $hrRole->value]);

        return response()->json([
            'message' => 'User created.',
            'user' => $this->serializeUser($user),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        if ($actor->id === $user->id) {
            // allow self name/email only; role changes blocked below
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'is_active' => ['sometimes', 'boolean'],
            'hr_role' => ['sometimes', 'string', Rule::in(array_map(fn (HrRole $r) => $r->value, HrRole::cases()))],
            'is_hr_admin' => ['sometimes', 'boolean'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $assignmentTouched = array_key_exists('hr_role', $validated)
            || array_key_exists('is_hr_admin', $validated);

        if ($assignmentTouched) {
            if ($actor->id === $user->id) {
                throw ValidationException::withMessages([
                    'hr_role' => ['You cannot change your own HR role or Admin (HR) access. Ask another administrator.'],
                ]);
            }

            $target = isset($validated['hr_role'])
                ? HrRole::from($validated['hr_role'])
                : $this->deriveDefaultHrRoleForAssignmentUpdate($user);

            $isHrAdmin = array_key_exists('is_hr_admin', $validated)
                ? (bool) $validated['is_hr_admin']
                : $user->isAdmin();
            if ($target === HrRole::AdminHr) {
                $isHrAdmin = true;
            }

            // Keep existing org context when only toggling Admin (HR) and no explicit org ids are posted.
            $roleValidationInput = $validated;
            if (! array_key_exists('company_id', $roleValidationInput)) {
                $roleValidationInput['company_id'] = $user->company_id;
            }
            if (! array_key_exists('branch_id', $roleValidationInput)) {
                $roleValidationInput['branch_id'] = $user->branch_id;
            }
            if (! array_key_exists('department_id', $roleValidationInput)) {
                $roleValidationInput['department_id'] = $user->department_id;
            }

            $this->assertAssignable($actor, $target, $isHrAdmin);
            $this->validateRoleContext($target, $roleValidationInput, $isHrAdmin);
            $this->roleAssignmentService->applyRole(
                $user,
                $target,
                $validated['company_id'] ?? null,
                $validated['branch_id'] ?? null,
                $validated['department_id'] ?? null,
                $isHrAdmin,
            );
        } elseif ($user->isAdmin()) {
            $orgTouched = array_key_exists('company_id', $validated)
                || array_key_exists('branch_id', $validated)
                || array_key_exists('department_id', $validated);
            if ($orgTouched) {
                if ($this->hrRoleResolver->isAssignedOrganizationHead($user)) {
                    throw ValidationException::withMessages([
                        'company_id' => ['This user has a company / branch / department head assignment. Change organization via a full role update (hr_role, is_hr_admin, and org fields).'],
                    ]);
                }
                $c = array_key_exists('company_id', $validated) ? $validated['company_id'] : $user->company_id;
                $b = array_key_exists('branch_id', $validated) ? $validated['branch_id'] : $user->branch_id;
                $d = array_key_exists('department_id', $validated) ? $validated['department_id'] : $user->department_id;
                $this->validateOptionalAdminHrOrgScope($c, $b, $d);
                $this->roleAssignmentService->applyAdminHrOrganizationScope($user, $c, $b, $d);
            }
        }

        if (isset($validated['name'])) {
            $user->name = trim($validated['name']);
        }
        if (isset($validated['email'])) {
            $user->email = trim($validated['email']);
        }
        if (array_key_exists('is_active', $validated)) {
            if ($actor->id === $user->id && $validated['is_active'] === false) {
                throw ValidationException::withMessages([
                    'is_active' => ['You cannot deactivate your own account.'],
                ]);
            }
            $this->assertNotLastAdminDeactivation($user, (bool) $validated['is_active']);
            $user->is_active = (bool) $validated['is_active'];
        }

        $user->save();
        $user->refresh();

        $this->logActivity($actor, $user, 'user.updated', array_intersect_key($validated, array_flip(['name', 'email', 'is_active', 'hr_role'])));

        return response()->json([
            'message' => 'User updated.',
            'user' => $this->serializeUser($user),
        ]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->password = Hash::make($validated['password']);
        $user->save();

        $this->logActivity($actor, $user, 'user.password_reset', []);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Bulk activate or deactivate accounts (same rules as single update).
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['activate', 'deactivate'])],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $active = $validated['action'] === 'activate';
        $ids = array_values(array_unique($validated['user_ids']));

        foreach ($ids as $id) {
            $user = User::query()->findOrFail($id);
            if ($actor->id === $user->id && ! $active) {
                throw ValidationException::withMessages([
                    'user_ids' => ['You cannot deactivate your own account.'],
                ]);
            }
            $this->assertNotLastAdminDeactivation($user, $active);
            $user->is_active = $active;
            $user->save();
            $this->logActivity($actor, $user, $active ? 'user.bulk_activated' : 'user.bulk_deactivated', []);
        }

        return response()->json([
            'message' => $active ? 'Users activated.' : 'Users deactivated.',
            'updated' => count($ids),
        ]);
    }

    public function activity(Request $request, int $id): JsonResponse
    {
        User::query()->findOrFail($id);

        $logs = UserAdminActivityLog::query()
            ->where('subject_user_id', $id)
            ->with(['actor:id,name,email'])
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        return response()->json(['logs' => $logs]);
    }

    /**
     * HR org roles cannot assign ADMIN or higher scope than themselves (least privilege).
     */
    /**
     * @param  bool  $isHrAdmin  Laravel administrator (Admin HR); may combine with org head roles.
     */
    private function validateRoleContext(HrRole $hrRole, array $validated, bool $isHrAdmin = false): void
    {
        $companyId = $validated['company_id'] ?? null;
        $branchId = $validated['branch_id'] ?? null;
        $departmentId = $validated['department_id'] ?? null;

        switch ($hrRole) {
            case HrRole::CompanyHead:
                if (empty($companyId)) {
                    throw ValidationException::withMessages(['company_id' => ['Company is required for COMPANY HEAD.']]);
                }
                break;
            case HrRole::BranchHead:
                if (empty($branchId)) {
                    throw ValidationException::withMessages(['branch_id' => ['Branch is required for BRANCH HEAD.']]);
                }
                break;
            case HrRole::DepartmentHead:
                if (empty($departmentId)) {
                    throw ValidationException::withMessages(['department_id' => ['Department is required for DEPARTMENT HEAD.']]);
                }
                break;
            case HrRole::AdminHr:
                $this->validateOptionalAdminHrOrgScope(
                    $validated['company_id'] ?? null,
                    $validated['branch_id'] ?? null,
                    $validated['department_id'] ?? null,
                );
                break;
            case HrRole::Employee:
                if ($isHrAdmin && ($companyId !== null || $branchId !== null || $departmentId !== null)) {
                    $this->validateOptionalAdminHrOrgScope($companyId, $branchId, $departmentId);
                }
                break;
            default:
                break;
        }
    }

    /**
     * When toggling only {@see is_hr_admin}, keep the same organizational role from org tables / FKs.
     */
    private function deriveDefaultHrRoleForAssignmentUpdate(User $user): HrRole
    {
        $org = $this->hrRoleResolver->resolveOrganizationalRole($user);
        if ($user->isAdmin() && $org === HrRole::Employee) {
            return HrRole::AdminHr;
        }

        return $org;
    }

    /**
     * Consistency rules for scoped Admin (HR): department wins, then branch, then company.
     */
    private function validateOptionalAdminHrOrgScope(?int $companyId, ?int $branchId, ?int $departmentId): void
    {
        if ($departmentId === null && $branchId === null && $companyId === null) {
            return;
        }

        if ($departmentId !== null) {
            $dept = Department::query()->with('branch')->find($departmentId);
            if (! $dept) {
                throw ValidationException::withMessages(['department_id' => ['Invalid department.']]);
            }
            if ($branchId !== null && (int) $branchId !== (int) $dept->branch_id) {
                throw ValidationException::withMessages(['branch_id' => ['Branch does not match the selected department.']]);
            }
            $cid = $dept->branch?->company_id;
            if ($companyId !== null && $cid !== null && (int) $companyId !== (int) $cid) {
                throw ValidationException::withMessages(['company_id' => ['Company does not match the selected department.']]);
            }

            return;
        }

        if ($branchId !== null) {
            $branch = Branch::query()->find($branchId);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => ['Invalid branch.']]);
            }
            if ($companyId !== null && (int) $companyId !== (int) $branch->company_id) {
                throw ValidationException::withMessages(['company_id' => ['Company does not match the selected branch.']]);
            }
        } elseif ($companyId !== null) {
            if (! Company::query()->whereKey($companyId)->exists()) {
                throw ValidationException::withMessages(['company_id' => ['Invalid company.']]);
            }
        }
    }

    private function assertAssignable(User $actor, HrRole $target, bool $grantsLaravelAdmin = false): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($grantsLaravelAdmin) {
            abort(403, 'Only HR administrators can grant Admin (HR) access.');
        }

        $actorRole = $this->hrRoleResolver->resolve($actor);
        $order = [
            HrRole::Employee->value => 0,
            HrRole::DepartmentHead->value => 1,
            HrRole::BranchHead->value => 2,
            HrRole::CompanyHead->value => 3,
            HrRole::AdminHr->value => 4,
        ];

        if ($target === HrRole::AdminHr) {
            abort(403, 'Only HR administrators can assign the ADMIN (HR) role.');
        }

        $t = $order[$target->value] ?? 0;
        $a = $order[$actorRole->value] ?? 0;
        if ($t > $a) {
            abort(403, 'You cannot assign a role higher than your own.');
        }
    }

    private function assertNotLastAdminDeactivation(User $user, bool $active): void
    {
        if ($active || ! $user->isAdmin()) {
            return;
        }

        $count = User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count();
        if ($count <= 1) {
            throw ValidationException::withMessages([
                'is_active' => ['Cannot deactivate the last active administrator.'],
            ]);
        }
    }

    private function applyHrRoleFilter($query, string $roleFilter): void
    {
        match ($roleFilter) {
            'admin_hr' => $query->where('role', User::ROLE_ADMIN),
            'company_head' => $query->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('companies')
                    ->whereColumn('companies.company_head_id', 'users.id')),
            'branch_head' => $query->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('branches')
                    ->whereColumn('branches.branch_manager_id', 'users.id')),
            'department_head' => $query->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('departments')
                    ->whereColumn('departments.department_head_id', 'users.id')),
            'employee' => $query->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('companies')->whereColumn('companies.company_head_id', 'users.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('branches')->whereColumn('branches.branch_manager_id', 'users.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('departments')->whereColumn('departments.department_head_id', 'users.id')),
            default => null,
        };
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing([
            'companyHeadships:id,name,company_head_id',
            'company:id,name',
            'branch:id,name,company_id',
            'departmentRelation:id,name,branch_id',
        ]);

        $hr = $this->hrRoleResolver->resolve($user);
        $hrList = $this->hrRoleResolver->listEffectiveHrRoles($user);

        return [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_hr_admin' => $user->isAdmin(),
            'hr_role' => $hr->value,
            'hr_role_label' => $hr->badgeLabel(),
            'hr_roles' => array_map(fn (HrRole $r) => $r->value, $hrList),
            'hr_roles_labels' => array_map(fn (HrRole $r) => $r->badgeLabel(), $hrList),
            'is_active' => (bool) $user->is_active,
            'is_super_admin' => (bool) $user->is_super_admin,
            'company_id' => $user->company_id,
            'branch_id' => $user->branch_id,
            'department_id' => $user->department_id,
            'department_name' => $user->departmentRelation?->name,
            'hr_admin_scoped' => $user->hasScopedHrAdminAssignment(),
            'hr_admin_scope_label' => $user->hasScopedHrAdminAssignment()
                ? ($user->department_id !== null
                    ? $user->departmentRelation?->name
                    : ($user->branch_id !== null
                        ? $user->branch?->name
                        : $user->company?->name))
                : null,
            'profile_image_url' => $user->profile_image_url,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function logActivity(User $actor, User $subject, string $action, array $meta): void
    {
        UserAdminActivityLog::query()->create([
            'subject_user_id' => $subject->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'meta' => $meta ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}
