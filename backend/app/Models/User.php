<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\EncryptedArray;
use App\Services\FaceVerificationService;
use App\Services\HrRoleResolver;
use App\Support\EmployeeProfileCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    /**
     * Staff included in HR rosters (Employee module lists, payroll runs, attendance/reports scopes, etc.).
     * Admin (HR) accounts are part of this set so they appear and behave as staff while retaining admin capabilities.
     *
     * @var list<string>
     */
    public const ROSTER_ELIGIBLE_ROLES = [self::ROLE_EMPLOYEE, self::ROLE_ADMIN];

    public function isRosterEligible(): bool
    {
        return in_array($this->role, self::ROSTER_ELIGIBLE_ROLES, true);
    }

    /**
     * Admin (HR) with an explicit org scope on {@see $company_id} / {@see $branch_id} / {@see $department_id}.
     * When false and {@see isAdmin()}, HR data access is global (within RBAC).
     */
    public function hasScopedHrAdminAssignment(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return $this->company_id !== null
            || $this->branch_id !== null
            || $this->department_id !== null;
    }

    protected $fillable = [
        'name',
        'username',
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
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
        'role',
        'is_super_admin',
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
        'department',
        'department_id',
        'company_id',
        'branch_id',
        'team_id',
        'position',
        'branch_office_location',
        'employment_type',
        'employment_status',
        'employment_status_effective_date',
        'hire_date',
        'contract_start_date',
        'contract_end_date',
        'supervisor_id',
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
            'pending_schedule_effective_from' => 'date',
            'employment_status_effective_date' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'password' => 'hashed',
            'qr_token_generated_at' => 'datetime',
            'face_registered_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'schedule' => 'array',
            'face_descriptor' => 'encrypted',
            'face_embedding' => 'encrypted',
            'face_descriptor_samples' => EncryptedArray::class,
            'is_active' => 'boolean',
            'signature_signed_at' => 'datetime',
            'daily_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'salary_effectivity_date' => 'date',
            'leave_credits' => 'integer',
            'leave_credits_reset_date' => 'date',
        ];
    }

    /**
     * Include normalized avatar URL in JSON so UIs can load /api/media/public/… without re-implementing path rules.
     *
     * @var list<string>
     */
    protected $appends = ['profile_image_url'];

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            EmployeeProfileCache::forgetForUser((int) $user->id);
        });

        static::deleted(function (User $user): void {
            EmployeeProfileCache::forgetForUser((int) $user->id);
        });
    }

    /**
     * Start and end of "today" in attendance timezone, as UTC (for DB created_at queries).
     * Ensures QR/scan "already timed in today" uses business day, not server/UTC day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function attendanceTodayRangeUtc(): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $today = Carbon::now($tz)->startOfDay();
        $start = $today->copy()->setTimezone('UTC');
        $end = $today->copy()->endOfDay()->setTimezone('UTC');

        return [$start, $end];
    }

    /**
     * Last attendance log for this user on the current attendance day (by created_at in that day's UTC range).
     */
    public function lastAttendanceToday(): ?AttendanceLog
    {
        [$start, $end] = $this->attendanceTodayRangeUtc();

        return AttendanceLog::where('user_id', $this->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * True if the user has any clock_in record today (prevents duplicate time-in per day).
     * Uses attendance timezone so "today" is the business day, not server/UTC.
     * Also considers approved manual attendance corrections that have a time_in set.
     */
    public function hasTimedInToday(): bool
    {
        [$start, $end] = $this->attendanceTodayRangeUtc();

        if (AttendanceLog::where('user_id', $this->id)
            ->whereBetween('created_at', [$start, $end])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists()) {
            return true;
        }

        // An approved manual correction with time_in counts as "timed in"
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $todayDate = Carbon::now($tz)->toDateString();

        return AttendanceCorrection::where('user_id', $this->id)
            ->where('approved', true)
            ->whereDate('date', $todayDate)
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

        // An approved correction with only time_in (no time_out) means the employee
        // clocked in via manual record and still needs to clock out
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $todayDate = Carbon::now($tz)->toDateString();

        return AttendanceCorrection::where('user_id', $this->id)
            ->where('approved', true)
            ->whereDate('date', $todayDate)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->exists();
    }

    /**
     * True if the user has both clock_in and clock_out for today (attendance completed).
     * Uses attendance timezone so "today" is the business day.
     * Also considers approved manual attendance corrections that have both time_in and time_out set.
     */
    public function hasCompletedAttendanceToday(): bool
    {
        [$start, $end] = $this->attendanceTodayRangeUtc();

        $hasIn = AttendanceLog::where('user_id', $this->id)
            ->whereBetween('created_at', [$start, $end])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = AttendanceLog::where('user_id', $this->id)
            ->whereBetween('created_at', [$start, $end])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        if ($hasIn && $hasOut) {
            return true;
        }

        // An approved manual correction with both time_in and time_out is a completed attendance day
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $todayDate = Carbon::now($tz)->toDateString();

        return AttendanceCorrection::where('user_id', $this->id)
            ->where('approved', true)
            ->whereDate('date', $todayDate)
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
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && (bool) $this->is_super_admin;
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
        return $this->canAccessSelfServiceEmployeeProfile();
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

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
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
