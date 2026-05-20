<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionUnitController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SectionUnit::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'department:id,name,branch_id',
                'division:id,name,company_id,branch_id,department_id',
                'sectionUnitHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
            ])
            ->withCount('employees');

        foreach (['company_id', 'branch_id', 'department_id', 'division_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $this->dataScopeService->restrictSectionUnitQuery($request->user(), $query);

        return response()->json([
            'sections_or_units' => $query->orderBy('name')->get()->map(fn (SectionUnit $section) => $this->sectionResponse($section)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        [$companyId, $branchId, $departmentId, $divisionId] = $this->normalizeOrgIds($validated);

        $this->dataScopeService->assertCanCreateEmployeeInOrg(
            $request->user(),
            $companyId,
            $branchId,
            $departmentId,
            $divisionId,
        );

        $section = SectionUnit::query()->create([
            'name' => trim((string) $validated['name']),
            'code' => $this->nullableTrim($validated['code'] ?? null),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'division_id' => $divisionId,
            'section_unit_head_id' => null,
            'status' => $validated['status'] ?? 'active',
            'description' => $this->nullableTrim($validated['description'] ?? null),
        ]);

        if (($validated['section_unit_head_id'] ?? null) !== null) {
            $this->validateSectionHeadExclusive((int) $validated['section_unit_head_id'], $section->id);
            $this->assertHeadBelongsToSection((int) $validated['section_unit_head_id'], $section);
            $section->section_unit_head_id = (int) $validated['section_unit_head_id'];
            $section->save();
            EmployeeProfileCache::invalidate((int) $validated['section_unit_head_id']);
        }

        return response()->json([
            'message' => 'Section/Unit created successfully.',
            'section_or_unit' => $this->sectionResponse($section->fresh($this->responseRelations())->loadCount('employees')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        $validated = $this->validatedPayload($request, $id, partial: true);

        $orgChanged = array_key_exists('company_id', $validated)
            || array_key_exists('branch_id', $validated)
            || array_key_exists('department_id', $validated)
            || array_key_exists('division_id', $validated);

        if ($orgChanged) {
            $merged = array_merge($section->only(['company_id', 'branch_id', 'department_id', 'division_id']), $validated);
            [$companyId, $branchId, $departmentId, $divisionId] = $this->normalizeOrgIds($merged);
            $section->company_id = $companyId;
            $section->branch_id = $branchId;
            $section->department_id = $departmentId;
            $section->division_id = $divisionId;
        }

        if (array_key_exists('name', $validated)) {
            $section->name = trim((string) $validated['name']);
        }
        if (array_key_exists('code', $validated)) {
            $section->code = $this->nullableTrim($validated['code']);
        }
        if (array_key_exists('status', $validated)) {
            $section->status = $validated['status'];
        }
        if (array_key_exists('description', $validated)) {
            $section->description = $this->nullableTrim($validated['description']);
        }
        if (array_key_exists('section_unit_head_id', $validated)) {
            $previousHeadId = $section->section_unit_head_id;
            if ($validated['section_unit_head_id'] !== null) {
                $this->validateSectionHeadExclusive((int) $validated['section_unit_head_id'], $section->id);
                $this->assertHeadBelongsToSection((int) $validated['section_unit_head_id'], $section);
            }
            $section->section_unit_head_id = $validated['section_unit_head_id'];
            foreach (array_unique(array_filter([
                $previousHeadId ? (int) $previousHeadId : null,
                $section->section_unit_head_id ? (int) $section->section_unit_head_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }

        $section->save();

        return response()->json([
            'message' => 'Section/Unit updated successfully.',
            'section_or_unit' => $this->sectionResponse($section->fresh($this->responseRelations())->loadCount('employees')),
        ]);
    }

    public function employees(Request $request, int $id): JsonResponse
    {
        $scope = SectionUnit::query()->whereKey($id);
        $this->dataScopeService->restrictSectionUnitQuery($request->user(), $scope);
        $section = $scope->firstOrFail();

        $employees = $section->employees()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->orderByLastName()
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_image'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->display_name,
                'formatted_name' => $user->formatted_name,
                'profile_image' => $user->profile_image_url,
            ]);

        return response()->json([
            'section_or_unit' => ['id' => $section->id, 'name' => $section->name],
            'employees' => $employees,
        ]);
    }

    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->update([
                'company_id' => $section->company_id,
                'branch_id' => $section->branch_id,
                'department_id' => $section->department_id,
                'division_id' => $section->division_id,
                'section_unit_id' => $section->id,
            ]);

        return response()->json([
            'message' => 'Employees assigned successfully.',
            'section_or_unit' => $this->sectionResponse($section->fresh($this->responseRelations())->loadCount('employees')),
        ]);
    }

    public function unassignEmployees(Request $request, int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->where('section_unit_id', $section->id)
            ->update(['section_unit_id' => null]);

        return response()->json([
            'message' => 'Employees unassigned successfully.',
            'section_or_unit' => $this->sectionResponse($section->fresh($this->responseRelations())->loadCount('employees')),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        User::query()->where('section_unit_id', $section->id)->update(['section_unit_id' => null]);
        $section->delete();

        return response()->json(['message' => 'Section/Unit deleted successfully.']);
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('sections_or_units', 'code')->ignore($ignoreId)],
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'branch_id' => [$required, 'integer', 'exists:branches,id'],
            'department_id' => [$required, 'integer', 'exists:departments,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'section_unit_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:int|null,1:int|null,2:int|null,3:int|null}
     */
    private function normalizeOrgIds(array $data): array
    {
        $divisionId = isset($data['division_id']) ? (int) $data['division_id'] : null;
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if ($divisionId !== null) {
            $division = Division::query()->findOrFail($divisionId);
            $companyId = $division->company_id !== null ? (int) $division->company_id : $companyId;
            $branchId = $division->branch_id !== null ? (int) $division->branch_id : $branchId;
            $departmentId = $division->department_id !== null ? (int) $division->department_id : $departmentId;
        }
        if ($departmentId !== null) {
            $department = Department::query()->with('branch')->findOrFail($departmentId);
            $branchId = (int) $department->branch_id;
            $companyId = $department->branch ? (int) $department->branch->company_id : $companyId;
        }
        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $companyId = (int) $branch->company_id;
        }
        if ($companyId !== null) {
            Company::query()->findOrFail($companyId);
        }

        if ($companyId === null || $branchId === null || $departmentId === null) {
            throw ValidationException::withMessages([
                'department_id' => ['Company, branch, and department are required for a section/unit.'],
            ]);
        }

        return [$companyId, $branchId, $departmentId, $divisionId];
    }

    private function assertHeadBelongsToSection(int $userId, SectionUnit $section): void
    {
        $user = User::query()->whereKey($userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->first();
        if (! $user || (int) $user->section_unit_id !== (int) $section->id) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee must belong to this section/unit to be assigned as head.'],
            ]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee must be active to be assigned as section/unit head.'],
            ]);
        }
    }

    private function validateSectionHeadExclusive(int $userId, ?int $excludeSectionId): void
    {
        if (Company::query()->where('company_head_id', $userId)->exists()
            || Branch::query()->where('branch_manager_id', $userId)->exists()
            || Department::query()->where('department_head_id', $userId)->exists()
            || Division::query()->where('division_head_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['This employee is already assigned to another organization head role.'],
            ]);
        }

        $query = SectionUnit::query()->where('section_unit_head_id', $userId);
        if ($excludeSectionId !== null) {
            $query->where('id', '!=', $excludeSectionId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['This employee is already Section/Unit Head of another section/unit.'],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function responseRelations(): array
    {
        return [
            'company:id,name,logo',
            'branch:id,name,company_id',
            'department:id,name,branch_id',
            'division:id,name,company_id,branch_id,department_id',
            'sectionUnitHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
        ];
    }

    private function sectionResponse(SectionUnit $section): array
    {
        return [
            'id' => $section->id,
            'name' => $section->name,
            'code' => $section->code,
            'company_id' => $section->company_id,
            'company_name' => $section->company?->name,
            'branch_id' => $section->branch_id,
            'branch_name' => $section->branch?->name,
            'department_id' => $section->department_id,
            'department_name' => $section->department?->name,
            'division_id' => $section->division_id,
            'division_name' => $section->division?->name,
            'section_unit_head_id' => $section->section_unit_head_id,
            'section_unit_head_name' => $section->sectionUnitHead?->display_name,
            'section_unit_head_profile_image' => $section->sectionUnitHead?->profile_image_url,
            'status' => $section->status,
            'description' => $section->description,
            'total_employees' => $section->employees_count ?? $section->employees()->count(),
            'created_at' => $section->created_at?->toIso8601String(),
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
}
