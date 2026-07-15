<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchGeofence;
use App\Models\BranchGeofenceSetting;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\GeofenceGlobalSetting;
use App\Models\GeofenceValidationLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GeofenceValidationService
{
    public const DEFAULT_ACCURACY_THRESHOLD_METERS = 100;

    public const DEFAULT_MOBILE_ACCURACY_THRESHOLD_METERS = 50;

    public const DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS = 100;

    public const CACHE_TTL_SECONDS = 1200;

    public const VALIDATION_TTL_MINUTES = 2;

    public const DEVICE_SCOPES = [
        'all_devices',
        'desktop_laptop',
        'mobile_tablet',
        'desktop',
        'laptop',
        'mobile',
        'tablet',
        'kiosk',
    ];

    public const DEVICE_TYPES = ['desktop', 'laptop', 'mobile', 'tablet', 'kiosk'];

    public static function branchCacheKey(int $branchId): string
    {
        return "geofence:branch:{$branchId}";
    }

    public static function forgetBranchCache(int $branchId): void
    {
        Cache::forget(self::branchCacheKey($branchId));
    }

    public static function forgetGlobalCache(): void
    {
        Cache::forget('geofence:global-settings');
        Cache::forget('geofence:global-settings:module-enabled');
        Cache::forget('geofence:global-settings:attendance-without-geofence-enabled');
        Cache::forget('geofence:global-settings:employee-exemption-ids');
    }

    /**
     * @param  array{branch_id?: int|null, clock_type?: string|null, device_type?: string|null, method?: string|null, attendance_log_id?: int|null, log?: bool}  $options
     * @return array<string, mixed>
     */
    public function validateForEmployee(
        User $employee,
        ?float $latitude,
        ?float $longitude,
        ?float $accuracyMeters = null,
        array $options = [],
    ): array {
        $selectedBranchId = isset($options['branch_id']) ? (int) $options['branch_id'] : null;
        $assignedBranch = $this->resolveBranchForEmployee($employee);

        if (! $this->geofenceModuleEnabled()) {
            $branch = $this->resolveBranchForEmployee($employee, $selectedBranchId);

            return $this->moduleDisabledResult($branch ?? $assignedBranch, [
                ...$options,
                'employee_id' => (int) $employee->id,
                'user_id' => (int) $employee->id,
                'company_id' => (int) (($branch ?? $assignedBranch)?->company_id ?? $employee->getEffectiveCompanyId()),
                'attempted_branch_id' => $selectedBranchId,
            ]);
        }

        if ($this->employeeExemptFromGeofence($employee)) {
            $branch = $this->resolveBranchForEmployee($employee, $selectedBranchId);

            return $this->finalizeResult($branch ?? $assignedBranch, null, null, null, [
                ...$options,
                'allowed' => true,
                'validation_status' => 'skipped',
                'enforcement_mode' => 'disabled',
                'skip_reason' => 'employee_geofence_exempt',
                'failure_reason' => null,
                'message' => 'This employee is exempt from geofence validation.',
                'suppress_location_capture' => true,
                'employee_id' => (int) $employee->id,
                'user_id' => (int) $employee->id,
                'company_id' => (int) (($branch ?? $assignedBranch)?->company_id ?? $employee->getEffectiveCompanyId()),
                'attempted_branch_id' => $selectedBranchId,
                'log' => $options['log'] ?? true,
            ]);
        }

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
        ?float $latitude,
        ?float $longitude,
        ?float $accuracyMeters = null,
        array $options = [],
    ): array {
        if (! $this->geofenceModuleEnabled()) {
            return $this->moduleDisabledResult($branch, $options);
        }

        $context = [
            'method' => $options['method'] ?? null,
            'device_type' => $this->normalizeDeviceType($options['device_type'] ?? null),
            'employee_id' => $options['employee_id'] ?? null,
            'user_id' => $options['user_id'] ?? ($options['employee_id'] ?? null),
            'company_id' => $options['company_id'] ?? ($branch?->company_id ? (int) $branch->company_id : null),
            'attempted_branch_id' => $options['attempted_branch_id'] ?? null,
            'clock_type' => $this->normalizeClockType($options['clock_type'] ?? null),
            'attendance_log_id' => $options['attendance_log_id'] ?? null,
            'log' => $options['log'] ?? true,
            'sampled_readings_count' => isset($options['sampled_readings_count']) ? (int) $options['sampled_readings_count'] : null,
            'selected_best_accuracy' => isset($options['selected_best_accuracy']) ? (float) $options['selected_best_accuracy'] : $accuracyMeters,
        ];

        if (! $branch) {
            return $this->finalizeResult(null, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => false,
                'failure_reason' => 'No branch assignment was found for this employee.',
                'validation_status' => 'failed',
            ]);
        }

        if ($this->branchAllowsWithoutGeofence($branch)) {
            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => true,
                'validation_status' => 'skipped',
                'skip_reason' => 'branch_allowed_without_geofence',
                'failure_reason' => null,
            ]);
        }

        $enforcementMode = $this->branchEnforcementMode($branch);
        if ($enforcementMode === 'disabled') {
            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => true,
                'validation_status' => 'skipped',
                'enforcement_mode' => 'disabled',
                'skip_reason' => 'branch_geofence_disabled',
                'failure_reason' => null,
            ]);
        }

        if ($latitude === null || $longitude === null) {
            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => false,
                'validation_status' => 'blocked',
                'enforcement_mode' => $enforcementMode,
                'failure_reason' => 'Location permission is required before attendance can continue.',
            ]);
        }

        $geofences = $this->matchingActiveGeofencesForBranch(
            (int) $branch->id,
            $context['device_type'],
        );
        if ($geofences === []) {
            return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                ...$context,
                'allowed' => false,
                'validation_status' => 'blocked',
                'enforcement_mode' => $enforcementMode,
                'failure_reason' => 'No active geofence matches this device for the employee branch.',
            ]);
        }

        $bestDistance = null;
        $bestCenterDistance = null;
        $bestGeofence = null;
        $bestGeofenceResult = null;
        $reportedThreshold = null;
        foreach ($geofences as $geofence) {
            $result = $this->checkGeofence($geofence, $latitude, $longitude, $accuracyMeters, $branch);
            $threshold = $this->geofenceAccuracyThreshold($branch, $geofence, $context['device_type']);
            $reportedThreshold = $reportedThreshold === null ? $threshold : min($reportedThreshold, $threshold);
            $poorAccuracy = $accuracyMeters !== null && $accuracyMeters > $threshold;
            if ($result['distance_to_center'] !== null) {
                $bestCenterDistance = $bestCenterDistance === null
                    ? $result['distance_to_center']
                    : min($bestCenterDistance, $result['distance_to_center']);
            }
            if ($result['distance'] !== null) {
                if ($bestDistance === null || $result['distance'] < $bestDistance) {
                    $bestDistance = $result['distance'];
                    $bestGeofence = $geofence;
                    $bestGeofenceResult = $result;
                }
            }
            if ($result['inside']) {
                if ($poorAccuracy && $enforcementMode === 'enforce') {
                    return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                        ...$context,
                        'allowed' => false,
                        'validation_status' => 'blocked',
                        'enforcement_mode' => $enforcementMode,
                        'failure_reason' => 'Location accuracy is too low. Please enable WiFi/location services and try again.',
                        'matched_geofence' => $this->publicGeofencePayload($geofence),
                        'matched_geofence_id' => (int) $geofence['id'],
                        'distance' => $result['distance'],
                        'distance_to_center' => $result['distance_to_center'],
                        'radius_meters' => $result['radius_meters'],
                        'geofence_type' => $result['geofence_type'],
                        'device_scope_matched' => $geofence['device_scope'] ?? 'all_devices',
                        'geofence_name' => $geofence['name'] ?? null,
                        'accuracy_threshold_meters' => $threshold,
                    ]);
                }

                return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
                    ...$context,
                    'allowed' => true,
                    'validation_status' => $poorAccuracy ? 'warn_only' : 'inside',
                    'enforcement_mode' => $enforcementMode,
                    'warning' => $poorAccuracy ? 'GPS accuracy is low, but coordinates are inside the branch geofence.' : null,
                    'matched_geofence' => $this->publicGeofencePayload($geofence),
                    'matched_geofence_id' => (int) $geofence['id'],
                    'distance' => $result['distance'],
                    'distance_to_center' => $result['distance_to_center'],
                    'radius_meters' => $result['radius_meters'],
                    'geofence_type' => $result['geofence_type'],
                    'device_scope_matched' => $geofence['device_scope'] ?? 'all_devices',
                    'geofence_name' => $geofence['name'] ?? null,
                    'accuracy_threshold_meters' => $threshold,
                ]);
            }
        }

        $poorAccuracy = $accuracyMeters !== null
            && $reportedThreshold !== null
            && $accuracyMeters > $reportedThreshold;
        $outsideReason = $poorAccuracy && $this->branchPoorAccuracyAction($branch) === 'block'
            ? 'Location accuracy is low and no matching branch geofence was found.'
            : 'You are outside the allowed attendance geofence for this branch.';

        return $this->finalizeResult($branch, $latitude, $longitude, $accuracyMeters, [
            ...$context,
            'allowed' => $enforcementMode === 'warn_only',
            'validation_status' => $enforcementMode === 'warn_only' ? 'warn_only' : 'outside',
            'enforcement_mode' => $enforcementMode,
            'warning' => $enforcementMode === 'warn_only' ? $outsideReason : null,
            'failure_reason' => $outsideReason,
            'distance' => $bestDistance,
            'distance_to_center' => $bestCenterDistance,
            'accuracy_threshold_meters' => $reportedThreshold,
            'matched_geofence' => $bestGeofence ? $this->publicGeofencePayload($bestGeofence) : null,
            'matched_geofence_id' => $bestGeofence['id'] ?? null,
            'geofence_name' => $bestGeofence['name'] ?? null,
            'device_scope_matched' => $bestGeofence['device_scope'] ?? null,
            'radius_meters' => $bestGeofenceResult['radius_meters'] ?? null,
            'geofence_type' => $bestGeofenceResult['geofence_type'] ?? null,
            'is_inside' => false,
        ]);
    }

    public function enforceForRequest(User $employee, \Illuminate\Http\Request $request, ?string $method = null): ?array
    {
        if (! Schema::hasTable('branch_geofences')) {
            return null;
        }

        $branch = $this->resolveBranchForEmployee(
            $employee,
            $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
        );
        $deviceType = $request->input('device_type') ?: $this->deviceTypeFromRequest($request);

        if (! $this->geofenceModuleEnabled()) {
            $result = $this->moduleDisabledResult($branch, [
                'employee_id' => (int) $employee->id,
                'user_id' => (int) $employee->id,
                'company_id' => (int) ($branch?->company_id ?? $employee->getEffectiveCompanyId()),
                'attempted_branch_id' => $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
                'clock_type' => $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                'device_type' => $deviceType,
                'method' => $method ?? $request->input('method'),
            ]);
            $request->attributes->set('geofence_result', $result);

            return $result;
        }

        if ($this->employeeExemptFromGeofence($employee)) {
            $result = $this->validateForEmployee(
                $employee,
                null,
                null,
                null,
                [
                    'branch_id' => $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
                    'clock_type' => $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                    'device_type' => $deviceType,
                    'method' => $method ?? $request->input('method'),
                ],
            );
            $request->attributes->set('geofence_result', $result);

            return $result;
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $validationId = $request->input('geofence_validation_id');

        if ($branch && $this->branchAllowsWithoutGeofence($branch)) {
            if ($validationId !== null) {
                $result = $this->consumeValidationLog(
                    $employee,
                    (int) $validationId,
                    $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                    $method ?? $request->input('method'),
                    $branch,
                );
                $request->attributes->set('geofence_result', $result);

                if (! ($result['allowed'] ?? false)) {
                    $this->recordLiveMonitorBlockedAttemptFromResult($result, $request, (int) $validationId);
                    abort(403, $result['failure_reason'] ?? 'Attendance without geofence is not authorized.');
                }

                return $result;
            }

            $result = $this->validateForEmployee(
                $employee,
                $lat !== null ? (float) $lat : null,
                $lng !== null ? (float) $lng : null,
                $request->input('accuracy_meters') !== null ? (float) $request->input('accuracy_meters') : null,
                [
                    'branch_id' => $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
                    'clock_type' => $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                    'device_type' => $deviceType,
                    'method' => $method ?? $request->input('method'),
                ],
            );
            $request->attributes->set('geofence_result', $result);

            return $result;
        }

        if ($lat === null || $lng === null) {
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

        $enforcementMode = $branch ? $this->branchEnforcementMode($branch) : 'enforce';
        $allowInlineValidation = $request->headers->has('X-Kiosk-Attendance')
            || $request->attributes->get('allow_inline_geofence_validation') === true;
        if ($validationId !== null) {
            $result = $this->consumeValidationLog(
                $employee,
                (int) $validationId,
                $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                $method ?? $request->input('method'),
                $branch,
            );
            $request->attributes->set('geofence_result', $result);

            if (! ($result['allowed'] ?? false)) {
                $this->recordLiveMonitorBlockedAttemptFromResult($result, $request, (int) $validationId);
                abort(403, $result['failure_reason'] ?? 'You are outside the allowed attendance geofence.');
            }

            return $result;
        }

        if ($branch && $enforcementMode !== 'disabled' && $this->branchRequiresBackendValidation($branch) && ! $allowInlineValidation) {
            throw ValidationException::withMessages([
                'geofence_validation_id' => ['A valid geofence validation record is required before attendance can be saved.'],
            ]);
        }

        $result = $this->validateForEmployee(
            $employee,
            (float) $lat,
            (float) $lng,
            $request->input('accuracy_meters') !== null ? (float) $request->input('accuracy_meters') : null,
            [
                'branch_id' => $request->input('branch_id') !== null ? (int) $request->input('branch_id') : null,
                'clock_type' => $this->normalizeClockType($request->input('clock_type') ?: $request->input('type')),
                'device_type' => $deviceType,
                'method' => $method ?? $request->input('method'),
            ],
        );

        $request->attributes->set('geofence_result', $result);

        if (! ($result['allowed'] ?? false)) {
            $this->recordLiveMonitorBlockedAttemptFromResult($result, $request);
            abort(403, $result['failure_reason'] ?? 'You are outside the allowed attendance geofence.');
        }

        return $result;
    }

    public function deviceTypeFromRequest(\Illuminate\Http\Request $request): string
    {
        $configured = $request->header('X-Device-Type');
        if ($configured !== null) {
            return $this->normalizeDeviceType($configured);
        }

        $ua = strtolower((string) $request->userAgent());

        if ($request->headers->has('X-Kiosk-Attendance')) {
            return 'kiosk';
        }
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }

        return str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')
            ? 'mobile'
            : 'desktop';
    }

    public function consumeValidationLog(User $employee, int $validationId, ?string $clockType, ?string $method, ?Branch $branch = null): array
    {
        $validation = GeofenceValidationLog::query()->find($validationId);
        if (! $validation) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'A valid geofence validation record is required before attendance can be saved.',
            ];
        }

        if ($validation->attendance_log_id !== null) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation has already been used. Please try attendance again.',
            ];
        }

        $expiresAt = $validation->expires_at ?? $validation->created_at?->copy()->addMinutes(self::VALIDATION_TTL_MINUTES);
        if (! $expiresAt || $expiresAt->lt(now())) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation expired. Please try attendance again.',
            ];
        }

        if ((int) ($validation->employee_id ?? $validation->user_id ?? 0) !== (int) $employee->id) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation does not match this employee.',
            ];
        }

        $branch ??= $this->resolveBranchForEmployee($employee, $validation->attempted_branch_id ? (int) $validation->attempted_branch_id : null);
        if ($branch && (int) ($validation->branch_id ?? 0) !== (int) $branch->id) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation does not match this branch.',
            ];
        }

        if ($validation->clock_type && $clockType && $validation->clock_type !== $clockType) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation does not match this attendance action.',
            ];
        }

        if ($validation->method && $method && $validation->method !== $method) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Geofence validation does not match this attendance method.',
            ];
        }

        if ($validation->validation_status === 'skipped'
            && $validation->skip_reason === 'branch_allowed_without_geofence'
            && $branch
            && $this->branchAllowsWithoutGeofence($branch)) {
            return [
                'allowed' => true,
                'validation_status' => 'skipped',
                'status' => 'skipped',
                'skip_reason' => 'branch_allowed_without_geofence',
                'geofence_validation_id' => (int) $validation->id,
                'id' => (int) $validation->id,
                'latitude' => $validation->latitude,
                'longitude' => $validation->longitude,
                'accuracy_meters' => $validation->accuracy_meters,
                'device_type' => $validation->device_type,
                'branch' => [
                    'id' => (int) $branch->id,
                    'name' => $branch->name,
                    'company_id' => (int) $branch->company_id,
                ],
            ];
        }

        if ($validation->validation_status === 'skipped'
            && $validation->skip_reason === 'employee_geofence_exempt'
            && $this->employeeExemptFromGeofence($employee)) {
            return [
                'allowed' => true,
                'validation_status' => 'skipped',
                'status' => 'skipped',
                'skip_reason' => 'employee_geofence_exempt',
                'suppress_location_capture' => true,
                'geofence_validation_id' => (int) $validation->id,
                'id' => (int) $validation->id,
                'latitude' => $validation->latitude,
                'longitude' => $validation->longitude,
                'accuracy_meters' => $validation->accuracy_meters,
                'device_type' => $validation->device_type,
                'branch' => $branch ? [
                    'id' => (int) $branch->id,
                    'name' => $branch->name,
                    'company_id' => (int) $branch->company_id,
                ] : null,
            ];
        }

        $validInsideStatus = in_array((string) $validation->validation_status, ['inside', 'passed'], true);
        if (! $validInsideStatus || ! (bool) $validation->is_inside) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => $validation->failure_reason ?: 'You are outside the allowed attendance geofence.',
            ];
        }

        $threshold = $validation->accuracy_threshold_meters !== null
            ? (int) $validation->accuracy_threshold_meters
            : ($branch ? $this->deviceAccuracyThreshold($branch, $validation->device_type) : self::DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS);
        if ($validation->accuracy_meters !== null && (float) $validation->accuracy_meters > $threshold) {
            return [
                'allowed' => false,
                'validation_status' => 'failed',
                'status' => 'outside',
                'failure_reason' => 'Location accuracy is too low. Please enable WiFi/location services and try again.',
            ];
        }

        $revalidated = $this->validateForEmployee(
            $employee,
            $validation->latitude !== null ? (float) $validation->latitude : null,
            $validation->longitude !== null ? (float) $validation->longitude : null,
            $validation->accuracy_meters !== null ? (float) $validation->accuracy_meters : null,
            [
                'branch_id' => $branch?->id,
                'clock_type' => $clockType,
                'device_type' => $validation->device_type,
                'method' => $method ?? $validation->method,
                'sampled_readings_count' => $validation->sampled_readings_count,
                'selected_best_accuracy' => $validation->selected_best_accuracy,
                'log' => false,
            ],
        );

        if (! ($revalidated['allowed'] ?? false) || ! in_array((string) ($revalidated['validation_status'] ?? ''), ['inside', 'passed', 'warn_only'], true)) {
            return [
                ...$revalidated,
                'allowed' => false,
                'failure_reason' => $revalidated['failure_reason'] ?? 'You are outside the allowed attendance geofence.',
            ];
        }

        return [
            ...$revalidated,
            'geofence_validation_id' => (int) $validation->id,
            'id' => (int) $validation->id,
        ];
    }

    public function branchLocationSettings(?Branch $branch): array
    {
        return [
            'mobile_accuracy_threshold_meters' => $branch ? $this->deviceAccuracyThreshold($branch, 'mobile') : self::DEFAULT_MOBILE_ACCURACY_THRESHOLD_METERS,
            'desktop_accuracy_threshold_meters' => $branch ? $this->deviceAccuracyThreshold($branch, 'desktop') : self::DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS,
            'accuracy_buffer_mode' => $branch ? $this->branchAccuracyBufferMode($branch) : 'strict',
            'minimum_samples' => $branch ? $this->branchMinimumSamples($branch) : 3,
            'maximum_samples' => $branch ? $this->branchMaximumSamples($branch) : 5,
            'sample_timeout_seconds' => $branch ? $this->branchSampleTimeoutSeconds($branch) : 15,
            'require_backend_validation' => $branch ? $this->branchRequiresBackendValidation($branch) : true,
        ];
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
                ->where('branch_id', $branchId);

            if (Schema::hasColumn('branch_geofences', 'status')) {
                $query->where('status', 'active');
            } else {
                $query->where('is_active', true);
            }

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
     * @return list<array<string, mixed>>
     */
    public function matchingActiveGeofencesForBranch(int $branchId, ?string $deviceType): array
    {
        $device = $this->normalizeDeviceType($deviceType);

        return array_values(array_filter(
            $this->activeGeofencesForBranch($branchId),
            fn (array $geofence): bool => $this->deviceScopeMatches(
                (string) ($geofence['device_scope'] ?? 'all_devices'),
                $device,
            ),
        ));
    }

    public function deviceScopeMatches(string $scope, string $deviceType): bool
    {
        return match ($scope) {
            'all_devices' => true,
            'desktop_laptop' => in_array($deviceType, ['desktop', 'laptop'], true),
            'mobile_tablet' => in_array($deviceType, ['mobile', 'tablet'], true),
            'desktop', 'laptop', 'mobile', 'tablet', 'kiosk' => $scope === $deviceType,
            default => false,
        };
    }

    public function normalizeDeviceType(mixed $deviceType): string
    {
        $normalized = str_replace(['-', ' '], '_', strtolower(trim((string) $deviceType)));

        $aliases = [
            'ipad' => 'tablet',
            'android_tablet' => 'tablet',
            'tablet_pc' => 'tablet',
            'phone' => 'mobile',
            'smartphone' => 'mobile',
            'iphone' => 'mobile',
            'android_phone' => 'mobile',
            'cellphone' => 'mobile',
            'notebook' => 'laptop',
            'macbook' => 'laptop',
            'portable_computer' => 'laptop',
            'pc' => 'desktop',
            'computer' => 'desktop',
            'workstation' => 'desktop',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        return in_array($normalized, self::DEVICE_TYPES, true) ? $normalized : 'desktop';
    }

    public function branchAllowsWithoutGeofence(Branch $branch): bool
    {
        if (! Schema::hasTable('branch_geofence_settings')) {
            return false;
        }

        if (! $this->attendanceWithoutGeofenceEnabled()) {
            return false;
        }

        return (bool) BranchGeofenceSetting::query()
            ->where('branch_id', (int) $branch->id)
            ->value('allow_without_geofence');
    }

    public function geofenceModuleEnabled(): bool
    {
        if (! Schema::hasTable('geofence_global_settings')) {
            return true;
        }

        if (! Schema::hasColumn('geofence_global_settings', 'geofence_module_enabled')) {
            return true;
        }

        return (bool) Cache::remember(
            'geofence:global-settings:module-enabled',
            self::CACHE_TTL_SECONDS,
            fn (): bool => (bool) (GeofenceGlobalSetting::query()->find(1)?->geofence_module_enabled ?? true),
        );
    }

    public function attendanceWithoutGeofenceEnabled(): bool
    {
        if (! Schema::hasTable('geofence_global_settings')) {
            return true;
        }

        return (bool) Cache::remember(
            'geofence:global-settings:attendance-without-geofence-enabled',
            self::CACHE_TTL_SECONDS,
            fn (): bool => (bool) (GeofenceGlobalSetting::query()->find(1)?->attendance_without_geofence_enabled ?? true),
        );
    }

    /**
     * @return list<int>
     */
    public function employeeExemptionIds(): array
    {
        if (! Schema::hasTable('geofence_global_settings')
            || ! Schema::hasColumn('geofence_global_settings', 'employee_exemption_ids')) {
            return [];
        }

        return Cache::remember('geofence:global-settings:employee-exemption-ids', self::CACHE_TTL_SECONDS, function (): array {
            $ids = GeofenceGlobalSetting::query()->find(1)?->employee_exemption_ids ?? [];
            if (! is_array($ids)) {
                return [];
            }

            return collect($ids)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        });
    }

    public function employeeExemptFromGeofence(User|int $employee): bool
    {
        $employeeId = $employee instanceof User ? (int) $employee->id : (int) $employee;

        return $employeeId > 0 && in_array($employeeId, $this->employeeExemptionIds(), true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function moduleDisabledResult(?Branch $branch = null, array $options = []): array
    {
        return [
            'allowed' => true,
            'status' => 'skipped',
            'message' => 'Geofencing is disabled. Attendance can continue without location validation.',
            'latitude' => null,
            'longitude' => null,
            'matched_geofence' => null,
            'matched_geofence_id' => null,
            'distance' => null,
            'distance_meters' => null,
            'accuracy_meters' => null,
            'failure_reason' => null,
            'skip_reason' => 'geofence_module_disabled',
            'warning' => null,
            'validation_status' => 'skipped',
            'enforcement_mode' => 'disabled',
            'accuracy_threshold_meters' => null,
            'radius_meters' => null,
            'geofence_type' => null,
            'device_type' => $options['device_type'] ?? null,
            'device_scope_matched' => null,
            'geofence_name' => null,
            'sampled_readings_count' => null,
            'selected_best_accuracy' => null,
            'expires_at' => null,
            'location_settings' => null,
            'geofence_module_enabled' => false,
            'suppress_location_capture' => true,
            'employee_id' => $options['employee_id'] ?? null,
            'user_id' => $options['user_id'] ?? ($options['employee_id'] ?? null),
            'company_id' => $options['company_id'] ?? ($branch?->company_id ? (int) $branch->company_id : null),
            'attempted_branch_id' => $options['attempted_branch_id'] ?? null,
            'clock_type' => $options['clock_type'] ?? null,
            'method' => $options['method'] ?? null,
            'branch' => $branch ? [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'company_id' => (int) $branch->company_id,
                'company_name' => $branch->company?->name,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeResult(?Branch $branch, ?float $latitude, ?float $longitude, ?float $accuracyMeters, array $result): array
    {
        $distance = isset($result['distance']) && $result['distance'] !== null ? round((float) $result['distance'], 2) : null;
        $status = $result['validation_status'] ?? (($result['allowed'] ?? false) ? 'passed' : 'failed');
        $isInside = array_key_exists('is_inside', $result)
            ? (bool) $result['is_inside']
            : ($result['matched_geofence_id'] ?? null) !== null;
        $payload = [
            'allowed' => (bool) ($result['allowed'] ?? false),
            'status' => $this->publicStatus($status, $isInside),
            'message' => $result['message'] ?? $result['failure_reason'] ?? $result['warning'] ?? (($result['allowed'] ?? false) ? 'Geofence validation passed.' : 'Geofence validation failed.'),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'matched_geofence' => $result['matched_geofence'] ?? null,
            'matched_geofence_id' => $result['matched_geofence_id'] ?? null,
            'distance' => $distance,
            'distance_meters' => $distance,
            'accuracy_meters' => $accuracyMeters,
            'failure_reason' => $result['failure_reason'] ?? null,
            'skip_reason' => $result['skip_reason'] ?? null,
            'warning' => $result['warning'] ?? null,
            'validation_status' => $status,
            'enforcement_mode' => $result['enforcement_mode'] ?? ($branch ? $this->branchEnforcementMode($branch) : null),
            'accuracy_threshold_meters' => $result['accuracy_threshold_meters'] ?? null,
            'radius_meters' => $result['radius_meters'] ?? null,
            'geofence_type' => $result['geofence_type'] ?? null,
            'device_type' => $result['device_type'] ?? null,
            'device_scope_matched' => $result['device_scope_matched'] ?? null,
            'geofence_name' => $result['geofence_name'] ?? ($result['matched_geofence']['name'] ?? null),
            'sampled_readings_count' => $result['sampled_readings_count'] ?? null,
            'selected_best_accuracy' => $result['selected_best_accuracy'] ?? $accuracyMeters,
            'expires_at' => now()->addMinutes(self::VALIDATION_TTL_MINUTES)->toIso8601String(),
            'location_settings' => $branch ? $this->branchLocationSettings($branch) : null,
            'suppress_location_capture' => (bool) ($result['suppress_location_capture'] ?? false),
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
                    'geofence_name' => $payload['geofence_name'],
                    'is_inside' => array_key_exists('is_inside', $result)
                        ? (bool) $result['is_inside']
                        : ($result['matched_geofence_id'] ?? null) !== null,
                    'distance_to_center' => $result['distance_to_center'] ?? $result['distance'] ?? null,
                    'validation_status' => $payload['validation_status'],
                    'failure_reason' => $payload['failure_reason'] ?? (($result['employee_id'] ?? null) === null ? 'employee_not_resolved' : null),
                    'skip_reason' => $payload['skip_reason'],
                    'device_type' => $result['device_type'] ?? null,
                    'device_scope_matched' => $payload['device_scope_matched'],
                    'method' => $result['method'] ?? null,
                ];

                foreach ([
                    'user_id' => $result['user_id'] ?? ($result['employee_id'] ?? null),
                    'company_id' => $result['company_id'] ?? ($branch?->company_id ? (int) $branch->company_id : null),
                    'attempted_branch_id' => $result['attempted_branch_id'] ?? null,
                    'clock_type' => $result['clock_type'] ?? null,
                    'enforcement_mode' => $payload['enforcement_mode'],
                    'distance_meters' => $payload['distance_meters'],
                    'radius_meters' => $payload['radius_meters'],
                    'geofence_type' => $payload['geofence_type'],
                    'sampled_readings_count' => $payload['sampled_readings_count'],
                    'selected_best_accuracy' => $payload['selected_best_accuracy'],
                    'accuracy_threshold_meters' => $payload['accuracy_threshold_meters'],
                    'expires_at' => now()->addMinutes(self::VALIDATION_TTL_MINUTES),
                ] as $column => $value) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $logPayload[$column] = $value;
                    }
                }

                $log = GeofenceValidationLog::query()->create($logPayload);
                $payload['geofence_validation_id'] = (int) $log->id;
                $payload['id'] = (int) $log->id;
                $payload['log_created'] = true;

                if (! ($payload['allowed'] ?? false)) {
                    $this->recordLiveMonitorBlockedAttempt((int) $log->id);
                }
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
     * @param  array<string, mixed>  $result
     */
    private function recordLiveMonitorBlockedAttemptFromResult(array $result, \Illuminate\Http\Request $request, ?int $fallbackValidationId = null): void
    {
        if ($result['log_created'] ?? false) {
            return;
        }

        $validationLogId = (int) ($result['geofence_validation_id'] ?? $result['id'] ?? $fallbackValidationId ?? 0);
        if ($validationLogId <= 0) {
            return;
        }

        $this->recordLiveMonitorBlockedAttempt($validationLogId, $request);
    }

    private function recordLiveMonitorBlockedAttempt(int $validationLogId, ?\Illuminate\Http\Request $request = null): void
    {
        try {
            app(GeofenceLiveMonitorService::class)->recordFromValidationLog(
                $validationLogId,
                $request ?? request(),
            );
        } catch (\Throwable $e) {
            Log::warning('Unable to record geofence live monitor blocked attempt', [
                'geofence_validation_log_id' => $validationLogId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $geofence
     * @return array{inside: bool, distance: ?float, distance_to_center: ?float, radius_meters: ?float, geofence_type: ?string}
     */
    private function checkGeofence(array $geofence, float $latitude, float $longitude, ?float $accuracyMeters, Branch $branch): array
    {
        $type = strtolower((string) ($geofence['type'] ?? ''));
        if ($type === 'circle') {
            $centerLat = isset($geofence['center_lat']) ? (float) $geofence['center_lat'] : null;
            $centerLng = isset($geofence['center_lng']) ? (float) $geofence['center_lng'] : null;
            $radius = isset($geofence['radius_meters']) ? (float) $geofence['radius_meters'] : null;
            if ($centerLat === null || $centerLng === null || $radius === null) {
                return ['inside' => false, 'distance' => null, 'distance_to_center' => null, 'radius_meters' => $radius, 'geofence_type' => 'circle'];
            }
            $distance = $this->haversineMeters($centerLat, $centerLng, $latitude, $longitude);
            $allowedDistance = $radius + $this->accuracyBufferMeters($branch, $accuracyMeters);

            return [
                'inside' => $distance <= $allowedDistance,
                'distance' => $distance,
                'distance_to_center' => $distance,
                'radius_meters' => $radius,
                'geofence_type' => 'circle',
            ];
        }

        if ($type === 'polygon') {
            $rings = $this->polygonRings($geofence['polygon_geojson'] ?? null);
            if ($rings === []) {
                return ['inside' => false, 'distance' => null, 'distance_to_center' => null, 'radius_meters' => null, 'geofence_type' => 'polygon'];
            }

            $inside = $this->pointInPolygon($latitude, $longitude, $rings);
            $distance = $this->distanceToPolygonVertices($latitude, $longitude, $rings);

            return [
                'inside' => $inside,
                'distance' => $distance,
                'distance_to_center' => $distance,
                'radius_meters' => null,
                'geofence_type' => 'polygon',
            ];
        }

        return ['inside' => false, 'distance' => null, 'distance_to_center' => null, 'radius_meters' => null, 'geofence_type' => $type ?: null];
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

    private function branchAccuracyBufferMode(Branch $branch): string
    {
        $value = Schema::hasColumn('branches', 'geofence_accuracy_buffer_mode')
            ? strtolower((string) ($branch->geofence_accuracy_buffer_mode ?? 'strict'))
            : 'strict';

        return in_array($value, ['strict', 'balanced', 'lenient'], true) ? $value : 'strict';
    }

    private function accuracyBufferMeters(Branch $branch, ?float $accuracyMeters): float
    {
        if ($accuracyMeters === null) {
            return 0.0;
        }

        return match ($this->branchAccuracyBufferMode($branch)) {
            'balanced' => min($accuracyMeters, 25.0),
            'lenient' => min($accuracyMeters, 50.0),
            default => 0.0,
        };
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
            'passed', 'inside' => 'inside',
            'warning' => 'warning',
            'warn_only' => $isInside ? 'warning' : 'outside',
            'disabled', 'skipped' => 'skipped',
            default => 'outside',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $geofences
     */
    private function branchAccuracyThreshold(Branch $branch, array $geofences, ?string $deviceType = null): int
    {
        $policyThreshold = match ($this->branchAccuracyPolicy($branch)) {
            'strict' => 50,
            'lenient' => 150,
            default => 100,
        };
        $branchDefault = $this->deviceAccuracyThreshold($branch, $deviceType);
        $thresholds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['accuracy_threshold_meters'] ?? 0),
            $geofences,
        ), static fn (int $n): bool => $n > 0));

        $configuredThreshold = max(1, min($thresholds !== [] ? min($thresholds) : $branchDefault, $branchDefault));

        return match ($this->branchAccuracyPolicy($branch)) {
            'strict' => min($configuredThreshold, $policyThreshold),
            'lenient' => min(max($configuredThreshold, $policyThreshold), 150),
            default => min(max($configuredThreshold, $policyThreshold), 100),
        };
    }

    private function deviceAccuracyThreshold(Branch $branch, ?string $deviceType): int
    {
        $isMobile = in_array($this->normalizeDeviceType($deviceType), ['mobile', 'tablet'], true);
        $column = $isMobile ? 'geofence_mobile_accuracy_threshold_meters' : 'geofence_desktop_accuracy_threshold_meters';
        $fallback = $isMobile ? self::DEFAULT_MOBILE_ACCURACY_THRESHOLD_METERS : self::DEFAULT_DESKTOP_ACCURACY_THRESHOLD_METERS;
        if (Schema::hasColumn('branches', $column)) {
            return max(5, min((int) ($branch->{$column} ?? $fallback), 5000));
        }

        return max(5, min((int) ($branch->geofence_default_accuracy_threshold_meters ?? $fallback), 5000));
    }

    /**
     * @param  array<string, mixed>  $geofence
     */
    private function geofenceAccuracyThreshold(Branch $branch, array $geofence, ?string $deviceType): int
    {
        $configured = (int) ($geofence['accuracy_threshold_meters'] ?? 0);
        if ($configured > 0) {
            return max(5, min($configured, 5000));
        }

        return $this->deviceAccuracyThreshold($branch, $deviceType);
    }

    private function branchMinimumSamples(Branch $branch): int
    {
        $value = Schema::hasColumn('branches', 'geofence_minimum_samples') ? (int) ($branch->geofence_minimum_samples ?? 3) : 3;

        return max(1, min($value, 5));
    }

    private function branchMaximumSamples(Branch $branch): int
    {
        $min = $this->branchMinimumSamples($branch);
        $value = Schema::hasColumn('branches', 'geofence_maximum_samples') ? (int) ($branch->geofence_maximum_samples ?? 5) : 5;

        return max($min, min($value, 5));
    }

    private function branchSampleTimeoutSeconds(Branch $branch): int
    {
        $value = Schema::hasColumn('branches', 'geofence_sample_timeout_seconds') ? (int) ($branch->geofence_sample_timeout_seconds ?? 15) : 15;

        return max(5, min($value, 30));
    }

    private function branchRequiresBackendValidation(Branch $branch): bool
    {
        return ! Schema::hasColumn('branches', 'geofence_require_backend_validation')
            || (bool) ($branch->geofence_require_backend_validation ?? true);
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
            'device_scope' => $geofence['device_scope'] ?? 'all_devices',
            'priority' => (int) ($geofence['priority'] ?? 1),
            'radius_meters' => isset($geofence['radius_meters']) ? (int) $geofence['radius_meters'] : null,
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
