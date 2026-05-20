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

class DivisionController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Division::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'department:id,name,branch_id',
                'divisionHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
            ])
            ->withCount(['employees', 'sectionsOrUnits']);

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
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

        return response()->json([
            'divisions' => $query->orderBy('name')->get()->map(fn (Division $division) => $this->divisionResponse($division)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        [$companyId, $branchId, $departmentId] = $this->normalizeOrgIds($validated);

        $this->dataScopeService->assertCanCreateEmployeeInOrg($request->user(), $companyId, $branchId, $departmentId);

        $division = Division::query()->create([
            'name' => trim((string) $validated['name']),
            'code' => $this->nullableTrim($validated['code'] ?? null),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'division_head_id' => null,
            'status' => $validated['status'] ?? 'active',
            'description' => $this->nullableTrim($validated['description'] ?? null),
        ]);

        if (($validated['division_head_id'] ?? null) !== null) {
            $this->validateDivisionHeadExclusive((int) $validated['division_head_id'], $division->id);
            $this->assertHeadBelongsToDivision((int) $validated['division_head_id'], $division);
            $division->division_head_id = (int) $validated['division_head_id'];
            $division->save();
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
            || array_key_exists('branch_id', $validated)
            || array_key_exists('department_id', $validated);

        if ($orgChanged) {
            $merged = array_merge($division->only(['company_id', 'branch_id', 'department_id']), $validated);
            [$companyId, $branchId, $departmentId] = $this->normalizeOrgIds($merged);
            $division->company_id = $companyId;
            $division->branch_id = $branchId;
            $division->department_id = $departmentId;
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
                $this->validateDivisionHeadExclusive((int) $validated['division_head_id'], $division->id);
                $this->assertHeadBelongsToDivision((int) $validated['division_head_id'], $division);
            }
            $division->division_head_id = $validated['division_head_id'];
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

        $employees = $division->employees()
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
            'division' => ['id' => $division->id, 'name' => $division->name],
            'employees' => $employees,
        ]);
    }

    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->update([
                'company_id' => $division->company_id,
                'branch_id' => $division->branch_id,
                'department_id' => $division->department_id,
                'division_id' => $division->id,
                'section_unit_id' => null,
            ]);

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

        User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->where('division_id', $division->id)
            ->update(['division_id' => null, 'section_unit_id' => null]);

        return response()->json([
            'message' => 'Employees unassigned successfully.',
            'division' => $this->divisionResponse($division->fresh($this->responseRelations())->loadCount(['employees', 'sectionsOrUnits'])),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $division = Division::query()->findOrFail($id);
        if ($division->sectionsOrUnits()->exists()) {
            return response()->json(['message' => 'Cannot delete division because it has sections/units.'], 422);
        }

        User::query()->where('division_id', $division->id)->update(['division_id' => null, 'section_unit_id' => null]);
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
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'division_head_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:int|null,1:int|null,2:int|null}
     */
    private function normalizeOrgIds(array $data): array
    {
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if ($departmentId !== null) {
            $department = Department::query()->with('branch')->findOrFail($departmentId);
            $branchId = (int) $department->branch_id;
            $companyId = $department->branch ? (int) $department->branch->company_id : $companyId;
        } elseif ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $companyId = (int) $branch->company_id;
        } elseif ($companyId !== null) {
            Company::query()->findOrFail($companyId);
        }

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Company is required.']]);
        }

        return [$companyId, $branchId, $departmentId];
    }

    private function assertHeadBelongsToDivision(int $userId, Division $division): void
    {
        $user = User::query()->whereKey($userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->first();
        if (! $user || (int) $user->division_id !== (int) $division->id) {
            throw ValidationException::withMessages([
                'division_head_id' => ['The selected employee must belong to this division to be assigned as head.'],
            ]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'division_head_id' => ['The selected employee must be active to be assigned as division head.'],
            ]);
        }
    }

    private function validateDivisionHeadExclusive(int $userId, ?int $excludeDivisionId): void
    {
        if (Company::query()->where('company_head_id', $userId)->exists()
            || Branch::query()->where('branch_manager_id', $userId)->exists()
            || Department::query()->where('department_head_id', $userId)->exists()
            || SectionUnit::query()->where('section_unit_head_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'division_head_id' => ['This employee is already assigned to another organization head role.'],
            ]);
        }

        $query = Division::query()->where('division_head_id', $userId);
        if ($excludeDivisionId !== null) {
            $query->where('id', '!=', $excludeDivisionId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'division_head_id' => ['This employee is already Division Head of another division.'],
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
            'divisionHead:id,name,first_name,middle_name,last_name,suffix,profile_image',
        ];
    }

    private function divisionResponse(Division $division): array
    {
        return [
            'id' => $division->id,
            'name' => $division->name,
            'code' => $division->code,
            'company_id' => $division->company_id,
            'company_name' => $division->company?->name,
            'branch_id' => $division->branch_id,
            'branch_name' => $division->branch?->name,
            'department_id' => $division->department_id,
            'department_name' => $division->department?->name,
            'division_head_id' => $division->division_head_id,
            'division_head_name' => $division->divisionHead?->display_name,
            'division_head_profile_image' => $division->divisionHead?->profile_image_url,
            'status' => $division->status,
            'description' => $division->description,
            'total_employees' => $division->employees_count ?? $division->employees()->count(),
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
}
