<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Department;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\LegacyOrganizationMirrorService;
use App\Services\OrganizationLeadershipAssignmentService;
use App\Services\OrganizationLeadershipService;
use App\Services\OrgApprovalWorkflowService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AreaController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipService $organizationLeadershipService,
        private readonly LegacyOrganizationMirrorService $legacyOrganizationMirror,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Area::query()
            ->with([
                'company:id,name,logo',
                'areaManager:id,name,first_name,middle_name,last_name,suffix,profile_image',
            ])
            ->withCount('branches')
            ->select('areas.*')
            ->selectSub($this->employeeCountSubquery(), 'employees_count');

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $this->dataScopeService->restrictAreaQuery($request->user(), $query);

        $areas = $query->orderBy('area_name')
            ->get()
            ->map(fn (Area $area): array => $this->areaResponse($area))
            ->values();

        return response()->json(['areas' => $areas]);
    }

    public function companyAreas(Request $request, int $companyId): JsonResponse
    {
        $query = Area::query()
            ->where('company_id', $companyId)
            ->with([
                'company:id,name,logo',
                'areaManager:id,name,first_name,middle_name,last_name,suffix,profile_image',
            ])
            ->withCount('branches')
            ->select('areas.*')
            ->selectSub($this->employeeCountSubquery(), 'employees_count');

        $this->dataScopeService->restrictAreaQuery($request->user(), $query);

        return response()->json([
            'areas' => $query->orderBy('area_name')->get()->map(fn (Area $area) => $this->areaResponse($area))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $branchIds = array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])));
        unset($validated['branch_ids']);
        if (($validated['area_manager_employee_id'] ?? null) !== null) {
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['area_manager_employee_id']);
        }

        [$area, $affectedBranchIds] = DB::transaction(function () use ($validated, $branchIds, $request): array {
            $area = Area::create($validated + [
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $affectedBranchIds = $this->syncBranchesForArea($area, $branchIds);
            $this->syncAreaHeadAssignment($area, null);

            return [$area, $affectedBranchIds];
        });
        $this->resyncPendingFilingsForBranches($affectedBranchIds);

        Log::info('organization_area: area created', $this->logPayload($area, null, $area->area_manager_employee_id, 'created') + [
            'branch_ids' => $branchIds,
        ]);

        return response()->json([
            'message' => 'Area created successfully.',
            'area' => $this->areaResponse($this->freshAreaForResponse($area)),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $validated = $this->validatePayload($request, $area);
        $branchIds = array_key_exists('branch_ids', $validated)
            ? array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])))
            : null;
        unset($validated['branch_ids']);
        if (array_key_exists('area_manager_employee_id', $validated) && ($validated['area_manager_employee_id'] ?? null) !== null) {
            $this->leadershipAssignments->assertEligibleHeadCandidate((int) $validated['area_manager_employee_id']);
        }

        $oldManagerId = $area->area_manager_employee_id;
        $affectedBranchIds = DB::transaction(function () use ($area, $validated, $branchIds, $request): array {
            $area->fill($validated + ['updated_by' => $request->user()?->id]);
            $area->save();
            if ($branchIds !== null) {
                return $this->syncBranchesForArea($area, $branchIds);
            }

            return [];
        });

        if (array_key_exists('area_manager_employee_id', $validated)) {
            $this->syncAreaHeadAssignment($area, $oldManagerId);
            foreach (array_unique(array_filter([
                $oldManagerId ? (int) $oldManagerId : null,
                $area->area_manager_employee_id ? (int) $area->area_manager_employee_id : null,
            ])) as $uid) {
                EmployeeProfileCache::invalidate($uid);
            }
        }
        $this->resyncPendingFilingsForBranches($affectedBranchIds);

        Log::info('organization_area: area updated', $this->logPayload($area, $oldManagerId, $area->area_manager_employee_id, 'updated') + [
            'branch_ids' => $branchIds,
        ]);

        return response()->json([
            'message' => 'Area updated successfully.',
            'area' => $this->areaResponse($this->freshAreaForResponse($area)),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $oldStatus = $area->status;
        $branchCount = $area->branches()->count();

        if ($oldStatus === 'inactive') {
            if ($branchCount > 0) {
                throw ValidationException::withMessages([
                    'area' => ['This inactive area still has assigned branches. Remove the branches before deleting it permanently.'],
                ]);
            }

            DB::transaction(function () use ($area): void {
                // Deactivate leadership first so the area manager hat clears before the mirror row is removed.
                app(\App\Services\LegacyOrganizationMirrorService::class)->deactivate($area);

                $unitIds = DB::table('organization_units')
                    ->where('legacy_source_type', 'area')
                    ->where('legacy_source_id', (int) $area->id)
                    ->pluck('id');
                if ($unitIds->isNotEmpty()) {
                    DB::table('organization_units')->whereIn('id', $unitIds->all())->delete();
                }

                $area->delete();
            });

            Log::info('organization_area: area deleted', [
                'area_id' => (int) $area->id,
                'company_id' => (int) $area->company_id,
                'area_manager_employee_id' => $area->area_manager_employee_id,
                'hierarchy_path' => $this->organizationPath($area),
                'permission_scope' => 'area',
                'old_status' => $oldStatus,
            ]);

            return response()->json(['message' => 'Area permanently deleted.']);
        }

        $area->status = 'inactive';
        $area->effective_to = $area->effective_to ?? now()->toDateString();
        $area->updated_by = $request->user()?->id;
        $area->save();

        Log::info('organization_area: area deactivated', $this->logPayload($area, $area->area_manager_employee_id, $area->area_manager_employee_id, 'deactivated') + [
            'old_status' => $oldStatus,
            'branches_count' => $branchCount,
        ]);

        return response()->json(['message' => 'Area deactivated successfully.']);
    }

    public function branches(Request $request, int $id): JsonResponse
    {
        $areaQuery = Area::query()->whereKey($id);
        $this->dataScopeService->restrictAreaQuery($request->user(), $areaQuery);
        $area = $areaQuery->firstOrFail();

        $branches = $area->branches()
            ->with(['company:id,name,logo', 'branchManager:id,name,first_name,middle_name,last_name,suffix,profile_image'])
            ->withCount('departments')
            ->withTotalEmployeesCount()
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'company_id' => (int) $branch->company_id,
                'company_name' => $branch->company?->name,
                'area_id' => (int) $area->id,
                'area_name' => $area->area_name,
                'address' => $branch->address,
                'branch_manager_id' => $branch->branch_manager_id,
                'branch_manager_name' => $branch->branchManager?->display_name,
                'departments_count' => (int) ($branch->departments_count ?? 0),
                'employees_count' => (int) ($branch->employees_count ?? 0),
            ])
            ->values();

        return response()->json(['area' => $this->areaResponse($area), 'branches' => $branches]);
    }

    public function employees(Request $request, int $id): JsonResponse
    {
        $areaQuery = Area::query()->whereKey($id);
        $this->dataScopeService->restrictAreaQuery($request->user(), $areaQuery);
        $area = $areaQuery->firstOrFail();
        $branchIds = $area->branches()->pluck('id')->map(fn ($value) => (int) $value)->all();
        $departmentIds = $branchIds === []
            ? []
            : Department::query()->whereIn('branch_id', $branchIds)->pluck('id')->map(fn ($value) => (int) $value)->all();
        $sectionIds = $branchIds === []
            ? []
            : SectionUnit::query()->whereIn('branch_id', $branchIds)->pluck('id')->map(fn ($value) => (int) $value)->all();

        $employees = User::query()
            ->visibleEmployees()
            ->where(function ($query) use ($branchIds, $departmentIds, $sectionIds): void {
                $query->whereIn('branch_id', $branchIds);
                if ($departmentIds !== []) {
                    $query->orWhereIn('department_id', $departmentIds);
                }
                if ($sectionIds !== []) {
                    $query->orWhereIn('section_unit_id', $sectionIds);
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_id', 'branch_id', 'department_id', 'section_unit_id', 'profile_image'])
            ->map(fn (User $employee): array => [
                'id' => (int) $employee->id,
                'employee_id' => $employee->employee_id,
                'name' => $employee->display_name,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'section_unit_id' => $employee->section_unit_id,
                'profile_image' => $employee->profile_image_url,
            ])
            ->values();

        return response()->json(['area' => $this->areaResponse($area), 'employees' => $employees]);
    }

    public function assignBranches(Request $request, int $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $validated = $request->validate([
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchIds = array_values(array_unique(array_map('intval', $validated['branch_ids'] ?? [])));
        $affectedBranchIds = DB::transaction(fn (): array => $this->syncBranchesForArea($area, $branchIds));
        $this->resyncPendingFilingsForBranches($affectedBranchIds);

        Log::info('organization_area: branches assigned', [
            'area_id' => (int) $area->id,
            'company_id' => (int) $area->company_id,
            'area_manager_employee_id' => $area->area_manager_employee_id,
            'branch_ids' => $branchIds,
            'hierarchy_path' => $this->organizationPath($area),
            'permission_scope' => 'area',
        ]);

        return response()->json([
            'message' => 'Branches assigned to area successfully.',
            'area' => $this->areaResponse($area->fresh($this->responseRelations())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Area $area = null): array
    {
        $areaId = $area?->id;
        $validated = $request->validate([
            'company_id' => [$area ? 'sometimes' : 'required', 'integer', 'exists:companies,id'],
            'area_name' => [$area ? 'sometimes' : 'required', 'string', 'max:255'],
            'area_code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('areas', 'area_code')
                    ->where(fn ($query) => $query->where('company_id', (int) ($request->input('company_id') ?? $area?->company_id)))
                    ->ignore($areaId),
            ],
            'area_manager_employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ], [
            'area_code.unique' => 'An area with this code already exists for the selected company. Edit the existing area instead of creating a new one.',
        ]);

        if (array_key_exists('area_name', $validated)) {
            $validated['area_name'] = trim((string) $validated['area_name']);
            if ($validated['area_name'] === '') {
                throw ValidationException::withMessages(['area_name' => ['Area name is required.']]);
            }
        }
        if (array_key_exists('area_code', $validated)) {
            $validated['area_code'] = $validated['area_code'] !== null && trim((string) $validated['area_code']) !== ''
                ? trim((string) $validated['area_code'])
                : null;
        }
        if (! array_key_exists('status', $validated)) {
            $validated['status'] = $area?->status ?? 'active';
        }

        return $validated;
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    private function syncBranchesForArea(Area $area, array $branchIds): array
    {
        $branches = Branch::query()->whereIn('id', $branchIds)->get(['id', 'company_id', 'area_id']);
        if ($branches->count() !== count($branchIds)) {
            throw ValidationException::withMessages(['branch_ids' => ['One or more branches were not found.']]);
        }

        $invalidCompany = $branches->first(fn (Branch $branch): bool => (int) $branch->company_id !== (int) $area->company_id);
        if ($invalidCompany) {
            throw ValidationException::withMessages(['branch_ids' => ['Branches must belong to the same company as the area.']]);
        }

        $existingAreaBranchIds = Branch::query()
            ->where('area_id', (int) $area->id)
            ->where('company_id', (int) $area->company_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $affectedBranchIds = array_values(array_unique([
            ...$existingAreaBranchIds,
            ...$branchIds,
        ]));

        $existingAreaBranches = Branch::query()
            ->where('area_id', (int) $area->id)
            ->where('company_id', (int) $area->company_id);
        if ($branchIds !== []) {
            $existingAreaBranches->whereNotIn('id', $branchIds);
        }
        $existingAreaBranches->update(['area_id' => null, 'updated_at' => now()]);

        if ($branchIds !== []) {
            Branch::query()->whereIn('id', $branchIds)->update(['area_id' => (int) $area->id, 'updated_at' => now()]);
        }

        if ($affectedBranchIds !== []) {
            // Bulk updates bypass Branch::saved, so repair the flexible-org parent links explicitly.
            $this->legacyOrganizationMirror->sync($area);
            Branch::query()
                ->whereIn('id', $affectedBranchIds)
                ->orderBy('id')
                ->get()
                ->each(fn (Branch $branch) => $this->legacyOrganizationMirror->sync($branch));
        }

        return $affectedBranchIds;
    }

    /**
     * Rebuild pending chains after a branch enters or leaves an Area so every
     * filing type follows the new Area Head immediately.
     *
     * @param  list<int>  $branchIds
     */
    private function resyncPendingFilingsForBranches(array $branchIds): void
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', $branchIds)))) as $branchId) {
            $this->approvalWorkflowService->resyncPendingRequestChains(
                OrganizationLeadershipService::pendingFilingResyncTypes(),
                'branch',
                $branchId,
            );
        }
    }

    private function syncAreaHeadAssignment(Area $area, mixed $oldManagerId): void
    {
        $this->organizationLeadershipService->upsertLegacyHeadAssignment(
            'area',
            (int) $area->id,
            $area->area_manager_employee_id !== null ? (int) $area->area_manager_employee_id : null,
            $oldManagerId !== null ? (int) $oldManagerId : null,
        );
    }

    /**
     * @return array<int, string>
     */
    private function responseRelations(): array
    {
        return [
            'company:id,name,logo',
            'areaManager:id,name,first_name,middle_name,last_name,suffix,profile_image',
        ];
    }

    private function employeeCountSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('users')
            ->whereIn('users.role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('users.is_system_user', false)
            ->where('users.is_hidden', false)
            ->where('users.exclude_from_reports', false)
            ->where('users.exclude_from_payroll', false)
            ->where('users.exclude_from_attendance', false)
            ->where(function ($query): void {
                $query->whereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('branches')
                        ->whereColumn('branches.id', 'users.branch_id')
                        ->whereColumn('branches.area_id', 'areas.id');
                })->orWhereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('departments')
                        ->join('branches', 'branches.id', '=', 'departments.branch_id')
                        ->whereColumn('departments.id', 'users.department_id')
                        ->whereColumn('branches.area_id', 'areas.id');
                });
            })
            ->selectRaw('COUNT(*)');
    }

    private function freshAreaForResponse(Area $area): Area
    {
        return Area::query()
            ->with($this->responseRelations())
            ->withCount('branches')
            ->select('areas.*')
            ->selectSub($this->employeeCountSubquery(), 'employees_count')
            ->whereKey($area->id)
            ->firstOrFail();
    }

    private function areaResponse(Area $area): array
    {
        return [
            'id' => (int) $area->id,
            'company_id' => (int) $area->company_id,
            'company_name' => $area->company?->name,
            'company_logo_url' => $this->publicMediaUrl($area->company?->logo),
            'area_name' => $area->area_name,
            'area_code' => $area->area_code,
            'area_manager_employee_id' => $area->area_manager_employee_id,
            'area_manager_name' => $area->areaManager?->display_name,
            'area_manager_profile_image' => $area->areaManager?->profile_image_url,
            'description' => $area->description,
            'status' => $area->status,
            'effective_from' => $area->effective_from?->format('Y-m-d'),
            'effective_to' => $area->effective_to?->format('Y-m-d'),
            'branches_count' => (int) ($area->branches_count ?? $area->branches()->count()),
            'employees_count' => (int) ($area->employees_count ?? 0),
            'organization_path' => $this->organizationPath($area),
            'created_at' => $area->created_at?->toIso8601String(),
            'updated_at' => $area->updated_at?->toIso8601String(),
        ];
    }

    private function organizationPath(Area $area): string
    {
        $companyName = $area->company?->name;
        return trim(implode(' > ', array_filter([$companyName, $area->area_name])));
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

        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    /**
     * @return array<string, mixed>
     */
    private function logPayload(Area $area, mixed $oldManagerId, mixed $newManagerId, string $reason): array
    {
        return [
            'area_id' => (int) $area->id,
            'company_id' => (int) $area->company_id,
            'area_manager_employee_id' => $newManagerId !== null ? (int) $newManagerId : null,
            'old_area_manager_employee_id' => $oldManagerId !== null ? (int) $oldManagerId : null,
            'hierarchy_path' => $this->organizationPath($area),
            'approval_scope' => 'area',
            'permission_scope' => 'area',
            'reason' => $reason,
        ];
    }
}
