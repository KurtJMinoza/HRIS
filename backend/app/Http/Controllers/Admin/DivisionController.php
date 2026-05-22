<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\OrgUnitEmployeeCounter;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\OrgHierarchyNormalizer;
use App\Services\OrganizationLeadershipAssignmentService;
use App\Services\OrganizationLeadershipService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DivisionController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrgUnitEmployeeCounter $orgUnitEmployeeCountService,
        private readonly OrgHierarchyNormalizer $orgHierarchyNormalizer,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipService $organizationLeadershipService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Division::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'divisionHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
            ])
            ->withCount(['employees', 'sectionsOrUnits', 'departments']);

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $this->dataScopeService->restrictDivisionQuery($request->user(), $query);

        $divisions = $query->orderBy('name')->get();
        $countsById = $this->divisionEmployeeCounts($divisions);

        return response()->json([
            'divisions' => $divisions->map(
                fn (Division $division) => $this->divisionResponse(
                    $division,
                    $countsById[(int) $division->id] ?? null,
                ),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        [$companyId, $branchId] = $this->orgHierarchyNormalizer->normalizeDivisionScope($validated);

        $this->dataScopeService->assertCanCreateEmployeeInOrg($request->user(), $companyId, $branchId, null);

        $division = Division::query()->create([
            'name' => trim((string) $validated['name']),
            'code' => $this->nullableTrim($validated['code'] ?? null),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_head_id' => null,
            'status' => $validated['status'] ?? 'active',
            'description' => $this->nullableTrim($validated['description'] ?? null),
        ]);

        if (($validated['division_head_id'] ?? null) !== null) {
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['division_head_id']);
            $division->division_head_id = (int) $validated['division_head_id'];
            $division->save();
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'division',
                (int) $division->id,
                (int) $validated['division_head_id'],
            );
            EmployeeProfileCache::invalidate((int) $validated['division_head_id']);
        }

        return response()->json([
            'message' => 'Division created successfully.',
            'division' => $this->divisionResponse($division->fresh($this->responseRelations())->loadCount(['employees', 'sectionsOrUnits'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        $validated = $this->validatedPayload($request, $id, partial: true);

        $orgChanged = array_key_exists('company_id', $validated)
            || array_key_exists('branch_id', $validated);

        if ($orgChanged) {
            $merged = array_merge($division->only(['company_id', 'branch_id']), $validated);
            [$companyId, $branchId] = $this->orgHierarchyNormalizer->normalizeDivisionScope($merged);
            $division->company_id = $companyId;
            $division->branch_id = $branchId;
        }

        if (array_key_exists('name', $validated)) {
            $division->name = trim((string) $validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $division->code = $this->nullableTrim($validated['code']);
        }
        if (array_key_exists('status', $validated)) {
            $division->status = $validated['status'];
        }
        if (array_key_exists('description', $validated)) {
            $division->description = $this->nullableTrim($validated['description']);
        }
        if (array_key_exists('division_head_id', $validated)) {
            $previousHeadId = $division->division_head_id;
            if ($validated['division_head_id'] !== null) {
                $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['division_head_id']);
            }
            $division->division_head_id = $validated['division_head_id'];
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'division',
                (int) $division->id,
                $validated['division_head_id'] !== null ? (int) $validated['division_head_id'] : null,
                $previousHeadId !== null ? (int) $previousHeadId : null,
            );
            foreach (array_unique(array_filter([
                $previousHeadId ? (int) $previousHeadId : null,
                $division->division_head_id ? (int) $division->division_head_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }

        $division->save();

        return response()->json([
            'message' => 'Division updated successfully.',
            'division' => $this->divisionResponse($division->fresh($this->responseRelations())->loadCount(['employees', 'sectionsOrUnits'])),
        ]);
    }

    public function employees(Request $request, int $id): JsonResponse
    {
        $scope = Division::query()->whereKey($id);
        $this->dataScopeService->restrictDivisionQuery($request->user(), $scope);
        $division = $scope->firstOrFail();

        $memberQuery = $this->orgUnitEmployeeCountService->divisionMembersQuery((int) $division->id);
        $employees = (clone $memberQuery)
            ->orderByLastName()
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_image'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->display_name,
                'formatted_name' => $user->formatted_name,
                'profile_image' => $user->profile_image_url,
            ]);

        return response()->json([
            'division' => ['id' => $division->id, 'name' => $division->name],
            'employee_count' => $employees->count(),
            'employees' => $employees,
        ]);
    }

    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'assignment_mode' => ['nullable', 'string', 'in:shared,transfer_primary'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignmentMode = $validated['assignment_mode'] ?? EmployeeOrganizationAssignmentService::MODE_TRANSFER_PRIMARY;
        $this->organizationAssignments->assignToLegacyUnit(
            'division',
            (int) $division->id,
            $validated['employee_ids'],
            $assignmentMode,
            $validated['remarks'] ?? null,
        );

        return response()->json([
            'message' => 'Employees assigned successfully.',
            'division' => $this->divisionResponse($division->fresh($this->responseRelations())->loadCount(['employees', 'sectionsOrUnits'])),
        ]);
    }

    public function unassignEmployees(Request $request, int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $this->organizationAssignments->unassignFromLegacyUnit(
            'division',
            (int) $division->id,
            $validated['employee_ids'],
        );

        return response()->json([
            'message' => 'Employees unassigned successfully.',
            'division' => $this->divisionResponse($division->fresh($this->responseRelations())->loadCount(['employees', 'sectionsOrUnits'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        if ($division->sectionsOrUnits()->exists() || $division->departments()->exists()) {
            return response()->json(['message' => 'Cannot delete division because it has departments or sections/units.'], 422);
        }

        User::query()->where('division_id', $division->id)->update(['division_id' => null, 'department_id' => null, 'section_unit_id' => null]);
        $division->delete();

        return response()->json(['message' => 'Division deleted successfully.']);
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('divisions', 'code')->ignore($ignoreId)],
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'division_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function responseRelations(): array
    {
        return [
            'company:id,name,logo',
            'branch:id,name,company_id',
            'divisionHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
        ];
    }

    /**
     * @param  Collection<int, Division>  $divisions
     * @return array<int, array{
     *   assigned_employee_count: int,
     *   branch_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }>
     */
    private function divisionEmployeeCounts(Collection $divisions): array
    {
        return $this->orgUnitEmployeeCountService->forDivisions($divisions);
    }

    /**
     * @return array{
     *   assigned_employee_count: int,
     *   branch_employee_count: int,
     *   department_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }
     */
    private function emptyDivisionEmployeeCounts(): array
    {
        return [
            'assigned_employee_count' => 0,
            'branch_employee_count' => 0,
            'department_employee_count' => 0,
            'unassigned_employee_count' => 0,
            'total_employees' => 0,
        ];
    }

    private function divisionResponse(Division $division, ?array $counts = null): array
    {
        // Logo always comes from Company (single source of truth). Division does not store its own logo.
        $companyLogo = $division->company?->logo ?? null;
        $logoUrl = $companyLogo ? $this->publicMediaUrl($companyLogo) : null;
        $counts ??= $this->divisionEmployeeCounts(collect([$division]))[(int) $division->id]
            ?? $this->emptyDivisionEmployeeCounts();

        return [
            'id' => $division->id,
            'name' => $division->name,
            'code' => $division->code,
            'company_id' => $division->company_id,
            'company_name' => $division->company?->name,
            'logo' => $companyLogo,
            'logo_url' => $logoUrl,
            'branch_id' => $division->branch_id,
            'branch_name' => $division->branch?->name,
            'division_head_id' => $division->division_head_id,
            'division_head_name' => $division->divisionHead?->display_name,
            'division_head_profile_image' => $division->divisionHead?->profile_image_url,
            'status' => $division->status,
            'description' => $division->description,
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'branch_employee_count' => $counts['branch_employee_count'] ?? $counts['department_employee_count'] ?? 0,
            'unassigned_employee_count' => $counts['unassigned_employee_count'],
            'total_employees' => $counts['assigned_employee_count'],
            'total_departments' => $division->departments_count ?? $division->departments()->count(),
            'total_sections_or_units' => $division->sections_or_units_count ?? $division->sectionsOrUnits()->count(),
            'created_at' => $division->created_at?->toIso8601String(),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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

        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }
}
