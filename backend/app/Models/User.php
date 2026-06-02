<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\EncryptedArray;
use App\Services\EmployeeLevelResolver;
use App\Services\FaceEmbeddingCacheService;
use App\Services\FaceVerificationService;
use App\Services\HrRoleResolver;
use App\Support\EmployeeProfileCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * Staff included in HR rosters (Employee module lists, payroll runs, attendance/reports scopes, etc.).
     * Admin (HR) accounts are part of this set so they appear and behave as staff while retaining admin capabilities.
     *
     * @var list<string>
     */
    public const ROSTER_ELIGIBLE_ROLES = [self::ROLE_EMPLOYEE, self::ROLE_ADMIN];

    public function isRosterEligible(): bool
    {
        return in_array($this->role, self::ROSTER_ELIGIBLE_ROLES, true)
            && ! (bool) ($this->is_system_user ?? false)
            && ! (bool) ($this->is_hidden ?? false);
    }

    public function isSystemAccessOnly(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN
            || (bool) ($this->is_system_user ?? false)
            || (bool) ($this->is_hidden ?? false);
    }

    public function isExcludedFromReports(): bool
    {
        return $this->isSystemAccessOnly() || (bool) ($this->exclude_from_reports ?? false);
    }

    public function isExcludedFromPayroll(): bool
    {
        return $this->isSystemAccessOnly() || (bool) ($this->exclude_from_payroll ?? false);
    }

    public function isExcludedFromAttendance(): bool
    {
        return $this->isSystemAccessOnly() || (bool) ($this->exclude_from_attendance ?? false);
    }

    public function isExcludedFromApprovals(): bool
    {
        return $this->isSystemAccessOnly() || (bool) ($this->exclude_from_approvals ?? false);
    }

    public const DEACTIVATED_LOGIN_MESSAGE = 'Your account has been deactivated. Please contact HR/Admin.';

    /**
     * Single source of truth for employee/account availability in active HRIS operations.
     *
     * Historical records still point at the user row, but new payroll, attendance, assignment,
     * routing and dropdown queries should use active()/activeRoster() unless a request
     * explicitly opts into deactivated employees.
     */
    public function isOperationallyActive(): bool
    {
        if (! (bool) $this->is_active) {
            return false;
        }

        $status = \App\Enums\EmploymentStatus::tryFromStored($this->employment_status);

        return $status === null || $status->isActive();
    }

    public function isAccountDeactivated(): bool
    {
        return ! $this->isOperationallyActive();
    }

    public function getEmploymentActiveStatusAttribute(): string
    {
        return $this->isOperationallyActive() ? 'active' : 'deactivated';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('employment_status')
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(employment_status, '-', '_'), ' ', '_')) NOT IN ('separated', 'inactive', 'resigned', 'terminated')");
            });
    }

    public function scopeDeactivated(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('is_active', false)
                ->orWhereRaw("LOWER(REPLACE(REPLACE(employment_status, '-', '_'), ' ', '_')) IN ('separated', 'inactive', 'resigned', 'terminated')");
        });
    }

    public function scopeRoster(Builder $query): Builder
    {
        return $query->visibleEmployees();
    }

    public function scopeActiveRoster(Builder $query): Builder
    {
        return $query->roster()->active();
    }

    /**
     * Operational staff roster (not system/hidden). Module-specific exclusions use
     * {@see scopeAttendanceEmployees()}, {@see scopeReportableEmployees()}, etc.
     */
    public function scopeVisibleEmployees(Builder $query): Builder
    {
        return $query->whereIn('role', self::ROSTER_ELIGIBLE_ROLES)
            ->where('is_system_user', false)
            ->where('is_hidden', false);
    }

    public function scopeApprovableEmployees(Builder $query): Builder
    {
        return $query->visibleEmployees()
            ->where('exclude_from_approvals', false);
    }

    public function scopeReportableEmployees(Builder $query): Builder
    {
        return $query->visibleEmployees()
            ->where('exclude_from_reports', false);
    }

    public function scopePayrollEmployees(Builder $query): Builder
    {
        return $query->visibleEmployees()
            ->where('exclude_from_payroll', false);
    }

    public function scopeAttendanceEmployees(Builder $query): Builder
    {
        return $query->visibleEmployees()
            ->where('exclude_from_attendance', false);
    }

    /**
     * Roster / payroll list ordering: family name first, then given names, then stable user id.
     */
    public function scopeOrderByLastName(Builder $query): Builder
    {
        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->orderBy('id');
    }

    /**
     * Stable sort key aligned with {@see scopeOrderByLastName()} for collections, ZIP ordering, and UI fallbacks.
     */
    public function employeeListingSortKey(): string
    {
        return sprintf(
            '%s|%s|%s|%010d',
            mb_strtolower(trim((string) ($this->last_name ?? '')), 'UTF-8'),
            mb_strtolower(trim((string) ($this->first_name ?? '')), 'UTF-8'),
            mb_strtolower(trim((string) ($this->middle_name ?? '')), 'UTF-8'),
            (int) $this->id
        );
    }

    public static function formatEmployeeDisplayName(
        ?string $firstName,
        ?string $middleName,
        ?string $lastName,
        ?string $suffix = null,
        ?string $legacyFullName = null
    ): string {
        $first = trim((string) $firstName);
        $middle = trim((string) $middleName);
        $last = trim((string) $lastName);
        $suffix = trim((string) $suffix);
        $legacy = trim((string) $legacyFullName);

        $given = trim(implode(' ', array_values(array_filter([$first, $middle], fn (string $part) => $part !== ''))));

        if ($last !== '' && $given !== '') {
            $name = $last.', '.$given;
        } elseif ($last !== '') {
            $name = $last;
        } elseif ($given !== '') {
            $name = $given;
        } else {
            $name = $legacy;
        }

        if ($name !== '' && $suffix !== '') {
            $name = trim($name.' '.$suffix);
        }

        return $name;
    }

    public function getDisplayNameAttribute(): string
    {
        return self::formatEmployeeDisplayName(
            $this->attributes['first_name'] ?? null,
            $this->attributes['middle_name'] ?? null,
            $this->attributes['last_name'] ?? null,
            $this->attributes['suffix'] ?? null,
            $this->attributes['name'] ?? null,
        );
    }

    public function getFormattedNameAttribute(): string
    {
        return $this->display_name;
    }

    public function getFullNameLastFirstAttribute(): string
    {
        return $this->display_name;
    }

    public function getNameAttribute($value): ?string
    {
        $display = self::formatEmployeeDisplayName(
            $this->attributes['first_name'] ?? null,
            $this->attributes['middle_name'] ?? null,
            $this->attributes['last_name'] ?? null,
            $this->attributes['suffix'] ?? null,
            is_string($value) ? $value : null,
        );

        return $display !== '' ? $display : (is_string($value) ? $value : null);
    }

    /**
     * Admin (HR) with an explicit org scope on {@see $company_id} / {@see $branch_id} / {@see $department_id}
     * / {@see $division_id} / {@see $section_unit_id}.
     * When false and {@see isAdmin()}, HR data access is global (within RBAC).
     */
    public function hasScopedHrAdminAssignment(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return $this->company_id !== null
            || $this->branch_id !== null
            || $this->department_id !== null
            || $this->division_id !== null
            || $this->section_unit_id !== null;
    }

    protected $fillable = [
        'name',
        'username',
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'phone_number',
        'date_of_birth',
        'gender',
        'civil_status',
        'nationality',
        'home_address',
        'full_address',
        'street_address',
        'barangay',
        'city',
        'province',
        'postal_code',
        'password',
        'account_export_password',
        'role',
        'employee_level',
        'employee_level_label',
        'employee_level_resolved_at',
        'is_super_admin',
        'is_system_user',
        'is_hidden',
        'exclude_from_reports',
        'exclude_from_payroll',
        'exclude_from_attendance',
        'exclude_from_approvals',
        'is_execom',
        'qr_token',
        'qr_token_generated_at',
        'face_descriptor',
        'face_descriptor_samples',
        'face_image',
        'face_registered_at',
        'face_embedding',
        'face_status',
        'face_liveness_type',
        'schedule',
        'working_schedule_id',
        'pending_working_schedule_id',
        'pending_schedule_effective_from',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
        'department',
        'department_id',
        'division_id',
        'section_unit_id',
        'company_id',
        'branch_id',
        'team_id',
        'position',
        'branch_office_location',
        'employment_type',
        'employment_status',
        'employment_status_effective_date',
        'regularization_date',
        'status_override',
        'hire_date',
        'payroll_effective_date',
        'contract_start_date',
        'contract_end_date',
        'supervisor_id',
        'assigned_team_leader_id',
        'pay_cycle_id',
        'profile_image',
        'signature_image',
        'signature_signed_at',
        'daily_rate',
        'monthly_rate',
        'monthly_salary',
        'hourly_rate',
        'salary_effectivity_date',
        'leave_credits',
        'leave_credits_reset_date',
        'leave_credits_initialized_at',
        'last_login_at',
        'employee_import_batch_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'account_export_password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'payroll_effective_date' => 'date',
            'pending_schedule_effective_from' => 'date',
            'employee_level' => 'integer',
            'employee_level_resolved_at' => 'datetime',
            'employment_status_effective_date' => 'date',
            'regularization_date' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'password' => 'hashed',
            'account_export_password' => 'encrypted',
            'qr_token_generated_at' => 'datetime',
            'face_registered_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_system_user' => 'boolean',
            'is_hidden' => 'boolean',
            'status_override' => 'boolean',
            'exclude_from_reports' => 'boolean',
            'exclude_from_payroll' => 'boolean',
            'exclude_from_attendance' => 'boolean',
            'exclude_from_approvals' => 'boolean',
            'is_execom' => 'boolean',
            'schedule' => 'array',
            'face_descriptor' => 'encrypted',
            'face_embedding' => 'encrypted',
            'face_descriptor_samples' => EncryptedArray::class,
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'signature_signed_at' => 'datetime',
            'daily_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'salary_effectivity_date' => 'date',
            'leave_credits' => 'integer',
            'leave_credits_reset_date' => 'date',
            'leave_credits_initialized_at' => 'datetime',
        ];
    }

    /**
     * Include normalized avatar URL in JSON so UIs can load /api/media/public/… without re-implementing path rules.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_image_url',
        'profile_picture_url',
        'avatar_url',
        'photo_url',
        'employment_active_status',
        'display_name',
        'formatted_name',
        'full_name_last_first',
    ];

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            EmployeeProfileCache::forgetForUser((int) $user->id);

            if ($user->wasChanged([
                'is_active',
                'employment_status',
                'company_id',
                'branch_id',
                'department_id',
                'division_id',
                'section_unit_id',
                'face_descriptor',
                'face_embedding',
                'face_descriptor_samples',
                'face_status',
                'face_registered_at',
            ])) {
                $oldCompanyId = $user->getOriginal('company_id');
                FaceEmbeddingCacheService::invalidateFaceCache(
                    (int) $user->id,
                    $oldCompanyId !== null ? (int) $oldCompanyId : ($user->company_id ? (int) $user->company_id : null)
                );
                if ($oldCompanyId !== null && (int) $oldCompanyId !== (int) $user->company_id) {
                    FaceEmbeddingCacheService::forgetCompanyIndex($user->company_id ? (int) $user->company_id : null);
                }
            }

            if ($user->wasChanged([
                'role',
                'is_super_admin',
                'is_execom',
                'company_id',
                'branch_id',
                'department_id',
                'division_id',
                'section_unit_id',
                'team_id',
                'assigned_team_leader_id',
                'supervisor_id',
                'is_active',
            ])) {
                try {
                    app(EmployeeLevelResolver::class)->syncCachedLevel($user, 'user_profile_or_role_changed');
                } catch (\Throwable) {
                    // Employee save should not fail because a derived cache could not refresh.
                }
            }
        });

        static::deleted(function (User $user): void {
            EmployeeProfileCache::forgetForUser((int) $user->id);
            FaceEmbeddingCacheService::invalidateFaceCache((int) $user->id, $user->company_id ? (int) $user->company_id : null);
        });
    }

    protected function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    public function governmentDeductionSetting(): HasOne
    {
        return $this->hasOne(EmployeeGovernmentDeductionSetting::class, 'user_id');
    }

    /**
     * Current business date for attendance. Approved corrections must match this
     * exact date before they can affect today's clock-in/out state.
     */
    protected function attendanceTodayDateKey(): string
    {
        return Carbon::now($this->attendanceTimezone())->toDateString();
    }

    /**
     * Start and end of an attendance date in UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function attendanceDateRangeUtc(string $dateKey): array
    {
        $day = Carbon::parse($dateKey, $this->attendanceTimezone())->startOfDay();

        return [
            $day->copy()->setTimezone('UTC'),
            $day->copy()->endOfDay()->setTimezone('UTC'),
        ];
    }

    /**
     * Match logs by their effective punch timestamp: verified_at first, created_at
     * only for legacy rows. This keeps approved corrections from another date out
     * of today's status even if the row was inserted or updated later.
     */
    protected function attendanceLogEffectiveDateQuery($query, string $dateKey)
    {
        [$start, $end] = $this->attendanceDateRangeUtc($dateKey);

        return $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('verified_at', [$start, $end])
                ->orWhere(function ($fallback) use ($start, $end) {
                    $fallback->whereNull('verified_at')
                        ->whereBetween('created_at', [$start, $end]);
                });
        });
    }

    protected function approvedAttendanceCorrectionForDate(string $dateKey)
    {
        return AttendanceCorrection::where('user_id', $this->id)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at')
            ->whereDate('date', $dateKey);
    }

    /**
     * Last attendance log for this user on the current attendance day.
     */
    public function lastAttendanceToday(): ?AttendanceLog
    {
        $todayDate = $this->attendanceTodayDateKey();

        return $this->attendanceLogEffectiveDateQuery(
            AttendanceLog::where('user_id', $this->id),
            $todayDate
        )
            ->orderByRaw('COALESCE(verified_at, created_at) DESC')
            ->first();
    }

    /**
     * True if the user has any clock_in record today (prevents duplicate time-in per day).
     * Uses attendance timezone so "today" is the business day, not server/UTC.
     * Also considers approved manual attendance corrections that have a time_in set.
     */
    public function hasTimedInToday(): bool
    {
        $todayDate = $this->attendanceTodayDateKey();

        if ($this->attendanceLogEffectiveDateQuery(
            AttendanceLog::where('user_id', $this->id),
            $todayDate
        )->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists()) {
            return true;
        }

        // Date-specific: only today's approved correction can count as today's time-in.
        return $this->approvedAttendanceCorrectionForDate($todayDate)
            ->whereNotNull('time_in')
            ->exists();
    }

    /**
     * True if the user can clock out (last log today is clock_in; no clock_out after it yet).
     * Also considers approved manual corrections: time_in set but time_out not yet set.
     */
    public function canClockOutToday(): bool
    {
        $last = $this->lastAttendanceToday();

        if ($last && $last->type === AttendanceLog::TYPE_CLOCK_IN) {
            return true;
        }

        $todayDate = $this->attendanceTodayDateKey();

        // Date-specific: a previous day's approved correction cannot open/close today's session.
        return $this->approvedAttendanceCorrectionForDate($todayDate)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->exists();
    }

    /**
     * True if any clock_out attendance log exists today (business-day UTC window).
     * Used by kiosk rules to allow at most one “orphan” clock-out without a same-day clock-in.
     */
    public function hasClockOutToday(): bool
    {
        $todayDate = $this->attendanceTodayDateKey();

        return $this->attendanceLogEffectiveDateQuery(
            AttendanceLog::where('user_id', $this->id),
            $todayDate
        )->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();
    }

    /**
     * True if the user has both clock_in and clock_out for today (attendance completed).
     * Uses attendance timezone so "today" is the business day.
     * Also considers approved manual attendance corrections that have both time_in and time_out set.
     */
    public function hasCompletedAttendanceToday(): bool
    {
        $todayDate = $this->attendanceTodayDateKey();

        $todayLogs = $this->attendanceLogEffectiveDateQuery(
            AttendanceLog::where('user_id', $this->id),
            $todayDate
        );

        $hasIn = (clone $todayLogs)
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = (clone $todayLogs)
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        if ($hasIn && $hasOut) {
            return true;
        }

        // Date-specific: only a completed correction filed for today's date completes today.
        return $this->approvedAttendanceCorrectionForDate($todayDate)
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->exists();
    }

    /**
     * Whether this user has a registered face embedding (allowed to use facial recognition DTR).
     * Accepts both legacy 128D (DeepFace) and current 512D (InsightFace) embeddings.
     */
    public function hasRegisteredFace(): bool
    {
        $samples = $this->face_descriptor_samples;
        if (is_array($samples) && ! empty($samples)) {
            foreach ($samples as $sample) {
                if (is_array($sample) && count($sample) >= 64) {
                    return true;
                }
            }
        }

        $stored = $this->face_embedding ?? $this->face_descriptor;
        if (empty($stored)) {
            return false;
        }
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) && ! isset($decoded['type']) && count($decoded) >= 64;
    }

    /**
     * Whether the stored face embedding uses the legacy format (e.g. 128D from DeepFace)
     * and needs re-registration with the current model (512D InsightFace).
     */
    public function needsFaceReregistration(): bool
    {
        if (! $this->hasRegisteredFace()) {
            return false;
        }

        $dim = \App\Services\FaceVerificationService::EMBEDDING_DIM;

        $samples = $this->face_descriptor_samples;
        if (is_array($samples) && ! empty($samples)) {
            foreach ($samples as $sample) {
                if (is_array($sample) && count($sample) === $dim) {
                    return false;
                }
            }
        }

        $stored = $this->face_embedding ?? $this->face_descriptor;
        if (! empty($stored)) {
            $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
            if (is_array($decoded) && ! isset($decoded['type']) && count($decoded) === $dim) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fully clear face registration artifacts so re-registration can proceed immediately.
     */
    public function clearFaceRegistrationData(?int $actorUserId = null): void
    {
        DB::transaction(function () use ($actorUserId): void {
            $this->forceFill([
                'face_descriptor' => null,
                'face_embedding' => null,
                'face_descriptor_samples' => null,
                'face_image' => null,
                'face_registered_at' => null,
                'face_status' => 'not_registered',
                'face_liveness_type' => null,
            ])->save();

            DuplicateFaceRegistrationAttempt::query()
                ->where(function ($q) {
                    $q->where('attempted_for_user_id', $this->id)
                        ->orWhere('existing_user_id', $this->id);
                })
                ->delete();

            FailedFaceAttempt::query()
                ->where('user_id', $this->id)
                ->delete();

            if ($actorUserId !== null) {
                UserAdminActivityLog::query()->create([
                    'subject_user_id' => $this->id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'face_registration_reset',
                    'meta' => [
                        'face_status' => 'not_registered',
                    ],
                    'ip_address' => request()?->ip(),
                ]);
            }

            FaceVerificationService::bumpDuplicateEmbeddingIndexVersion();
        });
        FaceEmbeddingCacheService::invalidateFaceCache((int) $this->id, $this->company_id ? (int) $this->company_id : null);
    }

    /**
     * Resolved HR role key (e.g. admin_hr, company_head) for middleware and policies.
     * Not stored on users; mirrors {@see HrRoleResolver::resolve()}.
     */
    public function getHrRoleAttribute(): string
    {
        return app(HrRoleResolver::class)->resolve($this)->value;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN
            || ($this->isAdmin() && (bool) $this->is_super_admin);
    }

    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    /**
     * Whether this user may use the employee self-service profile API (/employee/profile/*)
     * for their own record (employees, or org heads managing their own HR profile).
     */
    public function canAccessSelfServiceEmployeeProfile(): bool
    {
        if ($this->isSystemAccessOnly()) {
            return false;
        }

        if ($this->isEmployee()) {
            return true;
        }
        if ($this->isAdmin()) {
            return true;
        }

        return app(\App\Services\HrRoleResolver::class)->isAssignedOrganizationHead($this);
    }

    /**
     * Whether this user may record their own attendance via QR or face (aligned with My QR / profile enrollment).
     */
    public function canRecordOwnAttendanceViaQrOrFace(): bool
    {
        return ! $this->isExcludedFromAttendance()
            && $this->canAccessSelfServiceEmployeeProfile();
    }

    /**
     * Generate QR token for attendance scanning.
     * Includes company_id when assigned for mismatch validation on scan.
     * Format: DTR-EMP-0001-CO-5 (assigned) or DTR-EMP-0001 (unassigned).
     */
    public static function generateQrTokenFor(User $user): string
    {
        $companyId = $user->getEffectiveCompanyId();

        if ($companyId !== null) {
            return sprintf('DTR-EMP-%04d-CO-%d', $user->id, $companyId);
        }

        return sprintf('DTR-EMP-%04d', $user->id);
    }

    /**
     * If the stored qr_token no longer matches the employee's effective company (e.g. admin
     * updated org fields via PATCH without transfer), regenerate so scans succeed.
     *
     * @return bool True if the token was updated
     */
    public function syncQrTokenWithEffectiveCompany(): bool
    {
        $this->refresh();

        $token = trim((string) ($this->qr_token ?? ''));
        $embedded = null;
        $formatOk = false;

        if ($token !== '') {
            if (preg_match('/^DTR-EMP-\d+(?:-CO-(\d+))?$/', $token, $m)) {
                $formatOk = true;
                $embedded = isset($m[1]) ? (int) $m[1] : null;
            }
        }

        $effective = $this->getEffectiveCompanyId();

        $semanticsMatch = $formatOk
            && (($embedded === null && $effective === null)
                || ($embedded !== null && $effective !== null && (int) $embedded === (int) $effective));

        if ($semanticsMatch) {
            return false;
        }

        // No token yet but also no company → nothing to embed; leave null (getMyQr will generate)
        if ($token === '' && $effective === null) {
            return false;
        }

        $this->forceFill([
            'qr_token' => self::generateQrTokenFor($this),
            'qr_token_generated_at' => now(),
        ])->save();

        return true;
    }

    public function departmentRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function execomProfiles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExecomEmployeeProfile::class, 'employee_id');
    }

    public function activeExecomProfileForPeriod(?\Carbon\CarbonInterface $periodStart = null, ?\Carbon\CarbonInterface $periodEnd = null): ?ExecomEmployeeProfile
    {
        return ExecomEmployeeProfile::query()
            ->where('employee_id', (int) $this->id)
            ->activeForPeriod($periodStart, $periodEnd)
            ->orderByDesc('id')
            ->first();
    }

    public function division(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Division::class, 'division_id');
    }

    public function sectionUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SectionUnit::class, 'section_unit_id');
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Companies where this user is designated as Company Head. */
    public function companyHeadships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Company::class, 'company_head_id');
    }

    /**
     * The company this employee belongs to (company head, direct, branch, or department).
     * Used for duplicate-assignment validation: one employee = one company.
     */
    public function getEffectiveCompanyId(): ?int
    {
        $headOf = $this->companyHeadships()->first();
        if ($headOf) {
            return $headOf->id;
        }
        if ($this->company_id) {
            return $this->company_id;
        }
        if ($this->branch_id) {
            $b = $this->branch;
            if ($b) {
                return $b->company_id;
            }
        }
        if ($this->department_id) {
            $d = $this->departmentRelation;
            if ($d?->branch) {
                return $d->branch->company_id;
            }
        }
        if ($this->division_id) {
            $division = $this->division;
            if ($division?->company_id) {
                return $division->company_id;
            }
            if ($division?->branch) {
                return $division->branch->company_id;
            }
        }
        if ($this->section_unit_id) {
            $section = $this->sectionUnit;
            if ($section?->company_id) {
                return $section->company_id;
            }
            if ($section?->branch) {
                return $section->branch->company_id;
            }
            if ($section?->department?->branch) {
                return $section->department->branch->company_id;
            }
            if ($section?->division) {
                return $section->division->company_id
                    ?? $section->division->branch?->company_id;
            }
        }

        return null;
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Branch this user manages (branch_manager_id = this user). One user can manage at most one branch. */
    public function managedBranch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Branch::class, 'branch_manager_id');
    }

    /** Department this user heads (department_head_id = this user). One user can head at most one department. */
    public function managedDepartment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Department::class, 'department_head_id');
    }

    /** Division this user heads (division_head_id = this user). */
    public function managedDivision(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Division::class, 'division_head_id');
    }

    /** Section/Unit this user heads (section_unit_head_id = this user). */
    public function managedSectionUnit(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SectionUnit::class, 'section_unit_head_id');
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamLeaderDepartments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_team_leaders', 'employee_id', 'department_id')
            ->withTimestamps();
    }

    public function teamLeaderSections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SectionUnit::class, 'section_unit_team_leaders', 'employee_id', 'section_unit_id')
            ->withTimestamps();
    }

    public function workingSchedule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class, 'working_schedule_id');
    }

    public function pendingWorkingSchedule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class, 'pending_working_schedule_id');
    }

    public function supervisor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function assignedTeamLeader(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_team_leader_id');
    }

    public function organizationAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeOrganizationAssignment::class, 'employee_id');
    }

    public function primaryOrganizationAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeOrganizationAssignment::class, 'employee_id')
            ->where('is_primary', true)
            ->where('is_active', true)
            ->latest('id');
    }

    public function payCycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PayCycle::class, 'pay_cycle_id');
    }

    /**
     * Resolved pay cycle for payroll (direct assignment, then company/branch defaults). See PayCycleService::resolveForUser.
     */
    public function resolveEffectivePayCycle(): ?PayCycle
    {
        return app(\App\Services\PayCycleService::class)->resolveForUser($this);
    }

    public function payrollPeriods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollPeriod::class, 'user_id');
    }

    public function employeeBenefits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeBenefit::class, 'user_id');
    }

    public function compensationComponents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeCompensationComponent::class, 'user_id');
    }

    /** Payroll deductions / loans assigned to this employee ({@see EmployeeDeduction}). */
    public function employeeDeductions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeDeduction::class, 'user_id');
    }

    public function leaveCreditTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class, 'user_id');
    }

    public function governmentIds(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeGovernmentId::class, 'user_id');
    }

    public function emergencyContacts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class, 'user_id');
    }

    public function statutoryContributions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeStatutoryContribution::class, 'employee_id');
    }

    public function taxInfo(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeTaxInfo::class, 'user_id');
    }

    public function governmentLoans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeGovernmentLoan::class, 'user_id');
    }

    public function skills(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'user_id');
    }

    public function statusHistories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class, 'user_id');
    }

    public function regularizationRecommendations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RegularizationRecommendation::class, 'user_id');
    }

    public function probationMilestoneNotifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProbationMilestoneNotification::class, 'user_id');
    }

    /**
     * Normalized profile image URL for API responses.
     *
     * Handles historical values where profile_image may already contain:
     * - a full http(s) URL
     * - a path starting with "storage/"
     * - a path under "profiles/" or other public disk paths.
     */
    public function getProfileImageUrlAttribute(): ?string
    {
        $raw = $this->profile_image;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        $normalized = ltrim($raw, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        return url('/api/media/public/'.$this->encodeStoragePath($normalized));
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        return $this->profile_image_url;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->profile_image_url;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->profile_image_url;
    }

    public function getSignatureImageUrlAttribute(): ?string
    {
        $raw = $this->signature_image;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $normalized = ltrim(trim($raw), '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        return url('/api/media/public/'.$this->encodeStoragePath($normalized));
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }
}
