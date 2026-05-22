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
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\OrgHierarchyNormalizer;
use App\Services\OrganizationLeadershipAssignmentService;
use App\Services\OrganizationLeadershipService;
use App\Services\OrgUnitEmployeeCountService;
use App\Services\SectionUnitRosterService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionUnitController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrgUnitEmployeeCountService $orgUnitEmployeeCountService,
        private readonly OrgHierarchyNormalizer $orgHierarchyNormalizer,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipService $organizationLeadershipService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
        private readonly SectionUnitRosterService $sectionUnitRoster,
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
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['section_unit_head_id']);
            $section->section_unit_head_id = (int) $validated['section_unit_head_id'];
            $section->save();
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'section_unit',
                (int) $section->id,
                (int) $validated['section_unit_head_id'],
            );
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
                $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['section_unit_head_id']);
            }
            $section->section_unit_head_id = $validated['section_unit_head_id'];
            $this->organizationLeadershipService->upsertLegacyHeadAssignment(
                'section_unit',
                (int) $section->id,
                $validated['section_unit_head_id'] !== null ? (int) $validated['section_unit_head_id'] : null,
                $previousHeadId !== null ? (int) $previousHeadId : null,
            );
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

        $counts = $this->sectionUnitRoster->countsForSection($section);
        $assignedEmployees = $this->sectionUnitRoster->rosterForSection($section);

        Log::info('SectionUnit.employees', [
            'section_unit_id' => (int) $section->id,
            'primary_employee_count' => $counts['primary_employee_count'],
            'shared_employee_count' => $counts['shared_employee_count'],
            'temporary_employee_count' => $counts['temporary_employee_count'],
            'acting_employee_count' => $counts['acting_employee_count'],
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'returned_employee_count' => count($assignedEmployees),
        ]);

        return response()->json([
            'section_or_unit' => ['id' => $section->id, 'name' => $section->name],
            'employee_count' => $counts['assigned_employee_count'],
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'primary_employee_count' => $counts['primary_employee_count'],
            'shared_employee_count' => $counts['shared_employee_count'],
            'temporary_employee_count' => $counts['temporary_employee_count'],
            'acting_employee_count' => $counts['acting_employee_count'],
            'employees' => $assignedEmployees,
            'assigned_employees' => $assignedEmployees,
        ]);
    }

    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'assignment_mode' => ['nullable', 'string', 'in:shared,transfer_primary'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignmentMode = $validated['assignment_mode'] ?? EmployeeOrganizationAssignmentService::MODE_TRANSFER_PRIMARY;
        $created = $this->organizationAssignments->assignToLegacyUnit(
            'section_unit',
            (int) $section->id,
            $validated['employee_ids'],
            $assignmentMode,
            $validated['remarks'] ?? null,
        );

        $fresh = $section->fresh($this->responseRelations());
        $counts = $this->sectionUnitRoster->countsForSection($fresh);
        $assignedEmployees = $this->sectionUnitRoster->rosterForSection($fresh);

        Log::info('SectionUnit.assignEmployees', [
            'section_unit_id' => (int) $section->id,
            'selected_employee_ids' => $validated['employee_ids'],
            'assignment_mode' => $assignmentMode,
            'assignment_type' => $assignmentMode === EmployeeOrganizationAssignmentService::MODE_SHARED
                ? EmployeeOrganizationAssignmentService::TYPE_SHARED
                : EmployeeOrganizationAssignmentService::TYPE_PRIMARY,
            'rows_created' => collect($created)->map(fn ($row) => [
                'id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'assignment_type' => $row->assignment_type,
                'section_unit_id' => $row->section_unit_id,
            ])->values()->all(),
            'primary_employee_count' => $counts['primary_employee_count'],
            'shared_employee_count' => $counts['shared_employee_count'],
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'returned_employee_count' => count($assignedEmployees),
        ]);

        return response()->json([
            'message' => 'Employees assigned successfully.',
            'section_or_unit' => array_merge(
                $this->sectionResponse($fresh, $counts),
                ['assigned_employees' => $assignedEmployees],
            ),
        ]);
    }

    public function unassignEmployees(Request $request, int $id): JsonResponse
    {
        $section = SectionUnit::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $this->organizationAssignments->unassignFromLegacyUnit(
            'section_unit',
            (int) $section->id,
            $validated['employee_ids'],
        );

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
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.regex' => 'Section/Unit name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
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
            'status' => $section->status,
            'description' => $section->description,
            'assigned_employee_count' => $counts['assigned_employee_count'],
            'primary_employee_count' => $counts['primary_employee_count'] ?? 0,
            'shared_employee_count' => $counts['shared_employee_count'] ?? 0,
            'temporary_employee_count' => $counts['temporary_employee_count'] ?? 0,
            'acting_employee_count' => $counts['acting_employee_count'] ?? 0,
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
