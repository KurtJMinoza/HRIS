<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\GeofenceValidationLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GeofenceValidationService
{
    public const DEFAULT_ACCURACY_THRESHOLD_METERS = 100;

    public const CACHE_TTL_SECONDS = 1200;

    public static function branchCacheKey(int $branchId): string
    {
        return "geofence:branch:{$branchId}";
    }

    public static function forgetBranchCache(int $branchId): void
    {
        Cache::forget(self::branchCacheKey($branchId));
    }

    /**
     * @param  array{branch_id?: int|null, clock_type?: string|null, device_type?: string|null, method?: string|null, attendance_log_id?: int|null, log?: bool}  $options
     * @return array<string, mixed>
     */
    public function validateForEmployee(
        User $employee,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters = null,
        array $options = [],
    ): array {
        $selectedBranchId = isset($options['branch_id']) ? (int) $options['branch_id'] : null;
        $assignedBranch = $this->resolveBranchForEmployee($employee);

        if ($selectedBranchId !== null && $assignedBranch && (int) $assignedBranch->id !== $selectedBranchId) {
            $selected = Branch::query()->with('company:id,name')->find($selectedBranchId);
            if (! $selected || ! $this->mayUseCrossBranch($employee, $assignedBranch, $selected)) {
                $mode = $this->branchEnforcementMode($assignedBranch);
                return $this->finalizeResult($assignedBranch, $latitude, $longitude, $accuracyMeters, [
                    ...$options,
                    'allowed' => $mode === 'warn_only',
                    'validation_status' => $mode === 'warn_only' ? 'warn_only' : 'failed',
                    'enforcement_mode' => $mode,
                    'failure_reason' => 'branch_mismatch',
                    'employee_id' => (int) $employee->id,
                    'user_id' => (int) $employee->id,
                    'company_id' => (int) ($assignedBranch->company_id ?? $employee->getEffectiveCompanyId()),
                    'attempted_branch_id' => $selectedBranchId,
                    'log' => $options['log'] ?? true,
                ]);
            }
        }

        $branch = $this->resolveBranchForEmployee(
            $employee,
            $selectedBranchId,
        );

        return $this->validateBranchLocation($branch, $latitude, $longitude, $accuracyMeters, [
            ...$options,
            'employee_id' => (int) $employee->id,
            'user_id' => (int) $employee->id,
            'company_id' => (int) ($branch?->company_id ?? $employee->getEffectiveCompanyId()),
            'attempted_branch_id' => $selectedBranchId,
        ]);
    }

    /**
     * @param  array{employee_id?: int|null, user_id?: int|null, company_id?: int|null, attempted_branch_id?: int|null, clock_type?: string|null, attendance_log_id?: int|null, device_type?: string|null, method?: string|null, log?: bool}  $options
     * @return array<string, mixed>
     */
    public function validateBranchLocation(
        ?Branch $branch,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters = null,
        array $options = [],
    ): array {
        $context = [
            'method' => $options['method'] ?? null,
            'device_type' => $options['device_type'] ?? null,
            'employee_id' => $options['employee_id'] ?? null,
            'user_id' => $options['user_id'] ?? ($options['employee_id'] ?? null),
            'company_id' => $options['company_id'] ?? ($branch?->company_id ? (int) $branch->company_id : null),
            'attempted_branch_id' => $options['attempted_branch_id'] ?? null,
            'clock_type' => $this->normalizeClockType($options['clock_type'] ?? null),
            'attendance_log_id' => $options['attendance_log_id'] ?? null,
            'log' => $options['log'] ?? true,
        ];

        if (! $branch) {
            return $this->finalizeResult(null, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => false,
                'failure_reason' => 'No branch assignment was found for this employee.',
                'validation_status' => 'failed',
            ]);
        }

        $enforcementMode = $this->branchEnforcementMode($branch);
        if ($enforcementMode === 'disabled') {
            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => true,
                'validation_status' => 'disabled',
                'enforcement_mode' => 'disabled',
                'failure_reason' => null,
            ]);
        }

        $geofences = $this->activeGeofencesForBranch((int) $branch->id);
        if ($geofences === []) {
            $noActivePolicy = $this->branchNoActivePolicy($branch);
            $allowed = $noActivePolicy === 'allow' || $enforcementMode === 'warn_only';
            $message = 'No active geofence is configured for this branch.';

            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => $allowed,
                'validation_status' => $allowed
                    ? ($enforcementMode === 'warn_only' ? 'warn_only' : 'warning')
                    : 'failed',
                'enforcement_mode' => $enforcementMode,
                'warning' => $allowed ? $message : null,
                'failure_reason' => $allowed ? null : $message,
            ]);
        }

        $threshold = $this->branchAccuracyThreshold($branch, $geofences);
        $poorAccuracy = $accuracyMeters !== null && $accuracyMeters > $threshold;

        $bestDistance = null;
        $bestCenterDistance = null;
        foreach ($geofences as $geofence) {
            $result = $this->checkGeofence($geofence, $latitude, $longitude, $accuracyMeters, $branch);
            if ($result['distance_to_center'] !== null) {
                $bestCenterDistance = $bestCenterDistance === null
                    ? $result['distance_to_center']
                    : min($bestCenterDistance, $result['distance_to_center']);
            }
            if ($result['distance'] !== null) {
                $bestDistance = $bestDistance === null ? $result['distance'] : min($bestDistance, $result['distance']);
            }
            if ($result['inside']) {
                if ($poorAccuracy && $this->branchPoorAccuracyAction($branch) === 'block' && $enforcementMode === 'enforce') {
                    return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                        ...$context,
                        'allowed' => false,
                        'validation_status' => 'failed',
                        'enforcement_mode' => $enforcementMode,
                        'failure_reason' => 'Location accuracy is too low for attendance validation.',
                        'matched_geofence' => $this->publicGeofencePayload($geofence),
                        'matched_geofence_id' => (int) $geofence['id'],
                        'distance' => $result['distance'],
                        'distance_to_center' => $result['distance_to_center'],
                    ]);
                }

                return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                    ...$context,
                    'allowed' => true,
                    'validation_status' => $poorAccuracy ? ($enforcementMode === 'warn_only' ? 'warn_only' : 'warning') : 'passed',
                    'enforcement_mode' => $enforcementMode,
                    'warning' => $poorAccuracy ? 'GPS accuracy is low, but coordinates are inside the branch geofence.' : null,
                    'matched_geofence' => $this->publicGeofencePayload($geofence),
                    'matched_geofence_id' => (int) $geofence['id'],
                    'distance' => $result['distance'],
                    'distance_to_center' => $result['distance_to_center'],
                ]);
            }
        }

        $outsideReason = $poorAccuracy && $this->branchPoorAccuracyAction($branch) === 'block'
            ? 'Location accuracy is low and no matching branch geofence was found.'
            : 'You are outside the allowed attendance geofence for this branch.';

        return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
            ...$context,
            'allowed' => $enforcementMode === 'warn_only',
            'validation_status' => $enforcementMode === 'warn_only' ? 'warn_only' : 'failed',
            'enforcement_mode' => $enforcementMode,
            'warning' => $enforcementMode === 'warn_only' ? $outsideReason : null,
            'failure_reason' => $outsideReason,
            'distance' => $bestDistance,
            'distance_to_center' => $bestCenterDistance,
        ]);
    }

    public function enforceForRequest(User $employee, \Illuminate\Http\Request $request, ?string $method = null): ?array
    {
        if (! Schema::hasTable('branch_geofences')) {
            return null;
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        if ($lat === null || $lng === null) {
            $branch = $this->resolveBranchForEmployee(
                $employee,
                $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
            );
            if (! $branch) {
                return null;
            }
            if ($this->branchGeofenceColumnsExist() && ! (bool) ($branch->geofence_enabled ?? true)) {
                return null;
            }
            if ($this->branchEnforcementMode($branch) !== 'disabled') {
                throw ValidationException::withMessages([
                    'geofence' => ['Location permission is required before attendance can continue.'],
                ]);
            }

            return null;
        }

        $result = $this->validateForEmployee(
            $employee,
            (float) $lat,
            (float) $lng,
            $request->input('accuracy_meters') !== null ? (float) $request->input('accuracy_meters') : null,
            [
                'branch_id' => $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
                'clock_type' => $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                'device_type' => $request->input('device_type') ?: $this->deviceTypeFromRequest($request),
                'method' => $method ?? $request->input('method'),
            ],
        );

        $request->attributes->set('geofence_result', $result);

        if (! ($result['allowed'] ?? false)) {
            throw ValidationException::withMessages([
                'geofence' => [$result['failure_reason'] ?? 'Attendance location is outside the allowed geofence.'],
            ]);
        }

        return $result;
    }

    public function deviceTypeFromRequest(\Illuminate\Http\Request $request): string
    {
        $ua = strtolower((string) $request->userAgent());

        return str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')
            ? 'mobile'
            : 'desktop';
    }

    public function resolveBranchForEmployee(User $employee, ?int $selectedBranchId = null): ?Branch
    {
        $employee->loadMissing([
            'branch',
            'departmentRelation.branch',
            'primaryOrganizationAssignment',
        ]);

        $primary = $employee->primaryOrganizationAssignment;
        $branchId = $primary?->branch_id ? (int) $primary->branch_id : null;

        if ($branchId === null && Schema::hasTable('employee_organization_assignments')) {
            $active = EmployeeOrganizationAssignment::query()
                ->active()
                ->where('employee_id', (int) $employee->id)
                ->whereNotNull('branch_id')
                ->orderByDesc('is_primary')
                ->orderByDesc('id')
                ->first();
            $branchId = $active?->branch_id ? (int) $active->branch_id : null;
        }

        $branchId ??= $employee->branch_id ? (int) $employee->branch_id : null;
        $branchId ??= $employee->departmentRelation?->branch_id ? (int) $employee->departmentRelation->branch_id : null;

        $branch = $branchId !== null ? Branch::query()->with('company:id,name')->find($branchId) : null;

        if ($selectedBranchId !== null && $selectedBranchId > 0) {
            $selected = Branch::query()->with('company:id,name')->find($selectedBranchId);
            if ($selected && $branch && (int) $selected->id === (int) $branch->id) {
                return $selected;
            }
            if ($selected && $this->mayUseCrossBranch($employee, $branch, $selected)) {
                return $selected;
            }
        }

        if ($branch) {
            return $branch;
        }

        $companyId = $employee->getEffectiveCompanyId();
        if ($companyId !== null) {
            return Branch::query()
                ->with('company:id,name')
                ->where('company_id', (int) $companyId)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeGeofencesForBranch(int $branchId): array
    {
        if (! Schema::hasTable('branch_geofences')) {
            return [];
        }

        return Cache::remember(self::branchCacheKey($branchId), self::CACHE_TTL_SECONDS, function () use ($branchId): array {
            $query = BranchGeofence::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true);

            if (Schema::hasColumn('branch_geofences', 'enforcement_mode')) {
                $query->where('enforcement_mode', '!=', 'disabled');
            }

            return $query
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (BranchGeofence $geofence): array => $geofence->toArray())
                ->all();
        });
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeResult(?Branch $branch, float $latitude, float $longitude, ?float $accuracyMeters, array $result): array
    {
        $distance = isset($result['distance']) && $result['distance'] !== null ? round((float) $result['distance'], 2) : null;
        $status = $result['validation_status'] ?? (($result['allowed'] ?? false) ? 'passed' : 'failed');
        $isInside = ($result['matched_geofence_id'] ?? null) !== null;
        $payload = [
            'allowed' => (bool) ($result['allowed'] ?? false),
            'status' => $this->publicStatus($status, $isInside),
            'message' => $result['failure_reason'] ?? $result['warning'] ?? (($result['allowed'] ?? false) ? 'Geofence validation passed.' : 'Geofence validation failed.'),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'matched_geofence' => $result['matched_geofence'] ?? null,
            'matched_geofence_id' => $result['matched_geofence_id'] ?? null,
            'distance' => $distance,
            'distance_meters' => $distance,
            'accuracy_meters' => $accuracyMeters,
            'failure_reason' => $result['failure_reason'] ?? null,
            'warning' => $result['warning'] ?? null,
            'validation_status' => $status,
            'enforcement_mode' => $result['enforcement_mode'] ?? ($branch ? $this->branchEnforcementMode($branch) : null),
            'branch' => $branch ? [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'company_id' => (int) $branch->company_id,
                'company_name' => $branch->company?->name,
            ] : null,
        ];

        if (($result['log'] ?? true) && Schema::hasTable('geofence_validation_logs')) {
            try {
                $logPayload = [
                    'employee_id' => $result['employee_id'] ?? null,
                    'branch_id' => $branch?->id,
                    'attendance_log_id' => $result['attendance_log_id'] ?? null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_meters' => $accuracyMeters,
                    'matched_geofence_id' => $result['matched_geofence_id'] ?? null,
                    'is_inside' => array_key_exists('is_inside', $result)
                        ? (bool) $result['is_inside']
                        : ($result['matched_geofence_id'] ?? null) !== null,
                    'distance_to_center' => $result['distance_to_center'] ?? $result['distance'] ?? null,
                    'validation_status' => $payload['validation_status'],
                    'failure_reason' => $payload['failure_reason'] ?? (($result['employee_id'] ?? null) === null ? 'employee_not_resolved' : null),
                    'device_type' => $result['device_type'] ?? null,
                    'method' => $result['method'] ?? null,
                ];

                foreach ([
                    'user_id' => $result['user_id'] ?? ($result['employee_id'] ?? null),
                    'company_id' => $result['company_id'] ?? ($branch?->company_id ? (int) $branch->company_id : null),
                    'attempted_branch_id' => $result['attempted_branch_id'] ?? null,
                    'clock_type' => $result['clock_type'] ?? null,
                    'enforcement_mode' => $payload['enforcement_mode'],
                    'distance_meters' => $payload['distance_meters'],
                ] as $column => $value) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $logPayload[$column] = $value;
                    }
                }

                $log = GeofenceValidationLog::query()->create($logPayload);
                $payload['geofence_validation_id'] = (int) $log->id;
                $payload['id'] = (int) $log->id;
            } catch (\Throwable $e) {
                Log::warning('Unable to write geofence validation log', [
                    'employee_id' => $result['employee_id'] ?? null,
                    'branch_id' => $branch?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::debug('Geofence validation evaluated', [
            'employee_id' => $result['employee_id'] ?? null,
            'branch_id' => $branch?->id,
            'geofence_count' => $branch ? count($this->activeGeofencesForBranch((int) $branch->id)) : 0,
            'enforcement_mode' => $payload['enforcement_mode'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracyMeters,
            'matched_geofence_id' => $payload['matched_geofence_id'],
            'distance_meters' => $payload['distance_meters'],
            'allowed' => $payload['allowed'],
            'block_reason' => $payload['allowed'] ? null : $payload['failure_reason'],
            'attendance_saved' => ($result['attendance_log_id'] ?? null) !== null,
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $geofence
     * @return array{inside: bool, distance: ?float, distance_to_center: ?float}
     */
    private function checkGeofence(array $geofence, float $latitude, float $longitude, ?float $accuracyMeters, Branch $branch): array
    {
        $type = strtolower((string) ($geofence['type'] ?? ''));
        if ($type === 'circle') {
            $centerLat = isset($geofence['center_lat']) ? (float) $geofence['center_lat'] : null;
            $centerLng = isset($geofence['center_lng']) ? (float) $geofence['center_lng'] : null;
            $radius = isset($geofence['radius_meters']) ? (float) $geofence['radius_meters'] : null;
            if ($centerLat === null || $centerLng === null || $radius === null) {
                return ['inside' => false, 'distance' => null, 'distance_to_center' => null];
            }
            $distance = $this->haversineMeters($centerLat, $centerLng, $latitude, $longitude);
            $allowance = $this->accuracyAllowance($branch, $accuracyMeters, (int) ($geofence['accuracy_threshold_meters'] ?? self::DEFAULT_ACCURACY_THRESHOLD_METERS));

            return [
                'inside' => $distance <= ($radius + $allowance),
                'distance' => $distance,
                'distance_to_center' => $distance,
            ];
        }

        if ($type === 'polygon') {
            $rings = $this->polygonRings($geofence['polygon_geojson'] ?? null);
            if ($rings === []) {
                return ['inside' => false, 'distance' => null, 'distance_to_center' => null];
            }

            $inside = $this->pointInPolygon($latitude, $longitude, $rings);
            $distance = $this->distanceToPolygonVertices($latitude, $longitude, $rings);

            return [
                'inside' => $inside,
                'distance' => $distance,
                'distance_to_center' => $distance,
            ];
        }

        return ['inside' => false, 'distance' => null, 'distance_to_center' => null];
    }

    private function accuracyAllowance(Branch $branch, ?float $accuracyMeters, int $threshold): float
    {
        $accuracy = max(0.0, (float) ($accuracyMeters ?? 0));

        return match ($this->branchAccuracyPolicy($branch)) {
            'strict' => 0.0,
            'lenient' => $accuracy,
            default => min($accuracy, max(0, $threshold)),
        };
    }

    private function branchNoActivePolicy(Branch $branch): string
    {
        $value = Schema::hasColumn('branches', 'geofence_no_active_policy')
            ? strtolower((string) ($branch->geofence_no_active_policy ?? 'block'))
            : 'block';

        return $value === 'allow' ? 'allow' : 'block';
    }

    private function branchAccuracyPolicy(Branch $branch): string
    {
        $value = Schema::hasColumn('branches', 'geofence_accuracy_policy')
            ? strtolower((string) ($branch->geofence_accuracy_policy ?? 'balanced'))
            : 'balanced';

        return in_array($value, ['strict', 'balanced', 'lenient'], true) ? $value : 'balanced';
    }

    private function branchPoorAccuracyAction(Branch $branch): string
    {
        $value = Schema::hasColumn('branches', 'geofence_poor_accuracy_action')
            ? strtolower((string) ($branch->geofence_poor_accuracy_action ?? 'block'))
            : 'block';

        return $value === 'warn' ? 'warn' : 'block';
    }

    private function branchEnforcementMode(Branch $branch): string
    {
        if ($this->branchGeofenceColumnsExist() && ! (bool) ($branch->geofence_enabled ?? true)) {
            return 'disabled';
        }

        $value = Schema::hasColumn('branches', 'geofence_enforcement_mode')
            ? strtolower((string) ($branch->geofence_enforcement_mode ?? 'enforce'))
            : 'enforce';

        return in_array($value, ['disabled', 'warn_only', 'enforce'], true) ? $value : 'enforce';
    }

    private function normalizeClockType(mixed $value): ?string
    {
        $type = strtolower(trim((string) $value));

        return in_array($type, ['clock_in', 'clock_out', 'in', 'out'], true)
            ? match ($type) {
                'in' => 'clock_in',
                'out' => 'clock_out',
                default => $type,
            }
            : null;
    }

    private function publicStatus(string $status, bool $isInside): string
    {
        return match ($status) {
            'passed' => 'inside',
            'warning' => 'warning',
            'warn_only' => $isInside ? 'warning' : 'outside',
            'disabled' => 'disabled',
            default => 'outside',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $geofences
     */
    private function branchAccuracyThreshold(Branch $branch, array $geofences): int
    {
        $policyThreshold = match ($this->branchAccuracyPolicy($branch)) {
            'strict' => 50,
            'lenient' => 300,
            default => 150,
        };
        $branchDefault = Schema::hasColumn('branches', 'geofence_default_accuracy_threshold_meters')
            ? (int) ($branch->geofence_default_accuracy_threshold_meters ?? $policyThreshold)
            : $policyThreshold;
        $thresholds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['accuracy_threshold_meters'] ?? 0),
            $geofences,
        ), static fn (int $n): bool => $n > 0));

        $configuredThreshold = max(1, min($thresholds !== [] ? min($thresholds) : $branchDefault, $branchDefault));

        return match ($this->branchAccuracyPolicy($branch)) {
            'strict' => min($configuredThreshold, $policyThreshold),
            'lenient' => max($configuredThreshold, $policyThreshold),
            default => max($configuredThreshold, $policyThreshold),
        };
    }

    private function mayUseCrossBranch(User $employee, ?Branch $assignedBranch, Branch $selected): bool
    {
        $sameCompany = $employee->getEffectiveCompanyId() !== null
            && (int) $selected->company_id === (int) $employee->getEffectiveCompanyId();
        if (! $sameCompany) {
            return false;
        }

        if ($assignedBranch && Schema::hasColumn('branches', 'geofence_allow_cross_branch')) {
            return (bool) ($assignedBranch->geofence_allow_cross_branch ?? false);
        }

        return false;
    }

    private function branchGeofenceColumnsExist(): bool
    {
        return Schema::hasColumn('branches', 'geofence_enabled');
    }

    private function publicGeofencePayload(array $geofence): array
    {
        return [
            'id' => (int) $geofence['id'],
            'name' => $geofence['name'],
            'type' => $geofence['type'],
            'priority' => (int) ($geofence['priority'] ?? 1),
        ];
    }

    /**
     * @return list<list<array{lat: float, lng: float}>>
     */
    private function polygonRings(mixed $geojson): array
    {
        if (is_string($geojson)) {
            $geojson = json_decode($geojson, true);
        }
        if (! is_array($geojson)) {
            return [];
        }

        $geometry = ($geojson['type'] ?? null) === 'Feature' ? ($geojson['geometry'] ?? null) : $geojson;
        if (! is_array($geometry)) {
            return [];
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (($geometry['type'] ?? null) === 'Polygon' && is_array($coordinates)) {
            return $this->normalizePolygonCoordinates($coordinates);
        }

        if (isset($geojson[0]) && is_array($geojson[0])) {
            return $this->normalizePolygonCoordinates($geojson);
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $coordinates
     * @return list<list<array{lat: float, lng: float}>>
     */
    private function normalizePolygonCoordinates(array $coordinates): array
    {
        $rings = [];
        foreach ($coordinates as $ring) {
            if (! is_array($ring)) {
                continue;
            }
            $points = [];
            foreach ($ring as $point) {
                if (! is_array($point) || count($point) < 2) {
                    continue;
                }
                $points[] = [
                    'lng' => (float) $point[0],
                    'lat' => (float) $point[1],
                ];
            }
            if (count($points) >= 3) {
                $rings[] = $points;
            }
        }

        return $rings;
    }

    /**
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     */
    private function pointInPolygon(float $latitude, float $longitude, array $rings): bool
    {
        $insideOuter = $this->pointInRing($latitude, $longitude, $rings[0] ?? []);
        if (! $insideOuter) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if ($this->pointInRing($latitude, $longitude, $hole)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $ring
     */
    private function pointInRing(float $latitude, float $longitude, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i]['lng'];
            $yi = $ring[$i]['lat'];
            $xj = $ring[$j]['lng'];
            $yj = $ring[$j]['lat'];

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < ($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 0.0000001) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @param  list<list<array{lat: float, lng: float}>>  $rings
     */
    private function distanceToPolygonVertices(float $latitude, float $longitude, array $rings): ?float
    {
        $best = null;
        foreach ($rings as $ring) {
            foreach ($ring as $point) {
                $distance = $this->haversineMeters($latitude, $longitude, $point['lat'], $point['lng']);
                $best = $best === null ? $distance : min($best, $distance);
            }
        }

        return $best;
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
}
