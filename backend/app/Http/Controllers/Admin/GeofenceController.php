<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\BranchGeofenceSetting;
use App\Models\GeofenceGlobalSetting;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Models\EmployeeGeofenceAssignment;
use App\Services\BranchEmployeeResolver;
use App\Services\DataScopeService;
use App\Services\EmployeeGeofenceAssignmentService;
use App\Services\EmployeeGeofenceResolver;
use App\Services\GeofenceLiveMonitorService;
use App\Services\GeofenceValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeofenceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly BranchEmployeeResolver $branchEmployeeResolver,
        private readonly GeofenceValidationService $geofenceValidation,
        private readonly GeofenceLiveMonitorService $geofenceLiveMonitor,
        private readonly EmployeeGeofenceResolver $employeeGeofenceResolver,
        private readonly EmployeeGeofenceAssignmentService $employeeGeofenceAssignmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()
            ->with(['company:id,name,logo', 'area:id,area_name,company_id', 'geofenceSettings'])
            ->withCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('status', 'active'),
            ]);

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }

        $this->dataScopeService->restrictBranchQuery($request->user(), $query);

        $branches = $query->orderBy('name')->get()->map(fn (Branch $branch): array => $this->branchPayload($branch))->values();
        $employeeExemptionIds = $this->geofenceValidation->employeeExemptionIds();

        return response()->json([
            'branches' => $branches,
            'geofence_module' => [
                'enabled' => $this->geofenceValidation->geofenceModuleEnabled(),
            ],
            'employee_exemptions' => [
                'employee_ids' => $employeeExemptionIds,
                'employees' => $this->employeeExemptionCandidates($request),
            ],
            'defaults' => [
                'accuracy_threshold_meters' => GeofenceValidationService::DEFAULT_ACCURACY_THRESHOLD_METERS,
                'mobile_accuracy_threshold_meters' => GeofenceValidationService::DEFAULT_MOBILE_ACCURACY_THRESHOLD_METERS,
                'desktop_accuracy_threshold_meters' => GeofenceValidationService::DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS,
                'enforcement_mode' => 'enforce',
                'no_active_policy' => 'block',
                'accuracy_policy' => 'balanced',
                'accuracy_buffer_mode' => 'strict',
                'poor_accuracy_action' => 'block',
                'minimum_samples' => 3,
                'maximum_samples' => 5,
                'sample_timeout_seconds' => 15,
                'require_backend_validation' => true,
            ],
        ]);
    }

    public function updateEmployeeExemptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['present', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $requestedIds = collect($validated['employee_ids'])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $employeeQuery = User::query()
            ->activeRoster()
            ->whereIn('id', $requestedIds->all());
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeeQuery);
        $accessibleIds = $employeeQuery->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();

        if ($accessibleIds->all() !== $requestedIds->sort()->values()->all()) {
            abort(403, 'One or more selected employees are outside your data scope.');
        }

        $settings = GeofenceGlobalSetting::query()->firstOrNew(['id' => 1]);
        $settings->fill([
            'geofence_module_enabled' => (bool) ($settings->geofence_module_enabled ?? $this->geofenceValidation->geofenceModuleEnabled()),
            'attendance_without_geofence_enabled' => (bool) ($settings->attendance_without_geofence_enabled ?? $this->geofenceValidation->attendanceWithoutGeofenceEnabled()),
            'employee_exemption_ids' => $requestedIds->all(),
            'updated_by' => $request->user()?->id,
        ])->save();

        GeofenceValidationService::forgetGlobalCache();

        $this->audit($request, 'geofence_employee_exemptions_updated', null, null, [
            'employee_ids' => $requestedIds->all(),
        ]);

        return response()->json([
            'message' => 'Employee geofence exemptions updated.',
            'employee_exemptions' => [
                'employee_ids' => $requestedIds->all(),
                'employees' => $this->employeeExemptionCandidates($request),
            ],
        ]);
    }

    public function updateEmployeeGeofenceEnforcement(Request $request, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $employeeQuery = User::query()->activeRoster()->whereKey($employeeId);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeeQuery);
        $employee = $employeeQuery->first();
        if (! $employee) {
            abort(404, 'Employee not found.');
        }

        if ($this->geofenceValidation->employeeExemptFromGeofence($employee)) {
            abort(422, 'This employee is fully exempt from geofencing. Remove the exemption before changing enforcement.');
        }

        $resolved = $this->employeeGeofenceAssignmentService->setLocationOnlyMode(
            (int) $employee->id,
            ! (bool) $validated['enabled'],
            $request->user(),
            $validated['reason'] ?? null,
        );

        $this->audit($request, (bool) $validated['enabled'] ? 'employee_geofence_enforcement_enabled' : 'employee_geofence_enforcement_disabled', null, null, [
            'employee_id' => (int) $employee->id,
            'enabled' => (bool) $validated['enabled'],
        ]);

        return response()->json([
            'message' => (bool) $validated['enabled']
                ? 'Geofence enforcement enabled for this employee.'
                : 'Geofence enforcement disabled for this employee. Location is still required.',
            'employee' => [
                ...$this->employeeAssignmentEmployeePayload($employee),
                'geofence_enforcement_enabled' => (bool) $validated['enabled'],
                'geofence_status' => (bool) $validated['enabled'] ? 'active' : 'location_only',
                'validation_mode' => $resolved['validation_mode'] ?? 'any_assigned_geofence',
            ],
        ]);
    }

    public function updateModuleSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $settings = GeofenceGlobalSetting::query()->firstOrNew(['id' => 1]);
        $settings->fill([
            'geofence_module_enabled' => (bool) $validated['enabled'],
            'attendance_without_geofence_enabled' => (bool) ($settings->attendance_without_geofence_enabled ?? true),
            'updated_by' => $request->user()?->id,
        ])->save();

        GeofenceValidationService::forgetGlobalCache();
        Cache::forget(GeofenceValidationService::scopedCacheKey('app:public-settings'));
        Cache::forget('app:public-settings');

        $this->audit($request, 'geofence_module_settings_updated', null, null, [
            'enabled' => (bool) $validated['enabled'],
        ]);

        return response()->json([
            'message' => (bool) $validated['enabled'] ? 'Geofencing module enabled.' : 'Geofencing module disabled.',
            'geofence_module' => [
                'enabled' => (bool) $validated['enabled'],
            ],
        ]);
    }

    public function branch(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);

        return response()->json([
            'branch' => $this->branchPayload($branch->loadCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('status', 'active'),
            ])),
            'geofences' => $branch->geofences()
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (BranchGeofence $geofence): array => $this->geofencePayload($geofence))
                ->values(),
            'employees' => $this->branchEmployeeResolver
                ->getEmployeesByBranch((int) $branch->id)
                ->map(fn (User $employee): array => $this->branchEmployeeWithGeofencePayload($employee, (int) $branch->id))
                ->values(),
        ]);
    }

    public function store(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $employeeId = $request->filled('employee_id') ? (int) $request->input('employee_id') : null;
        if ($employeeId) {
            $employee = User::query()->findOrFail($employeeId);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        }

        $payload = $this->validatedGeofencePayload($request);

        $geofence = DB::transaction(function () use ($request, $branch, $payload, $employeeId): BranchGeofence {
            $create = [
                ...$payload,
                'company_id' => (int) $branch->company_id,
                'branch_id' => (int) $branch->id,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ];
            if ($employeeId) {
                $create['ownership_type'] = 'employee_specific';
                $create['owner_employee_id'] = $employeeId;
            }

            $geofence = BranchGeofence::query()->create($create);

            if ($employeeId) {
                $this->ensureEmployeeGeofenceAssignment($employeeId, $geofence, (int) $request->user()->id);
            }

            $this->audit($request, 'geofence_created', $branch, $geofence);

            return $geofence;
        });

        GeofenceValidationService::forgetBranchCache((int) $branch->id);

        return response()->json([
            'message' => $employeeId ? 'Employee geofence created.' : 'Geofence created.',
            'geofence' => $this->geofencePayload($geofence),
        ], 201);
    }

    public function storeFromPayload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        return $this->store($request, (int) $validated['branch_id']);
    }

    public function update(Request $request, int $branchId, int $geofenceId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $geofence = $branch->geofences()->whereKey($geofenceId)->first();
        if (! $geofence) {
            return response()->json([
                'message' => 'Geofence not found. Please refresh this branch and try again.',
            ], 404);
        }
        $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $employeeId = $request->filled('employee_id')
            ? (int) $request->input('employee_id')
            : ($geofence->owner_employee_id !== null ? (int) $geofence->owner_employee_id : null);
        if ($employeeId) {
            $employee = User::query()->findOrFail($employeeId);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        }

        $payload = $this->validatedGeofencePayload($request, partial: true);

        DB::transaction(function () use ($request, $branch, $geofence, $payload, $employeeId): void {
            if ($employeeId && Schema::hasColumn('branch_geofences', 'owner_employee_id')) {
                $payload['ownership_type'] = 'employee_specific';
                $payload['owner_employee_id'] = $employeeId;
            }

            $geofence->fill([
                ...$payload,
                'updated_by' => $request->user()?->id,
            ])->save();

            if ($employeeId) {
                $this->ensureEmployeeGeofenceAssignment($employeeId, $geofence, (int) $request->user()->id);
            }

            $this->audit($request, 'geofence_updated', $branch, $geofence);
        });

        GeofenceValidationService::forgetBranchCache((int) $branch->id);
        if ($geofence->owner_employee_id !== null) {
            EmployeeGeofenceResolver::forgetEmployeeCache((int) $geofence->owner_employee_id);
        }

        return response()->json([
            'message' => 'Geofence updated.',
            'geofence' => $this->geofencePayload($geofence->refresh()),
        ]);
    }

    public function updateFlat(Request $request, int $geofenceId): JsonResponse
    {
        $geofence = BranchGeofence::query()->find($geofenceId);
        if (! $geofence) {
            return response()->json([
                'message' => 'Geofence not found. Please refresh this branch and try again.',
            ], 404);
        }

        return $this->update($request, (int) $geofence->branch_id, $geofenceId);
    }

    public function destroy(Request $request, int $branchId, int $geofenceId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $geofence = $branch->geofences()->whereKey($geofenceId)->first();
        if (! $geofence) {
            return response()->json([
                'message' => 'Geofence not found. Please refresh this branch and try again.',
            ], 404);
        }

        DB::transaction(function () use ($request, $branch, $geofence): void {
            $this->audit($request, 'geofence_deleted', $branch, $geofence);
            $geofence->delete();
        });

        GeofenceValidationService::forgetBranchCache((int) $branch->id);

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function destroyFlat(Request $request, int $geofenceId): JsonResponse
    {
        $geofence = BranchGeofence::query()->find($geofenceId);
        if (! $geofence) {
            return response()->json([
                'message' => 'Geofence not found. Please refresh this branch and try again.',
            ], 404);
        }

        return $this->destroy($request, (int) $geofence->branch_id, $geofenceId);
    }

    public function updateBranchSettings(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $validated = $request->validate([
            'geofence_enabled' => ['sometimes', 'boolean'],
            'geofence_enforcement_mode' => ['sometimes', 'string', Rule::in(['disabled', 'warn_only', 'enforce'])],
            'geofence_no_active_policy' => ['sometimes', 'string', Rule::in(['allow', 'block'])],
            'geofence_accuracy_policy' => ['sometimes', 'string', Rule::in(['strict', 'balanced', 'lenient'])],
            'geofence_accuracy_buffer_mode' => ['sometimes', 'string', Rule::in(['strict', 'balanced', 'lenient'])],
            'geofence_poor_accuracy_action' => ['sometimes', 'string', Rule::in(['warn', 'block'])],
            'geofence_default_accuracy_threshold_meters' => ['sometimes', 'integer', 'min:5', 'max:5000'],
            'geofence_mobile_accuracy_threshold_meters' => ['sometimes', 'integer', 'min:5', 'max:5000'],
            'geofence_desktop_accuracy_threshold_meters' => ['sometimes', 'integer', 'min:5', 'max:5000'],
            'geofence_minimum_samples' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'geofence_maximum_samples' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'geofence_sample_timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:30'],
            'geofence_allow_cross_branch' => ['sometimes', 'boolean'],
            'geofence_require_backend_validation' => ['sometimes', 'boolean'],
            'allow_without_geofence' => ['sometimes', 'boolean'],
        ]);

        $allowWithoutGeofence = $validated['allow_without_geofence'] ?? null;
        unset($validated['allow_without_geofence']);

        if (isset($validated['geofence_minimum_samples'], $validated['geofence_maximum_samples'])
            && (int) $validated['geofence_minimum_samples'] > (int) $validated['geofence_maximum_samples']) {
            throw ValidationException::withMessages([
                'geofence_maximum_samples' => ['Maximum samples must be greater than or equal to minimum samples.'],
            ]);
        }

        $branch->fill($validated)->save();
        if ($allowWithoutGeofence !== null) {
            BranchGeofenceSetting::query()->updateOrCreate(
                ['branch_id' => (int) $branch->id],
                [
                    'allow_without_geofence' => (bool) $allowWithoutGeofence,
                    'updated_by' => $request->user()?->id,
                ],
            );
        }
        GeofenceValidationService::forgetBranchCache((int) $branch->id);

        $this->audit($request, 'geofence_branch_settings_updated', $branch, null, $validated);

        return response()->json([
            'message' => 'Geofence settings updated.',
            'branch' => $this->branchPayload($branch->loadCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('status', 'active'),
            ])),
        ]);
    }

    public function updateAttendanceWithoutGeofence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $branchQuery = Branch::query()->whereIn('id', $validated['branch_ids']);
        $this->dataScopeService->restrictBranchQuery($request->user(), $branchQuery);
        $accessibleIds = $branchQuery->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $requestedIds = collect($validated['branch_ids'])->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if (count($accessibleIds) !== count($requestedIds)) {
            abort(403, 'One or more selected branches are outside your data scope.');
        }

        DB::transaction(function () use ($request, $validated, $requestedIds): void {
            GeofenceGlobalSetting::query()->updateOrCreate(
                ['id' => 1],
                [
                    'geofence_module_enabled' => $this->geofenceValidation->geofenceModuleEnabled(),
                    'attendance_without_geofence_enabled' => (bool) $validated['enabled'],
                    'updated_by' => $request->user()?->id,
                ],
            );

            $scopedBranches = Branch::query();
            $this->dataScopeService->restrictBranchQuery($request->user(), $scopedBranches);
            $scopedIds = $scopedBranches->pluck('id')->map(fn ($id): int => (int) $id);
            foreach ($scopedIds as $branchId) {
                BranchGeofenceSetting::query()->updateOrCreate(
                    ['branch_id' => $branchId],
                    [
                        'allow_without_geofence' => in_array($branchId, $requestedIds, true),
                        'updated_by' => $request->user()?->id,
                    ],
                );
            }
        });

        $this->audit($request, 'geofence_attendance_without_geofence_updated', null, null, [
            'enabled' => (bool) $validated['enabled'],
            'branch_ids' => $requestedIds,
        ]);

        GeofenceValidationService::forgetGlobalCache();
        Cache::forget(GeofenceValidationService::scopedCacheKey('app:public-settings'));
        Cache::forget('app:public-settings');

        return response()->json([
            'message' => 'Attendance without geofence settings updated.',
            'attendance_without_geofence' => [
                'enabled' => (bool) $validated['enabled'],
                'branch_ids' => $requestedIds,
            ],
        ]);
    }

    public function validateAttendance(Request $request): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'clock_type' => ['nullable', 'string', Rule::in(['clock_in', 'clock_out', 'in', 'out'])],
            'clicked_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'device_type' => ['nullable', 'string', 'max:32'],
            'method' => ['nullable', 'string', 'max:32'],
            'sampled_readings_count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'selected_best_accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $employee = isset($validated['employee_id']) && $actor?->isAdmin()
            ? User::query()->findOrFail((int) $validated['employee_id'])
            : $actor;

        if ($employee && (int) $employee->id !== (int) $actor->id) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        }

        $result = $this->geofenceValidation->validateForEmployee(
            $employee,
            isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            isset($validated['longitude']) ? (float) $validated['longitude'] : null,
            isset($validated['accuracy_meters']) ? (float) $validated['accuracy_meters'] : null,
            [
                'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                'clock_type' => $validated['clock_type'] ?? null,
                'device_type' => $this->geofenceValidation->normalizeDeviceType(
                    $validated['device_type'] ?? $this->geofenceValidation->deviceTypeFromRequest($request)
                ),
                'method' => $validated['method'] ?? 'face',
                'sampled_readings_count' => $validated['sampled_readings_count'] ?? null,
                'selected_best_accuracy' => $validated['selected_best_accuracy'] ?? ($validated['accuracy_meters'] ?? null),
            ],
        );

        return response()->json($result);
    }

    public function liveMonitorEvents(Request $request): JsonResponse
    {
        return response()->json($this->geofenceLiveMonitor->recentEvents($request->user(), $request->query()));
    }

    public function liveMonitorSummary(Request $request): JsonResponse
    {
        return response()->json(['summary' => $this->geofenceLiveMonitor->summary($request->user(), $request->query())]);
    }

    public function liveMonitorEvent(Request $request, int $eventId): JsonResponse
    {
        return response()->json($this->geofenceLiveMonitor->event($request->user(), $eventId));
    }

    public function liveMonitorBoundaries(Request $request): JsonResponse
    {
        return response()->json(['boundaries' => $this->geofenceLiveMonitor->boundaries($request->user(), $request->query())]);
    }

    public function searchLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:255'],
            'mode' => ['nullable', 'string', Rule::in(['address', 'establishment', 'branch'])],
        ]);

        $query = trim(preg_replace('/\s+/', ' ', (string) $validated['query']));
        $mode = $validated['mode'] ?? 'address';
        $coordinatePair = $this->parseCoordinateQuery($query);
        if ($coordinatePair !== null) {
            $cacheKey = 'geofence:map_reverse:v1:'.sha1(number_format($coordinatePair['lat'], 7, '.', '').'|'.number_format($coordinatePair['lng'], 7, '.', ''));
            $results = Cache::remember($cacheKey, now()->addHour(), fn (): array => $this->nominatimReverse($coordinatePair['lat'], $coordinatePair['lng']));

            return response()->json([
                'query' => $query,
                'provider' => 'nominatim_reverse',
                'results' => $results,
            ]);
        }

        $localResults = $this->localBranchSearch($request, $query);
        $placeResults = [];

        if ($mode !== 'branch') {
            $cacheKey = 'geofence:map_search:v2:'.sha1(mb_strtolower($query).'|'.$mode);
            $placeResults = Cache::remember($cacheKey, now()->addHour(), fn (): array => $this->nominatimSearch($query, $mode));
        }

        return response()->json([
            'query' => $query,
            'provider' => 'nominatim',
            'results' => collect([...$localResults, ...$placeResults])->unique('id')->take(8)->values()->all(),
        ]);
    }

    public function osmPoiSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:100', 'max:10000'],
        ]);

        $query = trim(preg_replace('/\s+/', ' ', (string) $validated['query']));
        $center = $this->requestMapCenter($validated);
        $radius = (int) ($validated['radius'] ?? 5000);
        $bbox = $this->bboxAround($center['lat'], $center['lng'], $radius);
        $bboxHash = sha1(implode(',', array_map(static fn (float $value): string => number_format($value, 5, '.', ''), $bbox)));

        $branchResults = collect($this->localBranchSearch($request, $query))
            ->map(fn (array $row): array => $this->branchPoiPayload($row, $center))
            ->all();

        $cacheKey = 'overpass:search:'.sha1(mb_strtolower($query)).':'.$bboxHash;
        $osmResults = Cache::remember(
            $cacheKey,
            now()->addHours(12),
            fn (): array => $this->overpassPoiSearch($query, $bbox, $center)
        );

        return response()->json([
            'query' => $query,
            'center' => $center,
            'radius' => $radius,
            'provider' => 'overpass',
            'results' => collect([...$branchResults, ...$osmResults])
                ->unique('id')
                ->take(10)
                ->values()
                ->all(),
        ]);
    }

    public function osmPoiNearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:100', 'max:2000'],
        ]);

        $center = [
            'lat' => (float) $validated['lat'],
            'lng' => (float) $validated['lng'],
        ];
        $radius = (int) ($validated['radius'] ?? 500);
        $cacheKey = 'overpass:nearby:'.sha1(number_format($center['lat'], 5, '.', '').'|'.number_format($center['lng'], 5, '.', '').'|'.$radius);

        $results = Cache::remember(
            $cacheKey,
            now()->addHours(12),
            fn (): array => $this->overpassPoiNearby($center, $radius)
        );

        return response()->json([
            'center' => $center,
            'radius' => $radius,
            'provider' => 'overpass',
            'results' => $results,
        ]);
    }

    private function scopedBranch(Request $request, int $branchId): Branch
    {
        $query = Branch::query()->with('company:id,name,logo')->whereKey($branchId);
        $this->dataScopeService->restrictBranchQuery($request->user(), $query);

        return $query->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedGeofencePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $validated = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, 'string', Rule::in(['circle', 'polygon'])],
            'device_scope' => [$required, 'string', Rule::in(GeofenceValidationService::DEVICE_SCOPES)],
            'center_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'center_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:5', 'max:50000'],
            'polygon_geojson' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'active', 'inactive'])],
            'enforcement_mode' => ['nullable', 'string', Rule::in(['disabled', 'warn_only', 'enforce'])],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'accuracy_threshold_meters' => ['nullable', 'integer', 'min:5', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $type = $validated['type'] ?? $request->input('type');
        if ($type === 'circle') {
            foreach (['center_lat', 'center_lng', 'radius_meters'] as $field) {
                if (! $partial && ! array_key_exists($field, $validated)) {
                    throw ValidationException::withMessages([$field => ['This field is required for circle geofences.']]);
                }
            }
            if (array_key_exists('center_lat', $validated) || array_key_exists('center_lng', $validated) || ! $partial) {
                $this->validatePhilippinesCoordinatePair($validated['center_lat'] ?? null, $validated['center_lng'] ?? null);
            }
        }
        if ($type === 'polygon' && ! $partial && empty($validated['polygon_geojson'])) {
            throw ValidationException::withMessages(['polygon_geojson' => ['Draw polygon boundaries before saving.']]);
        }
        if ($type === 'polygon' && array_key_exists('polygon_geojson', $validated) && empty($validated['polygon_geojson'])) {
            throw ValidationException::withMessages(['polygon_geojson' => ['Draw polygon boundaries before saving.']]);
        }
        if ($type === 'polygon' && array_key_exists('polygon_geojson', $validated) && ! empty($validated['polygon_geojson'])) {
            $this->validatePolygonCoordinates($validated['polygon_geojson']);
            [$centerLat, $centerLng] = $this->polygonCenterCoordinates($validated['polygon_geojson']);
            $validated['center_lat'] = $centerLat;
            $validated['center_lng'] = $centerLng;
            $validated['radius_meters'] = null;
        } elseif ($type === 'polygon' && (array_key_exists('center_lat', $validated) || array_key_exists('center_lng', $validated))) {
            $this->validatePhilippinesCoordinatePair($validated['center_lat'] ?? null, $validated['center_lng'] ?? null);
        }

        if (isset($validated['name'])) {
            $validated['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('status', $validated)) {
            $validated['is_active'] = $validated['status'] === 'active';
        } elseif (array_key_exists('is_active', $validated)) {
            $validated['status'] = $validated['is_active'] ? 'active' : 'inactive';
        } elseif (! $partial) {
            $validated['status'] = 'draft';
            $validated['is_active'] = false;
        }
        if (array_key_exists('priority', $validated) || ! $partial) {
            $validated['priority'] = (int) ($validated['priority'] ?? 1);
        }
        if (array_key_exists('enforcement_mode', $validated) || ! $partial) {
            $validated['enforcement_mode'] = $validated['enforcement_mode'] ?? 'enforce';
        }
        if (array_key_exists('accuracy_threshold_meters', $validated) || ! $partial) {
            $validated['accuracy_threshold_meters'] = (int) ($validated['accuracy_threshold_meters'] ?? GeofenceValidationService::DEFAULT_ACCURACY_THRESHOLD_METERS);
        }

        return $validated;
    }

    private function validatePhilippinesCoordinatePair(mixed $latitude, mixed $longitude): void
    {
        if ($latitude === null || $longitude === null) {
            throw ValidationException::withMessages([
                'center_lat' => ['Latitude and longitude are required.'],
            ]);
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;
        if (! is_finite($lat) || ! is_finite($lng) || ($lat == 0.0 && $lng == 0.0)) {
            throw ValidationException::withMessages([
                'center_lat' => ['Coordinates must be valid and cannot be 0,0.'],
            ]);
        }

        if (! $this->withinPhilippinesBounds($lat, $lng)) {
            $swappedLooksValid = $this->withinPhilippinesBounds($lng, $lat);
            throw ValidationException::withMessages([
                'center_lat' => [$swappedLooksValid
                    ? 'Coordinates look swapped. Latitude must be first and longitude second.'
                    : 'Coordinates must be within the expected Philippines bounds.'],
            ]);
        }
    }

    private function validatePolygonCoordinates(array $geojson): void
    {
        $geometry = ($geojson['type'] ?? null) === 'Feature' ? ($geojson['geometry'] ?? null) : $geojson;
        $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
        if (($geometry['type'] ?? null) !== 'Polygon' || ! is_array($coordinates)) {
            throw ValidationException::withMessages([
                'polygon_geojson' => ['Polygon geofence must contain valid GeoJSON polygon coordinates.'],
            ]);
        }

        $outerRing = $coordinates[0] ?? null;
        if (! is_array($outerRing) || count($outerRing) < 4) {
            throw ValidationException::withMessages([
                'polygon_geojson' => ['Polygon geofence must have at least three boundary points.'],
            ]);
        }

        $validPointCount = 0;
        foreach ($coordinates as $ring) {
            if (! is_array($ring)) {
                continue;
            }
            foreach ($ring as $point) {
                if (! is_array($point) || count($point) < 2) {
                    throw ValidationException::withMessages([
                        'polygon_geojson' => ['Polygon contains an invalid coordinate point.'],
                    ]);
                }
                $this->validatePhilippinesCoordinatePair($point[1], $point[0]);
                $validPointCount++;
            }
        }

        if ($validPointCount < 4) {
            throw ValidationException::withMessages([
                'polygon_geojson' => ['Polygon geofence must have at least three boundary points.'],
            ]);
        }

        if (! $this->polygonRingIsClosed($outerRing)) {
            throw ValidationException::withMessages([
                'polygon_geojson' => ['Polygon boundary must be closed before saving.'],
            ]);
        }

        if ($this->polygonAreaSquareDegrees($outerRing) <= 0.0) {
            throw ValidationException::withMessages([
                'polygon_geojson' => ['Polygon boundary must cover an area.'],
            ]);
        }
    }

    private function polygonRingIsClosed(array $ring): bool
    {
        $first = $ring[0] ?? null;
        $last = $ring[count($ring) - 1] ?? null;
        if (! is_array($first) || ! is_array($last) || count($first) < 2 || count($last) < 2) {
            return false;
        }

        return abs((float) $first[0] - (float) $last[0]) < 0.0000001
            && abs((float) $first[1] - (float) $last[1]) < 0.0000001;
    }

    private function polygonAreaSquareDegrees(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $current = $ring[$i] ?? null;
            $previous = $ring[$j] ?? null;
            if (! is_array($current) || ! is_array($previous) || count($current) < 2 || count($previous) < 2) {
                continue;
            }
            $area += ((float) $previous[0] * (float) $current[1]) - ((float) $current[0] * (float) $previous[1]);
        }

        return abs($area / 2.0);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function polygonCenterCoordinates(array $geojson): array
    {
        $geometry = ($geojson['type'] ?? null) === 'Feature' ? ($geojson['geometry'] ?? null) : $geojson;
        $ring = is_array($geometry) ? ($geometry['coordinates'][0] ?? []) : [];
        $points = array_values(array_filter($ring, fn ($point) => is_array($point) && count($point) >= 2));
        if (count($points) >= 2 && $this->polygonRingIsClosed($points)) {
            array_pop($points);
        }

        $area = 0.0;
        $centroidLng = 0.0;
        $centroidLat = 0.0;
        $count = count($points);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $current = $points[$i];
            $previous = $points[$j];
            $lng = (float) $current[0];
            $lat = (float) $current[1];
            $prevLng = (float) $previous[0];
            $prevLat = (float) $previous[1];
            $cross = ($prevLng * $lat) - ($lng * $prevLat);
            $area += $cross;
            $centroidLng += ($prevLng + $lng) * $cross;
            $centroidLat += ($prevLat + $lat) * $cross;
        }

        if (abs($area) > 0.000000000001) {
            $factor = 1 / (3 * $area);

            return [
                round($centroidLat * $factor, 7),
                round($centroidLng * $factor, 7),
            ];
        }

        $latTotal = 0.0;
        $lngTotal = 0.0;
        foreach ($points as $point) {
            $lngTotal += (float) $point[0];
            $latTotal += (float) $point[1];
        }

        return [
            round($latTotal / max(1, $count), 7),
            round($lngTotal / max(1, $count), 7),
        ];
    }

    private function withinPhilippinesBounds(float $lat, float $lng): bool
    {
        return $lat >= 4.0 && $lat <= 22.0 && $lng >= 116.0 && $lng <= 127.5;
    }

    private function branchPayload(Branch $branch): array
    {
        $companyLogoUrl = $this->publicMediaUrl($branch->company?->logo);

        return [
            'id' => (int) $branch->id,
            'company_id' => (int) $branch->company_id,
            'company_name' => $branch->company?->name,
            'company_logo_url' => $companyLogoUrl,
            'logo' => $branch->company?->logo,
            'logo_url' => $companyLogoUrl,
            'branch_name' => $branch->name,
            'branch_code' => sprintf('BR-%04d', (int) $branch->id),
            'address' => $branch->address,
            'branch_latitude' => $branch->branch_latitude,
            'branch_longitude' => $branch->branch_longitude,
            'branch_address' => $branch->branch_address,
            'branch_city' => $branch->branch_city,
            'branch_province' => $branch->branch_province,
            'branch_postal_code' => $branch->branch_postal_code,
            'active_geofences_count' => (int) ($branch->active_geofences_count ?? $branch->geofences()->where('status', 'active')->count()),
            'geofences_count' => (int) ($branch->geofences_count ?? $branch->geofences()->count()),
            'employee_count' => $this->branchEmployeeResolver->countEmployeesByBranch((int) $branch->id),
            'assigned_employees_preview' => $this->branchEmployeeResolver->previewEmployeesByBranch((int) $branch->id),
            'geofence_enabled' => (bool) ($branch->geofence_enabled ?? true),
            'geofence_enforcement_mode' => $branch->geofence_enforcement_mode ?? 'enforce',
            'geofence_status' => (bool) ($branch->geofence_enabled ?? true) ? 'enabled' : 'disabled',
            'geofence_no_active_policy' => $branch->geofence_no_active_policy ?? 'block',
            'geofence_accuracy_policy' => $branch->geofence_accuracy_policy ?? 'balanced',
            'geofence_accuracy_buffer_mode' => $branch->geofence_accuracy_buffer_mode ?? 'strict',
            'geofence_poor_accuracy_action' => $branch->geofence_poor_accuracy_action ?? 'block',
            'geofence_default_accuracy_threshold_meters' => (int) ($branch->geofence_default_accuracy_threshold_meters ?? GeofenceValidationService::DEFAULT_ACCURACY_THRESHOLD_METERS),
            'geofence_mobile_accuracy_threshold_meters' => (int) ($branch->geofence_mobile_accuracy_threshold_meters ?? GeofenceValidationService::DEFAULT_MOBILE_ACCURACY_THRESHOLD_METERS),
            'geofence_desktop_accuracy_threshold_meters' => (int) ($branch->geofence_desktop_accuracy_threshold_meters ?? GeofenceValidationService::DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS),
            'geofence_minimum_samples' => (int) ($branch->geofence_minimum_samples ?? 3),
            'geofence_maximum_samples' => (int) ($branch->geofence_maximum_samples ?? 5),
            'geofence_sample_timeout_seconds' => (int) ($branch->geofence_sample_timeout_seconds ?? 15),
            'geofence_allow_cross_branch' => (bool) ($branch->geofence_allow_cross_branch ?? false),
            'geofence_require_backend_validation' => (bool) ($branch->geofence_require_backend_validation ?? true),
            'allowed_without_geofence' => (bool) ($branch->geofenceSettings?->allow_without_geofence
                ?? $branch->geofenceSettings()->value('allow_without_geofence')
                ?? false),
            'last_updated' => $branch->updated_at?->toIso8601String(),
        ];
    }

    private function geofencePayload(BranchGeofence $geofence): array
    {
        return [
            'id' => (int) $geofence->id,
            'company_id' => (int) $geofence->company_id,
            'branch_id' => (int) $geofence->branch_id,
            'name' => $geofence->name,
            'address' => $geofence->address,
            'ownership_type' => $geofence->ownership_type ?? 'shared',
            'owner_employee_id' => $geofence->owner_employee_id !== null ? (int) $geofence->owner_employee_id : null,
            'type' => $geofence->type,
            'device_scope' => $geofence->device_scope ?? 'all_devices',
            'center_lat' => $geofence->center_lat,
            'center_lng' => $geofence->center_lng,
            'radius_meters' => $geofence->radius_meters,
            'polygon_geojson' => $geofence->polygon_geojson,
            'is_active' => (bool) $geofence->is_active,
            'status' => $geofence->status ?? ((bool) $geofence->is_active ? 'active' : 'inactive'),
            'enforcement_mode' => $geofence->enforcement_mode ?? 'enforce',
            'priority' => (int) $geofence->priority,
            'accuracy_threshold_meters' => (int) $geofence->accuracy_threshold_meters,
            'notes' => $geofence->notes,
            'updated_at' => $geofence->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeAssignmentEmployeePayload(User $employee): array
    {
        return [
            'id' => (int) $employee->id,
            'name' => $employee->display_name,
            'employee_number' => $employee->employee_code ?: sprintf('EMP-%05d', (int) $employee->id),
            'company_name' => $employee->company?->name,
            'branch_name' => $employee->branch?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeAssignmentRow(User $employee): array
    {
        $summary = Schema::hasTable('employee_geofence_assignments')
            ? $this->employeeGeofenceResolver->employeeSummary((int) $employee->id)
            : ['resolved' => [], 'active_assignments' => []];

        $resolved = $summary['resolved'] ?? [];
        $active = collect($summary['active_assignments'] ?? []);

        if ($resolved['has_active_exemption'] ?? false) {
            $status = 'exempt';
        } elseif (($resolved['validation_mode'] ?? '') === 'location_only') {
            $status = 'location_only';
        } elseif ($active->where('geofence_id', '!=', null)->isNotEmpty()) {
            $status = 'active';
        } else {
            $status = 'none';
        }

        $primary = $active->first(fn (array $a): bool => ($a['is_primary'] ?? false))['geofence_name'] ?? null;

        if (! $primary && ! empty($resolved['allowed_geofences'])) {
            $primaryGeofence = collect($resolved['allowed_geofences'])->first(fn (array $g): bool => ($g['is_primary'] ?? false));
            $primary = $primaryGeofence['name'] ?? ($resolved['allowed_geofences'][0]['name'] ?? null);
        }

        $additional = $active
            ->filter(fn (array $a): bool => ! ($a['is_primary'] ?? false) && ($a['assignment_type'] ?? '') !== 'temporary' && ($a['assignment_type'] ?? '') !== 'exemption')
            ->pluck('geofence_name')
            ->filter()
            ->values()
            ->all();

        $temporary = $active
            ->filter(fn (array $a): bool => ($a['assignment_type'] ?? '') === 'temporary')
            ->pluck('geofence_name')
            ->filter()
            ->values()
            ->all();

        $start = $active->pluck('effective_start_date')->filter()->sort()->first();
        $end = $active->pluck('effective_end_date')->filter()->sort()->last();
        $effectivePeriod = $start
            ? $start.' – '.($end ?: 'ongoing')
            : null;

        return [
            'employee' => $this->employeeAssignmentEmployeePayload($employee),
            'primary_geofence' => $primary,
            'additional_geofences' => $additional,
            'temporary_geofences' => $temporary,
            'assigned_geofence_ids' => collect($summary['active_assignments'] ?? [])
                ->pluck('geofence_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'validation_mode' => $resolved['validation_mode'] ?? 'any_assigned_geofence',
            'effective_period' => $effectivePeriod,
            'status' => $status,
            'geofence_enforcement_enabled' => ($resolved['validation_mode'] ?? '') !== 'location_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchEmployeeWithGeofencePayload(User $employee, int $branchId): array
    {
        $payload = $this->branchEmployeeResolver->employeePayload($employee, $branchId);
        $row = $this->employeeAssignmentRow($employee);

        return [
            ...$payload,
            'primary_geofence' => $row['primary_geofence'],
            'additional_geofences' => $row['additional_geofences'],
            'temporary_geofences' => $row['temporary_geofences'],
            'assigned_geofence_ids' => $row['assigned_geofence_ids'] ?? [],
            'validation_mode' => $row['validation_mode'],
            'geofence_status' => $row['status'],
            'geofence_enforcement_enabled' => $row['geofence_enforcement_enabled'] ?? true,
        ];
    }

    private function ensureEmployeeGeofenceAssignment(int $employeeId, BranchGeofence $geofence, int $actorId): void
    {
        if (! Schema::hasTable('employee_geofence_assignments')) {
            return;
        }

        if (Schema::hasColumn('branch_geofences', 'owner_employee_id')) {
            $geofence->fill([
                'ownership_type' => 'employee_specific',
                'owner_employee_id' => $employeeId,
            ])->save();
        }

        $existing = EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('geofence_id', (int) $geofence->id)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->first();

        if ($existing) {
            EmployeeGeofenceResolver::forgetEmployeeCache($employeeId);

            return;
        }

        $hasPrimary = EmployeeGeofenceAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->whereNotIn('status', ['removed', 'replaced'])
            ->exists();

        EmployeeGeofenceAssignment::query()->create([
            'employee_id' => $employeeId,
            'geofence_id' => (int) $geofence->id,
            'assignment_type' => 'permanent',
            'validation_mode' => 'any_assigned_geofence',
            'is_primary' => ! $hasPrimary,
            'effective_start_date' => now()->toDateString(),
            'status' => 'active',
            'clock_in_applies' => true,
            'clock_out_applies' => true,
            'created_by' => $actorId,
        ]);

        EmployeeGeofenceResolver::forgetEmployeeCache($employeeId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeeExemptionCandidates(Request $request): array
    {
        $query = User::query()
            ->activeRoster()
            ->with([
                'branch:id,name,company_id',
                'departmentRelation:id,name,branch_id',
                'division:id,name,branch_id,company_id',
                'sectionUnit:id,name,branch_id,department_id,division_id,company_id',
            ]);

        $this->dataScopeService->restrictEmployeeQuery($request->user(), $query);

        return $query
            ->orderByLastName()
            ->get(['id', 'name', 'employee_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'branch_id', 'department_id', 'division_id', 'section_unit_id'])
            ->map(fn (User $employee): array => [
                'id' => (int) $employee->id,
                'name' => $employee->display_name,
                'employee_number' => $employee->employee_code ?: sprintf('EMP-%05d', (int) $employee->id),
                'profile_image_url' => $employee->profile_image_url,
                'branch_id' => $employee->branch_id ? (int) $employee->branch_id : null,
                'branch' => $employee->branch?->name,
                'department' => $employee->departmentRelation?->name,
                'division' => $employee->division?->name,
                'section_unit' => $employee->sectionUnit?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function localBranchSearch(Request $request, string $query): array
    {
        $branchQuery = Branch::query()
            ->where(function ($q) use ($query): void {
                $like = '%'.$query.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like);
                if (Schema::hasColumn('branches', 'branch_address')) {
                    $q->orWhere('branch_address', 'like', $like);
                }
            })
            ->limit(5);

        $this->dataScopeService->restrictBranchQuery($request->user(), $branchQuery);

        return $branchQuery->get()
            ->map(function (Branch $branch): ?array {
                $lat = $branch->branch_latitude ?? null;
                $lng = $branch->branch_longitude ?? null;
                if ($lat === null || $lng === null) {
                    return null;
                }

                return [
                    'id' => 'branch:'.$branch->id,
                    'label' => $branch->name,
                    'address' => $branch->branch_address ?: $branch->address,
                    'latitude' => (float) $lat,
                    'longitude' => (float) $lng,
                    'city' => $branch->branch_city,
                    'province' => $branch->branch_province,
                    'postal_code' => $branch->branch_postal_code,
                    'source' => 'branch',
                    'branch_id' => (int) $branch->id,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nominatimSearch(string $query, string $mode): array
    {
        $normalized = mb_strtolower($query);
        $queries = array_values(array_unique(array_filter([
            str_contains($normalized, 'davao') || str_contains($normalized, 'philippines')
                ? $query
                : $query.' Davao City Philippines',
            $query,
            str_contains($normalized, 'philippines') ? null : $query.' Philippines',
        ])));

        $merged = collect();
        foreach ($queries as $candidate) {
            $results = $this->nominatimRequest($candidate, $mode);
            if ($results !== []) {
                $merged = $merged->merge($results);
            }
        }

        return $merged
            ->unique('id')
            ->sortByDesc(fn (array $row): int => $this->searchResultScore($row, $query))
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function parseCoordinateQuery(string $query): ?array
    {
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)\s*$/', $query, $matches)) {
            return null;
        }

        $first = (float) $matches[1];
        $second = (float) $matches[2];
        $latLng = $this->withinPhilippinesBounds($first, $second) ? ['lat' => $first, 'lng' => $second] : null;
        $lngLat = $this->withinPhilippinesBounds($second, $first) ? ['lat' => $second, 'lng' => $first] : null;

        return $latLng ?? $lngLat;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nominatimRequest(string $query, string $mode): array
    {
        try {
            $params = [
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'dedupe' => 1,
                'limit' => 8,
                'countrycodes' => 'ph',
                'q' => $query,
            ];
            if ($mode === 'establishment') {
                $params['extratags'] = 1;
                $params['namedetails'] = 1;
            }

            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Language' => 'en',
                    'User-Agent' => config('app.name', 'HRIS').'/geofence-leaflet-search',
                ])
                ->get('https://nominatim.openstreetmap.org/search', $params);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json() ?: [])
                ->map(function (array $row): ?array {
                    $lat = isset($row['lat']) ? (float) $row['lat'] : null;
                    $lng = isset($row['lon']) ? (float) $row['lon'] : null;
                    if ($lat === null || $lng === null) {
                        return null;
                    }
                    $address = $row['address'] ?? [];

                    return [
                        'id' => 'osm:'.($row['osm_type'] ?? 'place').':'.($row['osm_id'] ?? md5((string) ($row['display_name'] ?? ''))),
                        'label' => $row['name'] ?? $row['display_name'] ?? 'Map result',
                        'address' => $row['display_name'] ?? null,
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'city' => $address['city'] ?? $address['town'] ?? $address['municipality'] ?? null,
                        'province' => $address['state'] ?? $address['province'] ?? null,
                        'country' => $address['country'] ?? null,
                        'importance' => isset($row['importance']) ? (float) $row['importance'] : null,
                        'source' => 'leaflet_search',
                    ];
                })
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nominatimReverse(float $lat, float $lng): array
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Language' => 'en',
                    'User-Agent' => config('app.name', 'HRIS').'/geofence-reverse-search',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'lat' => $lat,
                    'lon' => $lng,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $row = $response->json() ?: [];
            $address = $row['address'] ?? [];
            $label = $row['name']
                ?? $address['building']
                ?? $address['amenity']
                ?? $address['road']
                ?? $row['display_name']
                ?? 'Map result';

            return [[
                'id' => 'osm:reverse:'.($row['osm_type'] ?? 'place').':'.($row['osm_id'] ?? md5($lat.'|'.$lng)),
                'label' => $label,
                'address' => $row['display_name'] ?? null,
                'latitude' => $lat,
                'longitude' => $lng,
                'city' => $address['city'] ?? $address['town'] ?? $address['municipality'] ?? null,
                'province' => $address['state'] ?? $address['province'] ?? null,
                'country' => $address['country'] ?? null,
                'importance' => isset($row['importance']) ? (float) $row['importance'] : null,
                'source' => 'reverse_geocode',
            ]];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function searchResultScore(array $row, string $query): int
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $row['label'] ?? null,
            $row['address'] ?? null,
            $row['city'] ?? null,
            $row['province'] ?? null,
            $row['country'] ?? null,
        ])));
        $score = (int) round(((float) ($row['importance'] ?? 0)) * 100);

        foreach (preg_split('/\s+/', mb_strtolower($query)) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) >= 3 && str_contains($haystack, $token)) {
                $score += 10;
            }
        }

        if (str_contains($haystack, 'davao')) {
            $score += 20;
        }
        if (($row['source'] ?? null) === 'branch') {
            $score += 50;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{lat: float, lng: float}
     */
    private function requestMapCenter(array $validated): array
    {
        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;

        if ($lat !== null && $lng !== null && $lat !== 0.0 && $lng !== 0.0) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        // Davao City fallback keeps Overpass searches bounded and avoids slow global scans.
        return ['lat' => 7.0731, 'lng' => 125.6128];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function bboxAround(float $lat, float $lng, int $radiusMeters): array
    {
        $latDelta = $radiusMeters / 111320;
        $lngDelta = $radiusMeters / (111320 * max(0.2, cos(deg2rad($lat))));

        return [
            max(-90, $lat - $latDelta),
            max(-180, $lng - $lngDelta),
            min(90, $lat + $latDelta),
            min(180, $lng + $lngDelta),
        ];
    }

    /**
     * @param  array<string, mixed>  $center
     * @return list<array<string, mixed>>
     */
    private function overpassPoiSearch(string $query, array $bbox, array $center): array
    {
        $safeQuery = str_replace(['\\', '"'], ['\\\\', '\\"'], $query);
        [$south, $west, $north, $east] = $bbox;
        $bboxText = implode(',', [
            number_format($south, 7, '.', ''),
            number_format($west, 7, '.', ''),
            number_format($north, 7, '.', ''),
            number_format($east, 7, '.', ''),
        ]);
        $categoryClauses = $this->overpassCategoryClauses($query, $bboxText);

        $overpass = <<<OVERPASS
[out:json][timeout:25];
(
  node["name"~"{$safeQuery}",i]({$bboxText});
  way["name"~"{$safeQuery}",i]({$bboxText});
  relation["name"~"{$safeQuery}",i]({$bboxText});
{$categoryClauses}
);
out center tags 30;
OVERPASS;

        return $this->runOverpassQuery($overpass, $center);
    }

    private function overpassCategoryClauses(string $query, string $bboxText): string
    {
        $normalized = mb_strtolower($query);
        $clauses = [];
        $add = static function (string $selector) use (&$clauses, $bboxText): void {
            $clauses[] = "  node{$selector}({$bboxText});";
            $clauses[] = "  way{$selector}({$bboxText});";
            $clauses[] = "  relation{$selector}({$bboxText});";
        };

        if (str_contains($normalized, 'restaurant') || str_contains($normalized, 'cafe') || str_contains($normalized, 'jollibee') || str_contains($normalized, 'food')) {
            $add('["amenity"~"restaurant|cafe|fast_food"]');
        }
        if (str_contains($normalized, 'bank')) {
            $add('["amenity"="bank"]');
        }
        if (str_contains($normalized, 'school')) {
            $add('["amenity"="school"]');
            $add('["building"="school"]');
        }
        if (str_contains($normalized, 'hospital') || str_contains($normalized, 'doctor') || str_contains($normalized, 'clinic')) {
            $add('["amenity"~"hospital|clinic"]');
            $add('["healthcare"~"hospital|clinic"]');
            $add('["building"="hospital"]');
        }
        if (str_contains($normalized, 'mall') || str_contains($normalized, 'gaisano') || str_contains($normalized, 'abreeza') || str_contains($normalized, 'sm ')) {
            $add('["shop"="mall"]');
            $add('["building"~"retail|commercial"]');
        }
        if (str_contains($normalized, 'fuel') || str_contains($normalized, 'gas')) {
            $add('["amenity"="fuel"]');
        }
        if (str_contains($normalized, 'hotel')) {
            $add('["tourism"="hotel"]');
        }
        if (str_contains($normalized, 'office') || str_contains($normalized, 'government')) {
            $add('["office"]');
        }
        if (str_contains($normalized, 'building') || str_contains($normalized, 'commercial')) {
            $add('["building"]');
        }

        return implode("\n", array_unique($clauses));
    }

    /**
     * @param  array<string, float>  $center
     * @return list<array<string, mixed>>
     */
    private function overpassPoiNearby(array $center, int $radius): array
    {
        $lat = number_format((float) $center['lat'], 7, '.', '');
        $lng = number_format((float) $center['lng'], 7, '.', '');

        $overpass = <<<OVERPASS
[out:json][timeout:25];
(
  node["name"](around:{$radius},{$lat},{$lng});
  way["name"](around:{$radius},{$lat},{$lng});
  relation["name"](around:{$radius},{$lat},{$lng});
  node["amenity"](around:{$radius},{$lat},{$lng});
  way["amenity"](around:{$radius},{$lat},{$lng});
  node["shop"](around:{$radius},{$lat},{$lng});
  way["shop"](around:{$radius},{$lat},{$lng});
  node["office"](around:{$radius},{$lat},{$lng});
  way["office"](around:{$radius},{$lat},{$lng});
  node["tourism"="hotel"](around:{$radius},{$lat},{$lng});
  way["tourism"="hotel"](around:{$radius},{$lat},{$lng});
  node["healthcare"](around:{$radius},{$lat},{$lng});
  way["healthcare"](around:{$radius},{$lat},{$lng});
  way["building"](around:{$radius},{$lat},{$lng});
);
out center tags 80;
OVERPASS;

        return $this->runOverpassQuery($overpass, $center);
    }

    /**
     * @param  array<string, float>  $center
     * @return list<array<string, mixed>>
     */
    private function runOverpassQuery(string $query, array $center): array
    {
        try {
            $response = Http::timeout(28)
                ->connectTimeout(6)
                ->asForm()
                ->withHeaders([
                    'User-Agent' => config('app.name', 'HRIS').'/geofence-overpass-poi-search',
                ])
                ->post('https://overpass-api.de/api/interpreter', ['data' => $query]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('elements') ?: [])
                ->map(fn (array $element): ?array => $this->overpassElementPayload($element, $center))
                ->filter()
                ->unique('id')
                ->sortBy([
                    ['has_name', 'desc'],
                    ['distance_meters', 'asc'],
                ])
                ->take(10)
                ->map(function (array $row): array {
                    unset($row['has_name']);

                    return $row;
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, float>  $center
     * @return array<string, mixed>|null
     */
    private function overpassElementPayload(array $element, array $center): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $lat = isset($element['lat'])
            ? (float) $element['lat']
            : (isset($element['center']['lat']) ? (float) $element['center']['lat'] : null);
        $lng = isset($element['lon'])
            ? (float) $element['lon']
            : (isset($element['center']['lon']) ? (float) $element['center']['lon'] : null);

        if ($lat === null || $lng === null) {
            return null;
        }

        $name = trim((string) ($tags['name'] ?? $tags['brand'] ?? $tags['operator'] ?? ''));
        $category = $this->osmCategory($tags, (string) ($element['type'] ?? 'osm'));

        return [
            'id' => 'osm_poi:'.($element['type'] ?? 'element').':'.($element['id'] ?? md5((string) json_encode($element))),
            'osm_id' => $element['id'] ?? null,
            'osm_type' => $element['type'] ?? null,
            'label' => $name !== '' ? $name : $category['label'],
            'name' => $name !== '' ? $name : null,
            'category' => $category['key'],
            'category_label' => $category['label'],
            'address' => $this->osmAddress($tags),
            'latitude' => $lat,
            'longitude' => $lng,
            'distance_meters' => round($this->haversineMeters((float) $center['lat'], (float) $center['lng'], $lat, $lng)),
            'tags' => $tags,
            'source' => 'overpass',
            'has_name' => $name !== '',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $center
     * @return array<string, mixed>
     */
    private function branchPoiPayload(array $row, array $center): array
    {
        $lat = (float) $row['latitude'];
        $lng = (float) $row['longitude'];

        return [
            ...$row,
            'name' => $row['label'] ?? null,
            'category' => 'branch',
            'category_label' => 'Branch',
            'distance_meters' => round($this->haversineMeters((float) $center['lat'], (float) $center['lng'], $lat, $lng)),
            'osm_id' => null,
            'osm_type' => 'branch',
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     * @return array{key: string, label: string}
     */
    private function osmCategory(array $tags, string $osmType): array
    {
        $amenity = strtolower((string) ($tags['amenity'] ?? ''));
        $shop = strtolower((string) ($tags['shop'] ?? ''));
        $office = strtolower((string) ($tags['office'] ?? ''));
        $tourism = strtolower((string) ($tags['tourism'] ?? ''));
        $healthcare = strtolower((string) ($tags['healthcare'] ?? ''));
        $building = strtolower((string) ($tags['building'] ?? ''));

        if (in_array($amenity, ['restaurant', 'cafe', 'fast_food'], true)) return ['key' => 'restaurant', 'label' => 'Restaurant'];
        if ($amenity === 'bank') return ['key' => 'bank', 'label' => 'Bank'];
        if ($amenity === 'school' || $building === 'school') return ['key' => 'school', 'label' => 'School'];
        if (in_array($amenity, ['hospital', 'clinic'], true) || in_array($healthcare, ['hospital', 'clinic'], true) || $building === 'hospital') return ['key' => 'hospital', 'label' => 'Hospital'];
        if ($amenity === 'fuel') return ['key' => 'fuel', 'label' => 'Fuel Station'];
        if ($tourism === 'hotel') return ['key' => 'hotel', 'label' => 'Hotel'];
        if (in_array($shop, ['mall', 'supermarket', 'convenience'], true) || in_array($building, ['retail', 'commercial'], true)) return ['key' => 'mall', 'label' => $shop === 'mall' ? 'Mall' : 'Shop'];
        if ($office !== '') return ['key' => 'office', 'label' => 'Office'];
        if ($building !== '') return ['key' => 'building', 'label' => 'Building'];

        return ['key' => $osmType === 'way' ? 'building' : 'landmark', 'label' => $osmType === 'way' ? 'Building' : 'Landmark'];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function osmAddress(array $tags): ?string
    {
        $parts = array_filter([
            $tags['addr:housename'] ?? null,
            $tags['addr:housenumber'] ?? null,
            $tags['addr:street'] ?? null,
            $tags['addr:suburb'] ?? null,
            $tags['addr:city'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '');

        return $parts === [] ? null : implode(', ', array_map('strval', $parts));
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function audit(Request $request, string $action, ?Branch $branch, ?BranchGeofence $geofence = null, array $extra = []): void
    {
        $actorId = $request->user()?->id;
        if ($actorId === null) {
            return;
        }

        UserAdminActivityLog::query()->create([
            'subject_user_id' => $actorId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'meta' => [
                ...$extra,
                'branch_id' => $branch ? (int) $branch->id : null,
                'branch_name' => $branch?->name,
                'geofence_id' => $geofence?->id,
                'geofence_name' => $geofence?->name,
            ],
            'ip_address' => $request->ip(),
        ]);
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
        if (! Storage::disk('public')->exists($normalized)) {
            return null;
        }

        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }
}
