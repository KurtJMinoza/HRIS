<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\DataScopeService;
use App\Services\BranchEmployeeResolver;
use App\Services\GeofenceValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeofenceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly BranchEmployeeResolver $branchEmployeeResolver,
        private readonly GeofenceValidationService $geofenceValidation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Branch::query()
            ->with(['company:id,name,logo', 'area:id,area_name,company_id'])
            ->withCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('is_active', true),
            ]);

        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->input('company_id'));
        }

        $this->dataScopeService->restrictBranchQuery($request->user(), $query);

        $branches = $query->orderBy('name')->get()->map(fn (Branch $branch): array => $this->branchPayload($branch))->values();

        return response()->json([
            'branches' => $branches,
            'defaults' => [
                'accuracy_threshold_meters' => GeofenceValidationService::DEFAULT_ACCURACY_THRESHOLD_METERS,
                'enforcement_mode' => 'enforce',
                'no_active_policy' => 'block',
                'accuracy_policy' => 'balanced',
                'poor_accuracy_action' => 'block',
            ],
        ]);
    }

    public function branch(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);

        return response()->json([
            'branch' => $this->branchPayload($branch->loadCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('is_active', true),
            ])),
            'geofences' => $branch->geofences()
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (BranchGeofence $geofence): array => $this->geofencePayload($geofence))
                ->values(),
            'employees' => $this->branchEmployeeResolver
                ->getEmployeesByBranch((int) $branch->id)
                ->map(fn (User $employee): array => $this->branchEmployeeResolver->employeePayload($employee, (int) $branch->id))
                ->values(),
        ]);
    }

    public function store(Request $request, int $branchId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $payload = $this->validatedGeofencePayload($request);

        $geofence = DB::transaction(function () use ($request, $branch, $payload): BranchGeofence {
            $geofence = BranchGeofence::query()->create([
                ...$payload,
                'company_id' => (int) $branch->company_id,
                'branch_id' => (int) $branch->id,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $this->audit($request, 'geofence_created', $branch, $geofence);

            return $geofence;
        });

        return response()->json([
            'message' => 'Geofence created.',
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
        $geofence = $branch->geofences()->whereKey($geofenceId)->firstOrFail();
        $payload = $this->validatedGeofencePayload($request, partial: true);

        DB::transaction(function () use ($request, $branch, $geofence, $payload): void {
            $geofence->fill([
                ...$payload,
                'updated_by' => $request->user()?->id,
            ])->save();
            $this->audit($request, 'geofence_updated', $branch, $geofence);
        });

        return response()->json([
            'message' => 'Geofence updated.',
            'geofence' => $this->geofencePayload($geofence->refresh()),
        ]);
    }

    public function updateFlat(Request $request, int $geofenceId): JsonResponse
    {
        $geofence = BranchGeofence::query()->findOrFail($geofenceId);

        return $this->update($request, (int) $geofence->branch_id, $geofenceId);
    }

    public function destroy(Request $request, int $branchId, int $geofenceId): JsonResponse
    {
        $branch = $this->scopedBranch($request, $branchId);
        $geofence = $branch->geofences()->whereKey($geofenceId)->firstOrFail();

        DB::transaction(function () use ($request, $branch, $geofence): void {
            $this->audit($request, 'geofence_deleted', $branch, $geofence);
            $geofence->delete();
        });

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function destroyFlat(Request $request, int $geofenceId): JsonResponse
    {
        $geofence = BranchGeofence::query()->findOrFail($geofenceId);

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
            'geofence_poor_accuracy_action' => ['sometimes', 'string', Rule::in(['warn', 'block'])],
            'geofence_default_accuracy_threshold_meters' => ['sometimes', 'integer', 'min:5', 'max:5000'],
            'geofence_allow_cross_branch' => ['sometimes', 'boolean'],
        ]);

        $branch->fill($validated)->save();
        GeofenceValidationService::forgetBranchCache((int) $branch->id);

        $this->audit($request, 'geofence_branch_settings_updated', $branch, null, $validated);

        return response()->json([
            'message' => 'Geofence settings updated.',
            'branch' => $this->branchPayload($branch->loadCount([
                'geofences',
                'geofences as active_geofences_count' => fn ($q) => $q->where('is_active', true),
            ])),
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
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'device_type' => ['nullable', 'string', 'max:32'],
            'method' => ['nullable', 'string', 'max:32'],
        ]);

        $employee = isset($validated['employee_id']) && $actor?->isAdmin()
            ? User::query()->findOrFail((int) $validated['employee_id'])
            : $actor;

        if ($employee && (int) $employee->id !== (int) $actor->id) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        }

        $result = $this->geofenceValidation->validateForEmployee(
            $employee,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['accuracy_meters']) ? (float) $validated['accuracy_meters'] : null,
            [
                'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
                'clock_type' => $validated['clock_type'] ?? null,
                'device_type' => $validated['device_type'] ?? $this->geofenceValidation->deviceTypeFromRequest($request),
                'method' => $validated['method'] ?? 'face',
            ],
        );

        return response()->json($result);
    }

    public function searchLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:255'],
            'mode' => ['nullable', 'string', Rule::in(['address', 'establishment', 'branch'])],
        ]);

        $query = trim(preg_replace('/\s+/', ' ', (string) $validated['query']));
        $mode = $validated['mode'] ?? 'address';
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
            'center_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'center_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:5', 'max:50000'],
            'polygon_geojson' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
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
        }
        if ($type === 'polygon' && ! $partial && empty($validated['polygon_geojson'])) {
            throw ValidationException::withMessages(['polygon_geojson' => ['Draw polygon boundaries before saving.']]);
        }

        if (isset($validated['name'])) {
            $validated['name'] = trim((string) $validated['name']);
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
            'active_geofences_count' => (int) ($branch->active_geofences_count ?? $branch->geofences()->where('is_active', true)->count()),
            'geofences_count' => (int) ($branch->geofences_count ?? $branch->geofences()->count()),
            'employee_count' => $this->branchEmployeeResolver->countEmployeesByBranch((int) $branch->id),
            'assigned_employees_preview' => $this->branchEmployeeResolver->previewEmployeesByBranch((int) $branch->id),
            'geofence_enabled' => (bool) ($branch->geofence_enabled ?? true),
            'geofence_enforcement_mode' => $branch->geofence_enforcement_mode ?? 'enforce',
            'geofence_status' => (bool) ($branch->geofence_enabled ?? true) ? 'enabled' : 'disabled',
            'geofence_no_active_policy' => $branch->geofence_no_active_policy ?? 'block',
            'geofence_accuracy_policy' => $branch->geofence_accuracy_policy ?? 'balanced',
            'geofence_poor_accuracy_action' => $branch->geofence_poor_accuracy_action ?? 'block',
            'geofence_default_accuracy_threshold_meters' => (int) ($branch->geofence_default_accuracy_threshold_meters ?? GeofenceValidationService::DEFAULT_ACCURACY_THRESHOLD_METERS),
            'geofence_allow_cross_branch' => (bool) ($branch->geofence_allow_cross_branch ?? false),
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
            'type' => $geofence->type,
            'center_lat' => $geofence->center_lat,
            'center_lng' => $geofence->center_lng,
            'radius_meters' => $geofence->radius_meters,
            'polygon_geojson' => $geofence->polygon_geojson,
            'is_active' => (bool) $geofence->is_active,
            'enforcement_mode' => $geofence->enforcement_mode ?? 'enforce',
            'priority' => (int) $geofence->priority,
            'accuracy_threshold_meters' => (int) $geofence->accuracy_threshold_meters,
            'notes' => $geofence->notes,
            'updated_at' => $geofence->updated_at?->toIso8601String(),
        ];
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

        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }
}
