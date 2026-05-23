<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\OrgHierarchyNormalizer;
use App\Services\OrganizationLeadershipAssignmentService;
use App\Services\OrganizationLeadershipService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrgHierarchyNormalizer $orgHierarchyNormalizer,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipService $organizationLeadershipService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
    ) {}

    private const LOGO_DISK = 'public';

    private const LOGO_DIR = 'department-logos';

    private const LOGO_MAX_KB = 2048; // 2MB

    private const LOGO_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    /**
     * List all departments with total employees, department head, and logo URL.
     * Optional filter: ?branch_id=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::with('departmentHead:id,name,first_name,middle_name,last_name,suffix,profile_image')
            ->with('branch:id,name,company_id')
            ->with('branch.company:id,name,logo')
            ->with('division:id,name,company_id,branch_id')
            ->withCount('employees');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        if ($request->filled('division_id')) {
            $query->where('division_id', $request->input('division_id'));
        }

        if ($request->filled('company_id')) {
            $query->where(function ($q) use ($request): void {
                $q->where('company_id', $request->input('company_id'))
                    ->orWhereHas('branch', fn ($b) => $b->where('company_id', $request->input('company_id')));
            });
        }

        $this->dataScopeService->restrictDepartmentQuery($request->user(), $query);

        $departments = $query->orderBy('name')
            ->get()
            ->map(fn (Department $d) => $this->departmentResponse($d));

        return response()->json(['departments' => $departments]);
    }

    /**
     * Create a department (name + optional logo). Logo: JPG, PNG, WebP; max 2MB.
     * Name is restricted to standard letters/numbers to prevent emojis/symbol spam.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:departments,name',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'division_id' => ['required', 'integer', 'exists:divisions,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'office_location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.regex' => 'Department name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        [$companyId, $branchId, $divisionId] = $this->orgHierarchyNormalizer->normalizeDepartmentScope($validated);
        $branch = \App\Models\Branch::with('company')->findOrFail($branchId);
        if (! $branch->company?->logo || trim((string) $branch->company->logo) === '') {
            throw ValidationException::withMessages([
                'branch_id' => ['Please upload a Company logo before creating Branches and Departments.'],
            ]);
        }

        $department = Department::create([
            'name' => $validated['name'],
            'branch_id' => $branchId,
            'company_id' => $companyId,
            'division_id' => $divisionId,
            'office_location' => isset($validated['office_location']) && trim((string) $validated['office_location']) !== '' ? trim((string) $validated['office_location']) : null,
            'description' => isset($validated['description']) && trim((string) $validated['description']) !== '' ? trim((string) $validated['description']) : null,
            'logo' => null,
        ]);

        $department->load([
            'branch:id,name,company_id',
            'branch.company:id,name,logo',
            'division:id,name,company_id,branch_id',
        ]);
        $department->loadCount('employees');

        return response()->json([
            'message' => 'Department created successfully.',
            'department' => $this->departmentResponse($department),
        ], 201);
    }

    /**
     * List employees in this department (for View Employees). Returns id, name, profile_image URL.
     */
    public function employees(Request $request, int $id): JsonResponse
    {
        $deptScope = Department::query()->whereKey($id);
        $this->dataScopeService->restrictDepartmentQuery($request->user(), $deptScope);
        $department = $deptScope->firstOrFail();
        // List everyone with users.department_id = this department. Do not apply
        // restrictEmployeeQuery here — org-hat exclusions are for cross-department rosters;
        // membership for this screen must match Assign Employees (department_id FK).
        $sharedEmployeeIds = EmployeeOrganizationAssignment::query()
            ->active()
            ->where('department_id', (int) $department->id)
            ->pluck('employee_id')
            ->map(fn ($employeeId) => (int) $employeeId)
            ->all();

        $employees = User::query()
            ->visibleEmployees()
            ->where(function ($query) use ($department, $sharedEmployeeIds): void {
                $query->where('department_id', (int) $department->id);
                if ($sharedEmployeeIds !== []) {
                    $query->orWhereIn('id', $sharedEmployeeIds);
                }
            })
            ->orderByLastName()
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_image'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->display_name,
                'formatted_name' => $u->formatted_name,
                'profile_image' => $u->profile_image_url,
            ]);

        return response()->json([
            'department' => ['id' => $department->id, 'name' => $department->name],
            'employee_count' => $employees->count(),
            'employees' => $employees,
        ]);
    }

    /**
     * Update department (name, department head, and/or logo).
     * Logo: JPG, PNG, WebP; max 2MB. Send as multipart form when updating logo.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'unique:departments,name,'.$id,
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'office_location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.regex' => 'Department name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        if (array_key_exists('department_head_id', $validated) && $validated['department_head_id'] !== null) {
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['department_head_id']);
        }

        if (isset($validated['name'])) {
            $department->name = $validated['name'];
            $department->save();
            User::where('department_id', $department->id)->update(['department' => $department->name]);
        }

        if (array_key_exists('division_id', $validated) || array_key_exists('branch_id', $validated) || array_key_exists('company_id', $validated)) {
            $merged = array_merge($department->only(['company_id', 'branch_id', 'division_id']), $validated);
            [$companyId, $branchId, $divisionId] = $this->orgHierarchyNormalizer->normalizeDepartmentScope($merged);
            $department->company_id = $companyId;
            $department->branch_id = $branchId;
            $department->division_id = $divisionId;
            $department->save();
        } elseif (array_key_exists('branch_id', $validated)) {
            $branch = \App\Models\Branch::with('company')->findOrFail($validated['branch_id']);
            if (! $branch->company?->logo || trim((string) $branch->company->logo) === '') {
                throw ValidationException::withMessages([
                    'branch_id' => ['Please upload a Company logo before creating Branches and Departments.'],
                ]);
            }
            $department->branch_id = $validated['branch_id'];
            $department->company_id = $branch->company_id;
            $department->save();
        }

        if (array_key_exists('department_head_id', $validated)) {
            $previousHeadId = $department->department_head_id;
            $department->department_head_id = $validated['department_head_id'];
            $department->save();
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'department',
                (int) $department->id,
                $validated['department_head_id'] !== null ? (int) $validated['department_head_id'] : null,
                $previousHeadId !== null ? (int) $previousHeadId : null,
            );
            foreach (array_unique(array_filter([
                $previousHeadId ? (int) $previousHeadId : null,
                $department->department_head_id ? (int) $department->department_head_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }

        if (array_key_exists('office_location', $validated)) {
            $department->office_location = is_string($validated['office_location']) && trim($validated['office_location']) !== ''
                ? trim($validated['office_location'])
                : null;
            $department->save();
        }

        if (array_key_exists('description', $validated)) {
            $department->description = is_string($validated['description']) && trim($validated['description']) !== ''
                ? trim($validated['description'])
                : null;
            $department->save();
        }

        $departmentFresh = $department->fresh([
            'departmentHead:id,name,profile_image',
            'branch:id,name,company_id',
            'branch.company:id,name,logo',
        ]);
        if ($departmentFresh) {
            $departmentFresh->loadCount('employees');
        }

        return response()->json([
            'message' => 'Department updated successfully.',
            'department' => $this->departmentResponse($departmentFresh ?? $department),
        ]);
    }

    /**
     * Assign employees to this department.
     * Supports cross-company shared assignments and primary transfers.
     */
    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'assignment_mode' => ['nullable', 'string', 'in:shared,transfer_primary'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $users = User::with(['company', 'branch', 'departmentRelation.branch', 'companyHeadships'])
            ->whereIn('id', $validated['employee_ids'])
            ->visibleEmployees()
            ->get();

        // Block employees who are Company Heads from being assigned to a department
        $companyHeadIds = \App\Models\Company::whereIn('company_head_id', $validated['employee_ids'])
            ->pluck('company_head_id')
            ->map(fn ($headId) => (int) $headId)
            ->toArray();
        if (count($companyHeadIds) > 0) {
            $headNames = $users->whereIn('id', $companyHeadIds)->map(fn (User $user) => $user->display_name)->toArray();
            throw ValidationException::withMessages([
                'employee_ids' => [
                    'The following employees are assigned as Company Heads and cannot be assigned to a department: '.implode(', ', $headNames).'.',
                ],
            ]);
        }

        // Block employees who are Branch Managers from being assigned to a department
        $branchManagerIds = \App\Models\Branch::whereIn('branch_manager_id', $validated['employee_ids'])
            ->pluck('branch_manager_id')
            ->map(fn ($managerId) => (int) $managerId)
            ->unique()
            ->values()
            ->toArray();
        if (count($branchManagerIds) > 0) {
            $managerNames = $users->whereIn('id', $branchManagerIds)->map(fn (User $user) => $user->display_name)->toArray();
            throw ValidationException::withMessages([
                'employee_ids' => [
                    'The following employees are Branch Managers and cannot be assigned to a department: '.implode(', ', $managerNames).'. A Branch Manager holds a managerial role and cannot also serve as a department member.',
                ],
            ]);
        }

        $assignmentMode = $validated['assignment_mode'] ?? EmployeeOrganizationAssignmentService::MODE_TRANSFER_PRIMARY;
        $result = $this->organizationAssignments->assignToLegacyUnit(
            'department',
            (int) $department->id,
            $validated['employee_ids'],
            $assignmentMode,
            $validated['remarks'] ?? null,
        );

        return response()->json([
            'message' => $this->organizationAssignments->assignResultMessage($result),
            'added_count' => $result['added_count'],
            'skipped_existing_count' => $result['skipped_existing_count'],
            'skipped_existing_names' => $result['skipped_existing_names'],
            'final_assigned_count' => $result['final_assigned_count'],
            'department' => $this->departmentResponse($department->fresh([
                'departmentHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'branch:id,name,company_id',
                'branch.company:id,name,logo',
            ])->loadCount('employees')),
        ]);
    }

    /**
     * Unassign employees from this department (sets department_id and department to null).
     */
    public function unassignEmployees(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $this->organizationAssignments->unassignFromLegacyUnit(
            'department',
            (int) $department->id,
            $validated['employee_ids'],
        );

        return response()->json([
            'message' => 'Employees unassigned successfully.',
            'department' => $this->departmentResponse($department->fresh([
                'departmentHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'branch:id,name,company_id',
                'branch.company:id,name,logo',
            ])->loadCount('employees')),
        ]);
    }

    /**
     * Delete a department. Blocked if it has teams. Unassigns employees and removes logo from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        if ($department->teams()->exists()) {
            return response()->json([
                'message' => 'Cannot delete department because it has teams. Remove or reassign teams first.',
            ], 422);
        }

        if ($department->logo && Storage::disk(self::LOGO_DISK)->exists($department->logo)) {
            Storage::disk(self::LOGO_DISK)->delete($department->logo);
        }
        User::where('department_id', $id)->update(['department_id' => null, 'department' => null, 'branch_id' => null, 'company_id' => null]);
        $department->delete();

        return response()->json(['message' => 'Department deleted successfully.']);
    }

    private function departmentResponse(Department $d): array
    {
        // Logo always comes from Company (single source of truth). Department does not store its own logo.
        $companyLogo = $d->branch?->company?->logo ?? null;
        $logoUrl = $companyLogo ? $this->publicMediaUrl($companyLogo) : null;

        return [
            'id' => $d->id,
            'name' => $d->name,
            'branch_id' => $d->branch_id,
            'branch_name' => $d->branch?->name,
            'company_id' => $d->company_id ?? $d->branch?->company?->id,
            'company_name' => $d->branch?->company?->name,
            'division_id' => $d->division_id,
            'division_name' => $d->division?->name,
            'hierarchy_mismatch' => (bool) ($d->hierarchy_mismatch ?? false),
            'office_location' => $d->office_location,
            'description' => $d->description,
            'logo' => $companyLogo,
            'logo_url' => $logoUrl,
            'total_employees' => $d->employees_count ?? $d->employees()->count(),
            'department_head_id' => $d->department_head_id,
            'department_head_name' => $d->departmentHead?->display_name,
            'department_head_profile_image' => $d->departmentHead?->profile_image_url ?? null,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        // Skip Storage::exists() check on list endpoints — filesystem I/O per row is too costly.
        // The frontend handles missing images gracefully with initials fallbacks.
        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }

    /**
     * Store company logo and guarantee a valid storage path is returned.
     */
    private function storeLogoOrFail(Request $request): string
    {
        $file = $request->file('logo');
        if (! $file) {
            throw ValidationException::withMessages([
                'logo' => ['No logo file was uploaded.'],
            ]);
        }

        Storage::disk(self::LOGO_DISK)->makeDirectory(self::LOGO_DIR);
        $path = Storage::disk(self::LOGO_DISK)->putFile(self::LOGO_DIR, $file);
        if (! is_string($path) || trim($path) === '') {
            throw ValidationException::withMessages([
                'logo' => ['Failed to store company logo. Please check storage permissions and try again.'],
            ]);
        }

        return $path;
    }
}
