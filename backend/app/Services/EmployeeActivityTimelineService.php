<?php

namespace App\Services;

use App\Models\EmployeeActivityLog as EmployeeSessionActivityLog;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\LoanRequest;
use App\Models\Overtime;
use App\Models\ScheduleRequest;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeActivityTimelineService
{
    private const PER_SOURCE_LIMIT = 400;

    private const SESSION_SOURCE_LIMIT = 1000;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(User $actor, array $filters): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'attendance');
        $employeeId = ! empty($filters['employee_id']) ? (int) $filters['employee_id'] : null;
        $category = (string) ($filters['category'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $loadSession = $category === '' || $category === 'all' || in_array($category, ['auth', 'navigation'], true);
        $loadBusiness = $category === '' || $category === 'all' || ! in_array($category, ['auth', 'navigation'], true);

        $events = collect();
        if ($loadSession) {
            $events = $events->merge($this->sessionEvents($from, $to, $scopedEmployeeIds, $employeeId, $category));
        }
        if ($loadBusiness) {
            $events = $events
                ->merge($this->attendanceEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->leaveEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->overtimeEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->correctionEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->scheduleEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->loanEvents($from, $to, $scopedEmployeeIds, $employeeId))
                ->merge($this->accountEvents($from, $to, $scopedEmployeeIds, $employeeId));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $events = $events->filter(function (array $row) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['employee_name'] ?? '',
                    $row['employee_code'] ?? '',
                    $row['title'] ?? '',
                    $row['summary'] ?? '',
                    $row['category_label'] ?? '',
                    $row['module'] ?? '',
                    $row['path'] ?? '',
                    $row['device_type'] ?? '',
                    $row['status'] ?? '',
                ])));

                return str_contains($haystack, $needle);
            })->values();
        }

        $categoryCounts = $events->countBy('category')->all();

        if ($category !== '' && $category !== 'all') {
            $events = $events->filter(fn (array $row) => $row['category'] === $category)->values();
        }

        $sorted = $events
            ->sortByDesc(fn (array $row) => $row['occurred_at'] ?? '')
            ->values();

        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $rows = $sorted->slice($offset, $perPage)->values()->all();

        return [
            'rows' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'category_counts' => $categoryCounts,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(User $actor, string $ref): ?array
    {
        if (! str_contains($ref, ':')) {
            return null;
        }

        [$source, $rawId] = explode(':', $ref, 2);
        $id = (int) $rawId;
        if ($id <= 0) {
            return null;
        }

        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'attendance');

        return match ($source) {
            'attendance' => $this->attendanceDetail($id, $scopedEmployeeIds),
            'leave' => $this->leaveDetail($id, $scopedEmployeeIds),
            'overtime' => $this->overtimeDetail($id, $scopedEmployeeIds),
            'correction' => $this->correctionDetail($id, $scopedEmployeeIds),
            'schedule' => $this->scheduleDetail($id, $scopedEmployeeIds),
            'loan' => $this->loanDetail($id, $scopedEmployeeIds),
            'account' => $this->accountDetail($id, $scopedEmployeeIds),
            'session' => $this->sessionDetail($id, $scopedEmployeeIds),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(User $actor, array $filters): array
    {
        $filters['page'] = 1;
        $filters['per_page'] = 100;
        $all = [];
        do {
            $payload = $this->list($actor, $filters);
            $all = array_merge($all, $payload['rows']);
            $filters['page'] = ((int) ($payload['meta']['current_page'] ?? 1)) + 1;
            $lastPage = (int) ($payload['meta']['last_page'] ?? 1);
        } while ($filters['page'] <= $lastPage && $filters['page'] <= 50);

        return $all;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveDateRange(array $filters): array
    {
        $tz = $this->attendanceTimezone();
        $today = Carbon::now($tz)->startOfDay();
        $fromDate = $filters['from_date'] ?? $today->copy()->subDays(29)->toDateString();
        $toDate = $filters['to_date'] ?? $today->toDateString();

        $from = Carbon::parse($fromDate, $tz)->startOfDay();
        $to = Carbon::parse($toDate, $tz)->endOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

  private function inEmployeeScope(?array $scopedEmployeeIds, ?int $employeeId, int $userId): bool
    {
        if ($employeeId !== null && $userId !== (int) $employeeId) {
            return false;
        }
        if ($scopedEmployeeIds !== null && ! in_array($userId, $scopedEmployeeIds, true)) {
            return false;
        }

        return true;
    }

    private function userSnapshot(?User $user): array
    {
        return [
            'user_id' => $user?->id,
            'employee_name' => $this->employeeDisplayName($user),
            'employee_code' => $user?->employee_code,
            'profile_image' => $user?->profile_image_url,
            'profile_image_url' => $user?->profile_image_url,
            'department_name' => $user?->departmentRelation?->name ?? $user?->department,
            'company_name' => $user?->company?->name,
        ];
    }

    private function employeeDisplayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
            $user->suffix,
        ]))) ?: null;
    }

    private function formatInstant(?Carbon $instant): array
    {
        if (! $instant) {
            return ['occurred_at' => null, 'occurred_at_label' => null];
        }
        $local = $instant->copy()->timezone($this->attendanceTimezone());

        return [
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_label' => $local->format('M j, Y g:i A'),
        ];
    }

    private function statusLabel(?string $status): ?string
    {
        if (! $status) {
            return null;
        }

        return ucfirst(str_replace('_', ' ', $status));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sessionEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId, string $categoryFilter = 'all'): Collection
    {
        return EmployeeSessionActivityLog::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
            ])
            ->whereBetween('occurred_at', [$from, $to])
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->when($categoryFilter === 'auth' || $categoryFilter === 'navigation', fn ($q) => $q->where('category', $categoryFilter))
            ->orderByDesc('occurred_at')
            ->limit(self::SESSION_SOURCE_LIMIT)
            ->get()
            ->map(function (EmployeeSessionActivityLog $row) {
                $categoryLabel = match ($row->category) {
                    EmployeeSessionActivityLog::CATEGORY_AUTH => 'Sign in / out',
                    EmployeeSessionActivityLog::CATEGORY_NAVIGATION => 'Navigation',
                    default => ucfirst((string) $row->category),
                };

                return array_merge([
                    'id' => 'session:'.$row->id,
                    'source' => 'session',
                    'category' => $row->category,
                    'category_label' => $categoryLabel,
                    'action' => $row->event_type,
                    'title' => $row->title,
                    'summary' => $row->summary,
                    'module' => $row->module,
                    'path' => $row->path,
                    'device_type' => $row->device_type,
                    'ip_address' => $row->ip_address,
                    'status' => null,
                ], $this->userSnapshot($row->user), $this->formatInstant($row->occurred_at));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function attendanceEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return AttendanceLog::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
                'matchedGeofence:id,name',
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('verified_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->whereNull('verified_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('verified_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->filter(fn (AttendanceLog $log) => $this->inEmployeeScope($scopedEmployeeIds, $employeeId, (int) $log->user_id))
            ->map(function (AttendanceLog $log) {
                $instant = $log->verified_at ?? $log->created_at;
                $isOut = $log->type === AttendanceLog::TYPE_CLOCK_OUT;
                $method = $log->authentication_method ?? $log->method;
                $parts = array_filter([
                    $method,
                    $log->matchedGeofence?->name,
                    $log->ip_address,
                ]);

                return array_merge([
                    'id' => 'attendance:'.$log->id,
                    'source' => 'attendance',
                    'category' => 'attendance',
                    'category_label' => 'Attendance',
                    'action' => $log->type,
                    'title' => $isOut ? 'Clocked out' : 'Clocked in',
                    'summary' => $parts !== [] ? implode(' · ', $parts) : null,
                    'status' => null,
                ], $this->userSnapshot($log->user), $this->formatInstant($instant));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function leaveEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return LeaveRequest::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('filed_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->whereNull('filed_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('filed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (LeaveRequest $row) {
                $instant = $row->filed_at ?? $row->created_at;
                $range = $row->start_date?->format('M j, Y');
                if ($row->end_date && $row->end_date->toDateString() !== $row->start_date?->toDateString()) {
                    $range .= ' – '.$row->end_date->format('M j, Y');
                }

                return array_merge([
                    'id' => 'leave:'.$row->id,
                    'source' => 'leave',
                    'category' => 'leave',
                    'category_label' => 'Leave',
                    'action' => 'leave_filed',
                    'title' => 'Filed leave request',
                    'summary' => trim(($row->type ? ucfirst($row->type).' · ' : '').($range ?? '')),
                    'status' => $this->statusLabel($row->status),
                ], $this->userSnapshot($row->user), $this->formatInstant($instant));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function overtimeEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return Overtime::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('filed_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->whereNull('filed_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('filed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (Overtime $row) {
                $instant = $row->filed_at ?? $row->created_at;
                $hours = $row->computed_hours ?? $row->approved_ot_hours;

                return array_merge([
                    'id' => 'overtime:'.$row->id,
                    'source' => 'overtime',
                    'category' => 'overtime',
                    'category_label' => 'Overtime',
                    'action' => 'overtime_filed',
                    'title' => 'Filed overtime request',
                    'summary' => trim(implode(' · ', array_filter([
                        $row->date?->format('M j, Y'),
                        $hours !== null ? $hours.'h' : null,
                        $row->reason,
                    ]))),
                    'status' => $this->statusLabel($row->status),
                ], $this->userSnapshot($row->user), $this->formatInstant($instant));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function correctionEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return AttendanceCorrection::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('filed_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->whereNull('filed_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('filed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (AttendanceCorrection $row) {
                $instant = $row->filed_at ?? $row->created_at;

                return array_merge([
                    'id' => 'correction:'.$row->id,
                    'source' => 'correction',
                    'category' => 'correction',
                    'category_label' => 'Correction',
                    'action' => 'correction_filed',
                    'title' => 'Filed attendance correction',
                    'summary' => trim(implode(' · ', array_filter([
                        $row->date?->format('M j, Y'),
                        $row->resolvedIssueKind(),
                        $row->remarks,
                    ]))),
                    'status' => $this->statusLabel($row->status ?? ($row->approved ? 'approved' : 'pending')),
                ], $this->userSnapshot($row->user), $this->formatInstant($instant));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scheduleEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return ScheduleRequest::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
                'workingSchedule:id,name',
            ])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('filed_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner->whereNull('filed_at')->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('filed_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (ScheduleRequest $row) {
                $instant = $row->filed_at ?? $row->created_at;
                $kind = $row->request_kind === ScheduleRequest::KIND_CUSTOM ? 'Custom schedule' : 'Template schedule';

                return array_merge([
                    'id' => 'schedule:'.$row->id,
                    'source' => 'schedule',
                    'category' => 'schedule',
                    'category_label' => 'Schedule',
                    'action' => 'schedule_requested',
                    'title' => 'Requested schedule change',
                    'summary' => trim(implode(' · ', array_filter([
                        $kind,
                        $row->workingSchedule?->name,
                        $row->effective_from?->format('M j, Y'),
                    ]))),
                    'status' => $this->statusLabel($row->status),
                ], $this->userSnapshot($row->user), $this->formatInstant($instant));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function loanEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return LoanRequest::query()
            ->with([
                'user:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'user.company:id,name',
                'user.departmentRelation:id,name',
            ])
            ->whereBetween('created_at', [$from, $to])
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('user_id', $scopedEmployeeIds))
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (LoanRequest $row) {
                return array_merge([
                    'id' => 'loan:'.$row->id,
                    'source' => 'loan',
                    'category' => 'loan',
                    'category_label' => 'Loan',
                    'action' => 'loan_requested',
                    'title' => 'Requested loan',
                    'summary' => trim(implode(' · ', array_filter([
                        $row->requested_amount !== null ? '₱'.number_format((float) $row->requested_amount, 2) : null,
                        $row->term_months ? $row->term_months.' months' : null,
                        $row->reason,
                    ]))),
                    'status' => $this->statusLabel($row->status),
                ], $this->userSnapshot($row->user), $this->formatInstant($row->created_at));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function accountEvents(Carbon $from, Carbon $to, ?array $scopedEmployeeIds, ?int $employeeId): Collection
    {
        return UserAdminActivityLog::query()
            ->with([
                'subject:id,first_name,middle_name,last_name,suffix,name,employee_code,profile_image,department,department_id,company_id',
                'subject.company:id,name',
                'subject.departmentRelation:id,name',
            ])
            ->whereBetween('created_at', [$from, $to])
            ->whereColumn('actor_user_id', 'subject_user_id')
            ->when($employeeId, fn ($q) => $q->where('subject_user_id', $employeeId))
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('subject_user_id', $scopedEmployeeIds))
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(function (UserAdminActivityLog $row) {
                $title = match ($row->action) {
                    'face_registered' => 'Registered face ID',
                    'face_registration_reset' => 'Reset face registration',
                    default => ucfirst(str_replace('_', ' ', (string) $row->action)),
                };

                return array_merge([
                    'id' => 'account:'.$row->id,
                    'source' => 'account',
                    'category' => 'account',
                    'category_label' => 'Account',
                    'action' => (string) $row->action,
                    'title' => $title,
                    'summary' => is_array($row->meta) ? json_encode($row->meta, JSON_UNESCAPED_UNICODE) : null,
                    'status' => null,
                ], $this->userSnapshot($row->subject), $this->formatInstant($row->created_at));
            });
    }

    private function attendanceDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $log = AttendanceLog::query()->with(['user', 'matchedGeofence'])->find($id);
        if (! $log || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $log->user_id)) {
            return null;
        }

        return [
            'ref' => 'attendance:'.$log->id,
            'category_label' => 'Attendance',
            'title' => $log->type === AttendanceLog::TYPE_CLOCK_OUT ? 'Clocked out' : 'Clocked in',
            'fields' => [
                'Method' => $log->authentication_method ?? $log->method,
                'Verified at' => $log->verified_at?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
                'Geofence' => $log->matchedGeofence?->name,
                'Geofence status' => $log->geofence_status,
                'IP address' => $log->ip_address,
                'Face similarity' => $log->similarity_score,
                'Liveness score' => $log->liveness_score,
                'Location' => $log->latitude !== null && $log->longitude !== null ? $log->latitude.', '.$log->longitude : null,
            ],
        ];
    }

    private function leaveDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = LeaveRequest::query()->with('user')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        return [
            'ref' => 'leave:'.$row->id,
            'category_label' => 'Leave',
            'title' => 'Leave request',
            'fields' => [
                'Type' => $row->type,
                'Start' => $row->start_date?->format('M j, Y'),
                'End' => $row->end_date?->format('M j, Y'),
                'Status' => $this->statusLabel($row->status),
                'Notes' => $row->notes,
                'Filed at' => ($row->filed_at ?? $row->created_at)?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
            ],
        ];
    }

    private function overtimeDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = Overtime::query()->with('user')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        return [
            'ref' => 'overtime:'.$row->id,
            'category_label' => 'Overtime',
            'title' => 'Overtime request',
            'fields' => [
                'Date' => $row->date?->format('M j, Y'),
                'Computed hours' => $row->computed_hours,
                'Approved hours' => $row->approved_ot_hours,
                'Status' => $this->statusLabel($row->status),
                'Reason' => $row->reason,
                'Filed at' => ($row->filed_at ?? $row->created_at)?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
            ],
        ];
    }

    private function correctionDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = AttendanceCorrection::query()->with('user')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        return [
            'ref' => 'correction:'.$row->id,
            'category_label' => 'Correction',
            'title' => 'Attendance correction',
            'fields' => [
                'Date' => $row->date?->format('M j, Y'),
                'Issue' => $row->resolvedIssueKind(),
                'Time in' => $row->time_in?->timezone($this->attendanceTimezone())->format('g:i A'),
                'Time out' => $row->time_out?->timezone($this->attendanceTimezone())->format('g:i A'),
                'Status' => $this->statusLabel($row->status ?? ($row->approved ? 'approved' : 'pending')),
                'Remarks' => $row->remarks,
            ],
        ];
    }

    private function scheduleDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = ScheduleRequest::query()->with(['user', 'workingSchedule'])->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        return [
            'ref' => 'schedule:'.$row->id,
            'category_label' => 'Schedule',
            'title' => 'Schedule request',
            'fields' => [
                'Kind' => $row->request_kind,
                'Template' => $row->workingSchedule?->name,
                'Effective from' => $row->effective_from?->format('M j, Y'),
                'Status' => $this->statusLabel($row->status),
                'Remarks' => $row->remarks,
            ],
        ];
    }

    private function loanDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = LoanRequest::query()->with('user')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        return [
            'ref' => 'loan:'.$row->id,
            'category_label' => 'Loan',
            'title' => 'Loan request',
            'fields' => [
                'Amount' => $row->requested_amount !== null ? '₱'.number_format((float) $row->requested_amount, 2) : null,
                'Term' => $row->term_months ? $row->term_months.' months' : null,
                'Status' => $this->statusLabel($row->status),
                'Reason' => $row->reason,
                'Requested at' => $row->created_at?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
            ],
        ];
    }

    private function accountDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = UserAdminActivityLog::query()->with('subject')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->subject_user_id)) {
            return null;
        }

        return [
            'ref' => 'account:'.$row->id,
            'category_label' => 'Account',
            'title' => ucfirst(str_replace('_', ' ', (string) $row->action)),
            'fields' => [
                'Action' => $row->action,
                'IP address' => $row->ip_address,
                'Occurred at' => $row->created_at?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
                'Details' => is_array($row->meta) ? json_encode($row->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null,
            ],
        ];
    }

    private function sessionDetail(int $id, ?array $scopedEmployeeIds): ?array
    {
        $row = EmployeeSessionActivityLog::query()->with('user')->find($id);
        if (! $row || ! $this->inEmployeeScope($scopedEmployeeIds, null, (int) $row->user_id)) {
            return null;
        }

        $categoryLabel = match ($row->category) {
            EmployeeSessionActivityLog::CATEGORY_AUTH => 'Sign in / out',
            EmployeeSessionActivityLog::CATEGORY_NAVIGATION => 'Navigation',
            default => ucfirst((string) $row->category),
        };

        return [
            'ref' => 'session:'.$row->id,
            'category_label' => $categoryLabel,
            'title' => $row->title,
            'fields' => [
                'Event' => $row->event_type,
                'Module' => $row->module,
                'Page path' => $row->path,
                'Summary' => $row->summary,
                'Sign-in method' => $row->auth_method,
                'Device' => $row->device_type,
                'IP address' => $row->ip_address,
                'Session ID' => $row->session_token_id,
                'User agent' => $row->user_agent,
                'Occurred at' => $row->occurred_at?->timezone($this->attendanceTimezone())->format('M j, Y g:i A'),
                'Details' => is_array($row->meta) ? json_encode($row->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null,
            ],
        ];
    }
}
