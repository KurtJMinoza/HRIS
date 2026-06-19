<?php

namespace App\Services;

use App\Events\GeofenceAttendanceEventCreated;
use App\Models\AttendanceGeofenceEvent;
use App\Models\BranchGeofence;
use App\Models\GeofenceValidationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GeofenceLiveMonitorService
{
    public const CACHE_TTL_SECONDS = 45;

    public const BOUNDARY_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{events: list<array<string, mixed>>, filters: array<string, mixed>}
     */
    public function recentEvents(User $actor, array $filters = []): array
    {
        $this->ensureAuthorized($actor);

        $limit = max(1, min((int) ($filters['limit'] ?? 50), 100));
        $date = $this->normalizeDate($filters['date'] ?? null);
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== '' ? (int) $filters['company_id'] : null;
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;
        $status = $this->blankToNull($filters['status'] ?? null);
        $deviceType = $this->blankToNull($filters['device_type'] ?? null);
        $clockType = $this->blankToNull($filters['clock_type'] ?? null);
        $scopedBranchIds = $this->scopedBranchIds($actor);
        $scopeKey = $scopedBranchIds === null ? 'global' : sha1(implode(',', $scopedBranchIds));
        $version = (int) Cache::get($this->eventsVersionKey($date), 1);

        $cacheKey = 'geofence_live:events:'.$date.':'.sha1(json_encode([
            'scope' => $scopeKey,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'status' => $status,
            'device_type' => $deviceType,
            'clock_type' => $clockType,
            'limit' => $limit,
            'version' => $version,
        ], JSON_THROW_ON_ERROR));

        $events = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($companyId, $branchId, $date, $status, $deviceType, $clockType, $limit, $scopedBranchIds): array {
            $query = AttendanceGeofenceEvent::query()
                ->with([
                    'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id,department_id',
                    'employee.company:id,name',
                    'employee.departmentRelation:id,name',
                    'branch:id,name,company_id',
                    'branch.company:id,name',
                    'company:id,name',
                    'matchedGeofence:id,name',
                ])
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereDate('created_at', $date);

            if ($scopedBranchIds !== null) {
                if ($scopedBranchIds === []) {
                    return [];
                }
                $query->where(function (Builder $q) use ($scopedBranchIds): void {
                    $q->whereIn('branch_id', $scopedBranchIds);
                });
            }
            if ($companyId !== null) {
                $query->where('company_id', $companyId);
            }
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
            if ($status !== null) {
                $query->where('geofence_status', $this->normalizeStatus($status));
            }
            if ($deviceType !== null) {
                $query->where('device_type', $deviceType);
            }
            if ($clockType !== null) {
                $query->where('clock_type', $this->normalizeClockType($clockType));
            }

            return $query
                ->latest('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (AttendanceGeofenceEvent $event): array => $this->payloadFromEvent($event, includeDetail: true))
                ->values()
                ->all();
        });

        return [
            'events' => $events,
            'filters' => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'date' => $date,
                'status' => $status,
                'device_type' => $deviceType,
                'clock_type' => $clockType,
                'limit' => $limit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $actor, array $filters = []): array
    {
        $this->ensureAuthorized($actor);

        $date = $this->normalizeDate($filters['date'] ?? null);
        $scopedBranchIds = $this->scopedBranchIds($actor);
        $scopeKey = $scopedBranchIds === null ? 'global' : sha1(implode(',', $scopedBranchIds));
        $version = (int) Cache::get($this->eventsVersionKey($date), 1);
        $cacheKey = "geofence_live:summary:{$date}:{$scopeKey}:{$version}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($date, $scopedBranchIds): array {
            $query = AttendanceGeofenceEvent::query()->whereDate('created_at', $date);
            if ($scopedBranchIds !== null) {
                if ($scopedBranchIds === []) {
                    return [
                        'date' => $date,
                        'total' => 0,
                        'clock_in' => 0,
                        'clock_out' => 0,
                        'inside' => 0,
                        'outside' => 0,
                        'warning' => 0,
                        'skipped' => 0,
                    ];
                }
                $query->whereIn('branch_id', $scopedBranchIds);
            }

            return [
                'date' => $date,
                'total' => (clone $query)->count(),
                'clock_in' => (clone $query)->where('clock_type', 'clock_in')->count(),
                'clock_out' => (clone $query)->where('clock_type', 'clock_out')->count(),
                'inside' => (clone $query)->where('geofence_status', 'inside')->count(),
                'outside' => (clone $query)->whereIn('geofence_status', ['outside', 'failed'])->count(),
                'warning' => (clone $query)->where('geofence_status', 'warning')->count(),
                'skipped' => (clone $query)->where('geofence_status', 'skipped')->count(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function event(User $actor, int $eventId): array
    {
        $this->ensureAuthorized($actor);

        $event = AttendanceGeofenceEvent::query()
            ->with([
                'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id,department_id',
                'employee.company:id,name',
                'employee.departmentRelation:id,name',
                'branch:id,name,company_id',
                'branch.company:id,name',
                'company:id,name',
                'matchedGeofence:id,name',
            ])
            ->findOrFail($eventId);

        $scopedBranchIds = $this->scopedBranchIds($actor);
        if ($scopedBranchIds !== null && ! in_array((int) $event->branch_id, $scopedBranchIds, true)) {
            abort(404);
        }

        return ['event' => $this->payloadFromEvent($event, includeDetail: true)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function boundaries(User $actor, array $filters = []): array
    {
        $this->ensureAuthorized($actor);

        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;
        $companyId = isset($filters['company_id']) && $filters['company_id'] !== '' ? (int) $filters['company_id'] : null;
        $scopedBranchIds = $this->scopedBranchIds($actor);
        if ($scopedBranchIds !== null && $branchId !== null && ! in_array($branchId, $scopedBranchIds, true)) {
            return [];
        }
        if ($branchId !== null) {
            return Cache::remember(
                $this->boundaryCacheKey($branchId),
                self::BOUNDARY_CACHE_TTL_SECONDS,
                fn (): array => $this->boundaryQuery(null, $branchId),
            );
        }

        return $this->boundaryQuery($companyId, null, $scopedBranchIds);
    }

    public static function forgetBranchBoundaryCache(int $branchId): void
    {
        Cache::forget("geofence:branch_boundaries:{$branchId}");
    }

    public function recordFromValidationLog(int $validationLogId, ?Request $request = null, ?int $attendanceLogId = null): ?AttendanceGeofenceEvent
    {
        if (! Schema::hasTable('attendance_geofence_events')) {
            return null;
        }

        try {
            $log = GeofenceValidationLog::query()
                ->with([
                    'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id',
                    'employee.company:id,name',
                    'branch:id,name,company_id',
                    'branch.company:id,name',
                    'attemptedBranch:id,name,company_id',
                    'attemptedBranch.company:id,name',
                    'company:id,name',
                    'matchedGeofence:id,name',
                    'attendanceLog:id,type,created_at,verified_at',
                ])
                ->find($validationLogId);

            if (! $log || ! $log->employee_id || $log->latitude === null || $log->longitude === null) {
                return null;
            }

            $userAgent = $request?->userAgent();
            $deviceInfo = $this->deviceInfo($userAgent);
            $branch = $log->branch ?? $log->attemptedBranch ?? $log->employee?->branch;
            $company = $branch?->company ?? $log->company ?? $log->employee?->company;
            $clockType = $this->normalizeClockType($log->clock_type ?? $log->attendanceLog?->type);
            $status = $this->publicStatus($log);

            $event = AttendanceGeofenceEvent::query()->updateOrCreate(
                ['geofence_validation_log_id' => (int) $log->id],
                [
                    'attendance_log_id' => $attendanceLogId ?? $log->attendance_log_id,
                    'employee_id' => (int) $log->employee_id,
                    'company_id' => $log->company_id ?? $company?->id,
                    'branch_id' => $branch?->id,
                    'clock_type' => $clockType,
                    'event_type' => $this->eventType($clockType, $status),
                    'latitude' => $log->latitude,
                    'longitude' => $log->longitude,
                    'accuracy_meters' => $log->accuracy_meters,
                    'distance_meters' => $log->distance_meters ?? $log->distance_to_center,
                    'geofence_status' => $status,
                    'matched_geofence_id' => $log->matched_geofence_id,
                    'device_type' => $this->normalizeDeviceType($log->device_type),
                    'browser' => $deviceInfo['browser'],
                    'platform' => $deviceInfo['platform'],
                    'user_agent' => $userAgent,
                ],
            );

            $this->invalidateForDate($event->created_at?->toDateString() ?? now()->toDateString());
            $this->broadcastEventAfterCommit($event);

            return $event;
        } catch (\Throwable $e) {
            Log::warning('Unable to create geofence live monitor event', [
                'geofence_validation_log_id' => $validationLogId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function broadcastEventAfterCommit(AttendanceGeofenceEvent $event): void
    {
        DB::afterCommit(function () use ($event): void {
            $fresh = AttendanceGeofenceEvent::query()
                ->with([
                    'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id,department_id',
                    'employee.company:id,name',
                    'employee.departmentRelation:id,name',
                    'branch:id,name,company_id',
                    'branch.company:id,name',
                    'company:id,name',
                    'matchedGeofence:id,name',
                ])
                ->find($event->id);

            if ($fresh) {
                broadcast(new GeofenceAttendanceEventCreated($this->payloadFromEvent($fresh)));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFromEvent(AttendanceGeofenceEvent $event, bool $includeDetail = false): array
    {
        $employee = $event->employee;
        $branch = $event->branch;
        $company = $event->company ?? $branch?->company ?? $employee?->company;
        $time = ($event->created_at ?? now())->copy()->timezone(config('attendance.timezone', config('app.timezone', 'Asia/Manila')));

        $payload = [
            'event_id' => (int) $event->id,
            'employee_name' => $employee?->display_name ?? 'Unknown employee',
            'employee_number' => $employee?->employee_code ?? ($employee?->id ? 'EMP-'.str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT) : null),
            'company_name' => $company?->name,
            'branch_name' => $branch?->name,
            'clock_type' => $event->clock_type,
            'latitude' => $event->latitude !== null ? (float) $event->latitude : null,
            'longitude' => $event->longitude !== null ? (float) $event->longitude : null,
            'accuracy_meters' => $event->accuracy_meters !== null ? round((float) $event->accuracy_meters, 2) : null,
            'distance_meters' => $event->distance_meters !== null ? round((float) $event->distance_meters, 2) : null,
            'geofence_status' => $event->geofence_status,
            'device_type' => $event->device_type,
            'browser' => $event->browser,
            'created_at' => $time->toIso8601String(),
            'department' => $employee?->departmentRelation?->name,
            'matched_geofence' => $event->matchedGeofence?->name,
        ];

        if ($includeDetail) {
            $payload += [
                'id' => (int) $event->id,
                'attendance_log_id' => $event->attendance_log_id ? (int) $event->attendance_log_id : null,
                'geofence_validation_log_id' => $event->geofence_validation_log_id ? (int) $event->geofence_validation_log_id : null,
                'employee_id' => (int) $event->employee_id,
                'company_id' => $event->company_id ? (int) $event->company_id : null,
                'branch_id' => $event->branch_id ? (int) $event->branch_id : null,
                'event_type' => $event->event_type,
                'lat' => $payload['latitude'],
                'lng' => $payload['longitude'],
                'platform' => $event->platform,
                'user_agent' => $event->user_agent,
                'matched_geofence_id' => $event->matched_geofence_id ? (int) $event->matched_geofence_id : null,
                'time' => $time->format('Y-m-d H:i:s'),
            ];
        }

        return $payload;
    }

    private function publicStatus(GeofenceValidationLog $log): string
    {
        $status = strtolower((string) ($log->validation_status ?? ''));
        if (in_array($status, ['skipped', 'disabled'], true)) {
            return 'skipped';
        }
        if (in_array($status, ['blocked', 'failed'], true)) {
            return 'failed';
        }
        if ($status === 'warn_only' || ($log->accuracy_meters !== null && $log->accuracy_threshold_meters !== null && (float) $log->accuracy_meters > (float) $log->accuracy_threshold_meters)) {
            return (bool) $log->is_inside ? 'warning' : 'outside';
        }

        return (bool) $log->is_inside ? 'inside' : 'outside';
    }

    private function eventType(string $clockType, string $status): string
    {
        return match ($status) {
            'inside' => $clockType === 'clock_out' ? 'clock_out_inside' : 'clock_in_inside',
            'warning' => 'geofence_warning',
            'skipped' => 'geofence_skipped',
            default => 'outside_geofence_attempt',
        };
    }

    private function normalizeDeviceType(mixed $deviceType): string
    {
        $value = strtolower(trim((string) $deviceType));
        if (str_contains($value, 'kiosk')) {
            return 'kiosk';
        }
        if (str_contains($value, 'laptop') || str_contains($value, 'notebook') || str_contains($value, 'macbook')) {
            return 'laptop';
        }
        if (str_contains($value, 'tablet') || str_contains($value, 'ipad')) {
            return 'tablet';
        }
        if (str_contains($value, 'mobile') || str_contains($value, 'phone') || str_contains($value, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function normalizeClockType(mixed $clockType): string
    {
        $value = strtolower(trim((string) $clockType));

        return in_array($value, ['clock_out', 'out'], true) ? 'clock_out' : 'clock_in';
    }

    private function normalizeStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));

        return match ($value) {
            'warn_only', 'poor_accuracy' => 'warning',
            'blocked', 'failed' => 'failed',
            default => $value,
        };
    }

    /**
     * @return array{browser: ?string, platform: ?string}
     */
    private function deviceInfo(?string $userAgent): array
    {
        $ua = strtolower((string) $userAgent);
        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'chrome/') || str_contains($ua, 'crios/') => 'Chrome',
            str_contains($ua, 'safari/') => 'Safari',
            default => $userAgent ? 'Unknown' : null,
        };
        $platform = match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'linux') => 'Linux',
            default => $userAgent ? 'Unknown' : null,
        };

        return ['browser' => $browser, 'platform' => $platform];
    }

    public function ensureAuthorized(User $actor): void
    {
        if (! $actor->isAdmin()) {
            abort(403, 'You are not authorized to view geofence live monitoring.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundaryQuery(?int $companyId, ?int $branchId, ?array $scopedBranchIds = null): array
    {
        $query = BranchGeofence::query()
            ->with(['branch:id,name,company_id', 'branch.company:id,name'])
            ->where('status', 'active');

        if ($scopedBranchIds !== null) {
            if ($scopedBranchIds === []) {
                return [];
            }
            $query->whereIn('branch_id', $scopedBranchIds);
        }
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->orderBy('branch_id')
            ->orderBy('priority')
            ->limit(250)
            ->get()
            ->map(fn (BranchGeofence $geofence): array => [
                'id' => (int) $geofence->id,
                'company_id' => (int) $geofence->company_id,
                'branch_id' => (int) $geofence->branch_id,
                'branch_name' => $geofence->branch?->name,
                'company_name' => $geofence->branch?->company?->name,
                'name' => $geofence->name,
                'type' => $geofence->type,
                'center_lat' => $geofence->center_lat,
                'center_lng' => $geofence->center_lng,
                'radius_meters' => $geofence->radius_meters,
                'polygon_geojson' => $geofence->polygon_geojson,
                'device_scope' => $geofence->device_scope ?? 'all_devices',
            ])
            ->values()
            ->all();
    }

    private function boundaryCacheKey(int $branchId): string
    {
        return "geofence:branch_boundaries:{$branchId}";
    }

    private function eventsVersionKey(string $date): string
    {
        return "geofence_live:version:{$date}";
    }

    private function invalidateForDate(string $date): void
    {
        Cache::forget("geofence_live:summary:{$date}");
        Cache::add($this->eventsVersionKey($date), 1, now()->addDay());
        Cache::increment($this->eventsVersionKey($date));
    }

    private function normalizeDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === 'all' ? null : $value;
    }

    /**
     * @return list<int>|null
     */
    private function scopedBranchIds(User $actor): ?array
    {
        if ($actor->isAdmin()) {
            return null;
        }

        $query = \App\Models\Branch::query();
        $this->dataScopeService->restrictBranchQuery($actor, $query);

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
