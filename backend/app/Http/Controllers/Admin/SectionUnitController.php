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
use App\Services\OrgHierarchyNormalizer;
use App\Services\OrgTeamLeaderService;
use App\Services\OrgUnitEmployeeCountService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionUnitController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrgUnitEmployeeCountService $orgUnitEmployeeCountService,
        private readonly OrgHierarchyNormalizer $orgHierarchyNormalizer,
        private readonly OrgTeamLeaderService $orgTeamLeaderService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SectionUnit::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'department:id,name,branch_id',
                'division:id,name,company_id,branch_id',
                'sectionUnitHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'teamLeaders:id,name,first_name,middle_name,last_name,suffix,profile_image',
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

        $sections = $query->orderBy('name')->get();
        $countsById = $this->orgUnitEmployeeCountService->forSectionUnits($sections);

        return response()->json([
            'sections_or_units' => $sections->map(
                fn (SectionUnit $section) => $this->sectionResponse(
                    $section,
                    $countsById[(int) $section->id] ?? null,
                ),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        [$companyId, $branchId, $divisionId, $departmentId] = $this->orgHierarchyNormalizer->normalizeSectionScope($validated);

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
            $merged = array_merge($section->only(['company_id', 'branch_id', 'division_id', 'department_id']), $validated);
            [$companyId, $branchId, $divisionId, $departmentId] = $this->orgHierarchyNormalizer->normalizeSectionScope($merged);
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

        if (array_key_exists('team_leader_ids', $validated)) {
            $this->orgTeamLeaderService->syncSectionTeamLeaders(
                $section,
                array_map('intval', $validated['team_leader_ids'] ?? []),
            );
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
            'name' => [$required, 'string', 'max:255', "regex:/^[A-Za-z0-9\s\-']+$/"],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('sections_or_units', 'code')->ignore($ignoreId)],
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'branch_id' => [$required, 'integer', 'exists:branches,id'],
            'division_id' => [$required, 'integer', 'exists:divisions,id'],
            'department_id' => [$required, 'integer', 'exists:departments,id'],
            'section_unit_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_leader_ids' => ['sometimes', 'array'],
            'team_leader_ids.*' => ['integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.regex' => 'Section/Unit name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);
    }

    private function assertHeadBelongsToSection(int $userId, SectionUnit $section): void
    {
        $user = User::query()->whereKey($userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee is not eligible to be assigned as section/unit head.'],
            ]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee must be active to be assigned as section/unit head.'],
            ]);
        }

        $belongsToSection = (int) $user->section_unit_id === (int) $section->id;
        $belongsToDepartment = $section->department_id !== null
            && (int) $user->department_id === (int) $section->department_id;
        $isDepartmentHead = $section->department_id !== null
            && Department::query()
                ->whereKey($section->department_id)
                ->where('department_head_id', $userId)
                ->exists();

        if (! $belongsToSection && ! $belongsToDepartment && ! $isDepartmentHead) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee must belong to this section/unit or its parent department to be assigned as head.'],
            ]);
        }

        $headCompanyId = $user->getEffectiveCompanyId();
        if ($headCompanyId !== null && $section->company_id !== null && (int) $headCompanyId !== (int) $section->company_id) {
            throw ValidationException::withMessages([
                'section_unit_head_id' => ['The selected employee is assigned to another company and cannot be section/unit head here.'],
            ]);
        }
    }

    /**
     * Prevent the same employee from leading more than one section/unit at a time.
     */
    private function validateSectionHeadExclusive(int $userId, ?int $excludeSectionId): void
    {
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
            'department:id,name,branch_id,division_id',
            'division:id,name,company_id,branch_id',
            'sectionUnitHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
        ];
    }

    private function sectionResponse(SectionUnit $section, ?array $counts = null): array
    {
        $companyLogo = $section->company?->logo ?? null;
        $logoUrl = $companyLogo ? $this->publicMediaUrl($companyLogo) : null;
        $counts ??= $this->orgUnitEmployeeCountService->forSectionUnit($section);

        return [
            'id' => $section->id,
            'name' => $section->name,
            'code' => $section->code,
            'company_id' => $section->company_id,
            'company_name' => $section->company?->name,
            'logo' => $companyLogo,
            'logo_url' => $logoUrl,
            'branch_id' => $section->branch_id,
            'branch_name' => $section->branch?->name,
            'department_id' => $section->department_id,
            'department_name' => $section->department?->name,
            'division_id' => $section->division_id,
            'division_name' => $section->division?->name,
            'section_unit_head_id' => $section->section_unit_head_id,
            'section_unit_head_name' => $section->sectionUnitHead?->display_name,
            'section_unit_head_profile_image' => $section->sectionUnitHead?->profile_image_url,
            ...$this->orgTeamLeaderService->sectionTeamLeaderPayload($section),
            'status' => $section->status,
            'description' => $section->description,
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'division_employee_count' => $counts['division_employee_count'],
            'unassigned_employee_count' => $counts['unassigned_employee_count'],
            'total_employees' => $counts['assigned_employee_count'],
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
