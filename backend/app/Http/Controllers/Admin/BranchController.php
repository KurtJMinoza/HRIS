<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OrganizationLeadershipAssignmentService;
use App\Services\OrganizationLeadershipService;
use App\Support\BranchEmployeeCounts;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipService $organizationLeadershipService,
    ) {}

    /**
     * List all branches, optionally filtered by company_id.
     */
    public function index(Request $request): JsonResponse
    {
        $lite = $request->boolean('lite');

        $query = Branch::query()
            ->select('branches.*')
            ->with(['company:id,name,logo', 'area:id,area_name,company_id'])
            ->with('branchManager:id,name,first_name,middle_name,last_name,suffix,profile_image')
            ->withCount('departments');

        if (! $lite) {
            $employeeCounts = BranchEmployeeCounts::subquery();
            $query->leftJoinSub($employeeCounts, 'employee_counts', 'employee_counts.branch_id', '=', 'branches.id')
                ->addSelect(DB::raw('COALESCE(employee_counts.employees_count, 0) as employees_count'));
        }

        if ($request->filled('company_id')) {
            $query->where('branches.company_id', $request->input('company_id'));
        }

        $this->dataScopeService->restrictBranchQuery($request->user(), $query);

        $branches = $query->orderBy('branches.name')->get()->map(fn (Branch $b) => $this->branchResponse($b, $lite));

        return response()->json(['branches' => $branches]);
    }

    /**
     * Create a branch.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'address' => ['nullable', 'string', 'max:500'],
            'branch_manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $validated['name'] = trim((string) $validated['name']);
        $this->assertBranchNameIsUnique($validated['name'], (int) $validated['company_id']);

        $company = Company::findOrFail($validated['company_id']);
        if (! $company->logo || trim((string) $company->logo) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'company_id' => ['Please upload a Company logo before creating Branches and Departments.'],
            ]);
        }
        $this->assertAreaBelongsToCompany($validated['area_id'] ?? null, (int) $validated['company_id']);

        if (($validated['branch_manager_id'] ?? null) !== null) {
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['branch_manager_id']);
        }

        $branch = Branch::create([
            'name' => $validated['name'],
            'company_id' => $validated['company_id'],
            'area_id' => $validated['area_id'] ?? null,
            'address' => $validated['address'] ?? null,
            'branch_manager_id' => $validated['branch_manager_id'] ?? null,
        ]);

        if ($branch->branch_manager_id) {
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'branch',
                (int) $branch->id,
                (int) $branch->branch_manager_id,
            );
            EmployeeProfileCache::invalidate((int) $branch->branch_manager_id);
        }

        return response()->json([
            'message' => 'Branch created successfully.',
            'branch' => $this->branchResponse($branch->load(['company:id,name,logo', 'area:id,area_name,company_id', 'branchManager:id,name,first_name,middle_name,last_name,suffix,profile_image'])),
        ], 201);
    }

    /**
     * List departments under this branch.
     */
    public function departments(Request $request, int $id): JsonResponse
    {
        $branchQuery = Branch::query()->with('company:id,name,logo')->whereKey($id);
        $this->dataScopeService->restrictBranchQuery($request->user(), $branchQuery);
        $branch = $branchQuery->firstOrFail();
        $departments = $branch->departments()
            ->with('departmentHead:id,name,first_name,middle_name,last_name,suffix')
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'company_id' => $branch->company_id,
                'company_name' => $branch->company?->name,
                'company_logo_url' => $this->companyLogoUrl($branch->company?->logo),
                'logo_url' => $this->companyLogoUrl($branch->company?->logo),
                'office_location' => $d->office_location,
                'department_head_id' => $d->department_head_id,
                'department_head_name' => $d->departmentHead?->display_name,
                'employees_count' => $d->employees_count,
            ]);

        return response()->json([
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'company_id' => $branch->company_id,
                'company_name' => $branch->company?->name,
                'company_logo_url' => $this->companyLogoUrl($branch->company?->logo),
            ],
            'departments' => $departments,
        ]);
    }

    /**
     * Update a branch.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $branch = Branch::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'address' => ['nullable', 'string', 'max:500'],
            'branch_manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }
        $this->assertBranchNameIsUnique(
            (string) ($validated['name'] ?? $branch->name),
            (int) ($validated['company_id'] ?? $branch->company_id),
            (int) $branch->id
        );

        if (array_key_exists('company_id', $validated)) {
            $company = Company::findOrFail($validated['company_id']);
            if (! $company->logo || trim((string) $company->logo) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'company_id' => ['Please upload a Company logo before creating Branches and Departments.'],
                ]);
            }
        }
        if (array_key_exists('area_id', $validated)) {
            $this->assertAreaBelongsToCompany($validated['area_id'] ?? null, (int) ($validated['company_id'] ?? $branch->company_id));
        }

        if (array_key_exists('branch_manager_id', $validated)) {
            if (($validated['branch_manager_id'] ?? null) !== null) {
                $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['branch_manager_id']);
            }
        }

        $oldManagerId = $branch->branch_manager_id;

        $branch->fill($validated);
        $branch->save();

        if (array_key_exists('branch_manager_id', $validated)) {
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'branch',
                (int) $branch->id,
                $branch->branch_manager_id !== null ? (int) $branch->branch_manager_id : null,
                $oldManagerId !== null ? (int) $oldManagerId : null,
            );
            foreach (array_unique(array_filter([
                $oldManagerId ? (int) $oldManagerId : null,
                $branch->branch_manager_id ? (int) $branch->branch_manager_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }

        $refreshed = Branch::query()
            ->select('branches.*')
            ->with(['company:id,name,logo', 'area:id,area_name,company_id', 'branchManager:id,name,first_name,middle_name,last_name,suffix,profile_image'])
            ->withCount('departments')
            ->tap(function ($q) use ($branch) {
                $employeeCounts = BranchEmployeeCounts::subquery();
                $q->leftJoinSub($employeeCounts, 'employee_counts', 'employee_counts.branch_id', '=', 'branches.id')
                    ->addSelect(DB::raw('COALESCE(employee_counts.employees_count, 0) as employees_count'))
                    ->where('branches.id', $branch->id);
            })
            ->firstOrFail();

        return response()->json([
            'message' => 'Branch updated successfully.',
            'branch' => $this->branchResponse($refreshed),
        ]);
    }

    /**
     * Delete a branch. Blocked if it has departments.
     */
    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::findOrFail($id);

        if ($branch->departments()->exists()) {
            return response()->json([
                'message' => 'Cannot delete branch because it has departments. Remove or reassign departments first.',
            ], 422);
        }

        User::where('branch_id', $id)->update(['branch_id' => null, 'company_id' => null]);
        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    private function assertAreaBelongsToCompany(mixed $areaId, int $companyId): void
    {
        if ($areaId === null || $areaId === '') {
            return;
        }

        $area = Area::query()->find((int) $areaId);
        if (! $area || (int) $area->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'area_id' => ['The selected area must belong to the selected company.'],
            ]);
        }
    }

    private function assertBranchNameIsUnique(string $name, int $companyId, ?int $ignoreBranchId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Branch name is required.'],
            ]);
        }

        $normalized = mb_strtolower(trim($name));
        $query = Branch::query()
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]);

        if ($ignoreBranchId !== null) {
            $query->whereKeyNot($ignoreBranchId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A branch with this name already exists for the selected company.'],
            ]);
        }
    }

    private function branchResponse(Branch $b, bool $lite = false): array
    {
        $logoUrl = $this->companyLogoUrl($b->company?->logo);

        return [
            'id' => $b->id,
            'name' => $b->name,
            'company_id' => $b->company_id,
            'company_name' => $b->company?->name,
            'area_id' => $b->area_id,
            'area_name' => $b->area?->area_name,
            'logo' => $b->company?->logo,
            'logo_url' => $logoUrl,
            'address' => $b->address,
            'branch_manager_id' => $b->branch_manager_id,
            'branch_manager_name' => $b->branchManager?->display_name,
            'branch_manager_profile_image' => $this->companyLogoUrl($b->branchManager?->profile_image),
            'departments_count' => (int) ($b->departments_count ?? 0),
            'employees_count' => $lite
                ? null
                : (int) ($b->employees_count ?? 0),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }

    private function companyLogoUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $normalized = ltrim(trim($path), '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, 7), '/');
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));

        return '/api/media/public/'.$encoded;
    }
}
