<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentStatus;
use App\Enums\HrRole;
use App\Models\AttendanceLog;
use App\Models\FailedFaceAttempt;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\FaceAuthService;
use App\Services\FaceVerificationService;
use App\Services\HrRoleResolver;
use App\Services\LeaveCreditService;
use App\Services\PayCycleService;
use App\Services\RbacService;
use App\Services\ScheduleRateService;
use App\Support\EmployeeProfileCache;
use App\Support\LeaveScheduleSupport;
use App\Support\ManagementRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $phoneRequired = (bool) config('attendance.employee_phone_required', true);

        $phoneRules = [
            $phoneRequired ? 'required' : 'nullable',
            'string',
            'regex:/^\+63\s?9\d{9}$/u',
            'unique:users,phone_number',
        ];

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'phone_number' => $phoneRules,
        ], [
            'phone_number.regex' => 'The phone number must start with +63 and be followed by exactly 10 digits (e.g. +63 912 345 6789).',
            'phone_number.unique' => 'This phone number is already registered.',
        ]);

        $rawPhone = $validated['phone_number'] ?? null;
        $phone = is_string($rawPhone) && trim($rawPhone) !== '' ? \App\Services\SmsService::normalizePhone($rawPhone) : null;

        $user = User::create([
            'name' => trim($validated['first_name'].' '.$validated['last_name']),
            'username' => trim((string) $validated['username']),
            'email' => $validated['email'],
            'phone_number' => $phone,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;
        $user->refresh(); // ensure response matches DB (e.g. phone_number)

        return response()->json([
            'user' => $this->userResponse($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Login user and return token.
     */
    public function login(Request $request): JsonResponse
    {
        $start = microtime(true);
        // Temporary diagnostic guard while investigating timeout spikes in login.
        @ini_set('max_execution_time', '120');
        Log::info('Auth login timing start', ['endpoint' => 'Auth.login']);

        $validationStart = microtime(true);
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'validate_request',
            'time_ms' => round((microtime(true) - $validationStart) * 1000),
        ]);

        /** @var User|null $user */
        $lookupStart = microtime(true);
        $loginValue = trim((string) $validated['login']);
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($loginValue)])
            ->orWhereRaw('LOWER(username) = ?', [Str::lower($loginValue)])
            ->first();
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'user_lookup',
            'time_ms' => round((microtime(true) - $lookupStart) * 1000),
            'user_found' => (bool) $user,
        ]);

        $passwordCheckStart = microtime(true);
        if (! $user || ! Hash::check($validated['password'], (string) $user->password)) {
            Log::warning('Auth login failed', [
                'endpoint' => 'Auth.login',
                'step' => 'password_check',
                'time_ms' => round((microtime(true) - $passwordCheckStart) * 1000),
                'reason' => 'invalid_credentials',
            ]);
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'password_check',
            'time_ms' => round((microtime(true) - $passwordCheckStart) * 1000),
        ]);

        $clearStateStart = microtime(true);
        $this->clearSessionAuthState($request);
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'clear_session_auth_state',
            'time_ms' => round((microtime(true) - $clearStateStart) * 1000),
        ]);

        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'login' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        $lastLoginStart = microtime(true);
        $user->forceFill(['last_login_at' => now()])->save();
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'update_last_login',
            'time_ms' => round((microtime(true) - $lastLoginStart) * 1000),
        ]);

        $tokenStart = microtime(true);
        $token = $user->createToken('auth-token')->plainTextToken;
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'token_generation',
            'time_ms' => round((microtime(true) - $tokenStart) * 1000),
        ]);

        $payloadStart = microtime(true);
        $authTtl = now()->addMinutes((int) config('cache.profile_ttl_minutes', 5));
        $userPayload = EmployeeProfileCache::remember(
            (int) $user->id,
            'auth_user_payload',
            ['version' => 5, 'include_leave_credits' => false],
            $authTtl,
            fn () => $this->userResponse($user, ['include_leave_credits' => false])
        );
        Log::info('Auth login timing step', [
            'endpoint' => 'Auth.login',
            'step' => 'build_user_payload',
            'time_ms' => round((microtime(true) - $payloadStart) * 1000),
        ]);

        Log::info('Auth login timing end', [
            'endpoint' => 'Auth.login',
            'user_id' => $user->id,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return response()->json([
            'user' => $userPayload,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout user (revoke token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        $this->clearSessionAuthState($request);

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        $start = microtime(true);
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if ($authUser->isAccountDeactivated()) {
            $authUser->currentAccessToken()?->delete();

            return response()->json([
                'message' => User::DEACTIVATED_LOGIN_MESSAGE,
            ], 403);
        }
        Log::info('Auth user endpoint timing start', [
            'endpoint' => 'Auth.user',
            'user_id' => $authUser?->id,
        ]);

        $userPayloadStart = microtime(true);
        $authTtl = now()->addMinutes((int) config('cache.profile_ttl_minutes', 5));
        $payload = EmployeeProfileCache::remember(
            (int) $authUser->id,
            'auth_user_payload',
            ['version' => 5, 'include_leave_credits' => false],
            $authTtl,
            fn () => $this->userResponse($authUser, ['include_leave_credits' => false])
        );
        $userPayloadMs = round((microtime(true) - $userPayloadStart) * 1000);

        Log::info('Auth user endpoint timing end', [
            'endpoint' => 'Auth.user',
            'user_id' => $authUser?->id,
            'user_payload_ms' => $userPayloadMs,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return response()->json([
            'user' => $payload,
        ]);
    }

    /**
     * Login via QR code scan (employee badge).
     */
    public function loginWithQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:1'],
        ]);

        $user = User::where('qr_token', $validated['qr_token'])
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'qr_token' => ['QR code not recognized.'],
            ]);
        }

        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'qr_token' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        $this->clearSessionAuthState($request);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $this->userResponse($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Login via face recognition using Amazon Rekognition Face Liveness.
     *
     * Flow:
     * 1. Frontend: Amplify FaceLivenessDetector completes guided liveness (sessionId).
     * 2. Backend: GetFaceLivenessSessionResults → reference image → Python /embed → match → DTR.
     *
     * Accepts liveness_session_id (from Rekognition create session) or legacy image_base64.
     * Stores similarity_score, liveness_score, authentication_method in attendance log.
     */
    public function loginWithFace(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'liveness_session_id' => ['nullable', 'string', 'max:255'],
            'image_base64' => ['nullable', 'string'],
            'client_capture_started_at_ms' => ['nullable', 'numeric'],
        ], [
            'liveness_session_id.required_without' => 'Either liveness session or face image is required.',
        ]);
        $sessionId = $validated['liveness_session_id'] ?? null;
        $imageBase64 = $validated['image_base64'] ?? null;
        if (! $sessionId && ! $imageBase64) {
            return response()->json([
                'message' => 'Face liveness session or face image is required.',
                'errors' => ['face' => ['Perform face liveness first, then submit the session.']],
                'error_code' => 'validation_error',
            ], 422);
        }

        $result = $sessionId
            ? FaceAuthService::verifyFaceWithLivenessSession($sessionId)
            : FaceAuthService::verifyFace($imageBase64);

        if ($result === null) {
            $this->recordFaceLoginFailure($request, null, false, 'service_unavailable');

            return response()->json([
                'message' => 'Face verification service unavailable. Please try again or use credentials.',
                'errors' => ['face' => ['Face verification service unavailable. Please try again or use credentials.']],
                'error_code' => 'service_unavailable',
            ], 422);
        }

        if (! $result['is_live']) {
            $this->recordFaceLoginFailure($request, null, true, 'spoof_detected');

            return response()->json([
                'message' => 'Spoof attempt detected. Please perform a live face scan.',
                'errors' => ['face' => ['Spoof attempt detected. Please perform a live face scan.']],
                'error_code' => 'spoof_detected',
            ], 422);
        }

        // Rekognition Face Liveness path: RekognitionLivenessService already applied face_min_liveness_score.
        // Avoid a second duplicate gate that could drift out of sync and increase false rejects.
        //
        // Legacy image path: Python /verify still supplies spoof_confidence; enforce minimum there.
        if (! $sessionId) {
            $minLiveness = (float) config('attendance.face_min_liveness_score', 0.52);
            $spoofConfidence = isset($result['spoof_confidence']) ? (float) $result['spoof_confidence'] : null;
            if ($spoofConfidence === null || $spoofConfidence < $minLiveness) {
                $this->recordFaceLoginFailure($request, null, true, 'liveness_failed');

                return response()->json([
                    'message' => 'Liveness confidence too low. Please complete the face liveness check again.',
                    'errors' => ['face' => ['Liveness confidence too low. Please complete the face liveness check again.']],
                    'error_code' => 'spoof_detected',
                ], 422);
            }
        }

        if (empty($result['descriptor']) || count($result['descriptor']) !== FaceVerificationService::EMBEDDING_DIM) {
            $this->recordFaceLoginFailure($request, null, false, 'no_face_detected');
            $msg = $result['message'] ?: 'No face detected. Position your face in the frame.';

            return response()->json([
                'message' => $msg,
                'errors' => ['face' => [$msg]],
                'error_code' => 'no_face_detected',
            ], 422);
        }

        $identified = FaceAuthService::identifyUserWithScore($result['descriptor']);

        if (! $identified) {
            $this->recordFaceLoginFailure($request, null, false, 'face_not_recognized');

            return response()->json([
                'message' => 'We could not match your face to an enrolled profile. Face the camera straight-on, use even lighting (no glare), then try again—or sign in with username/email and password.',
                'errors' => ['face' => ['No match this time. Try again with steady lighting, or use username/email and password.']],
                'error_code' => 'face_not_recognized',
                'hint' => 'If you registered your face already, a second try after adjusting lighting often works.',
                'fallback' => 'password',
            ], 422);
        }

        $user = $identified['user'];

        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'face' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        if (! $user->hasRegisteredFace()) {
            $this->recordFaceLoginFailure($request, $user->id, false, 'face_not_registered');

            return response()->json([
                'message' => 'No registered face found. Please register your face before using facial recognition clock-in.',
                'errors' => ['face' => ['No registered face found. Please register your face before using facial recognition clock-in.']],
                'error_code' => 'face_not_registered',
            ], 422);
        }

        if ($user->needsFaceReregistration()) {
            return response()->json([
                'message' => 'Your face data needs to be updated. Please re-register your face in My QR & Face.',
                'errors' => ['face' => ['Your face data needs to be updated. Please re-register your face in My QR & Face.']],
                'error_code' => 'face_needs_reregistration',
            ], 422);
        }

        $this->clearSessionAuthState($request);

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('auth-token')->plainTextToken;

        $livenessScore = isset($result['spoof_confidence']) ? (float) $result['spoof_confidence'] : null;
        $faceContext = [
            'similarity_score' => $identified['similarity_score'],
            'liveness_score' => $livenessScore,
            'authentication_method' => AttendanceLog::AUTH_METHOD_FACE,
        ];
        $attendanceResult = app(AttendanceController::class)->recordClockInForUser($user, $request, $faceContext);

        $payload = [
            'user' => $this->userResponse($user),
            'token' => $token,
            'token_type' => 'Bearer',
            'attendance' => $attendanceResult,
            'performance' => [
                'server_processing_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'client_capture_started_at_ms' => isset($validated['client_capture_started_at_ms']) ? (int) $validated['client_capture_started_at_ms'] : null,
            ],
        ];

        Log::info('Face login performance', [
            'user_id' => $user->id,
            'uses_liveness_session' => ! empty($sessionId),
            'server_processing_ms' => $payload['performance']['server_processing_ms'],
            'client_capture_started_at_ms' => $payload['performance']['client_capture_started_at_ms'],
        ]);

        return response()->json($payload);
    }

    private function recordFaceLoginFailure(Request $request, ?int $userId, bool $isSpoof, ?string $failureReason = null): void
    {
        FailedFaceAttempt::create([
            'user_id' => $userId,
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'user_agent' => $request->userAgent(),
            'is_spoof' => $isSpoof,
            'failure_reason' => $failureReason,
        ]);
    }

    private function clearSessionAuthState(Request $request): void
    {
        Auth::guard('web')->logout();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Verify authenticated user's QR token (optional extra verification step).
     */
    public function verifyQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:8'],
        ]);

        $user = $request->user();
        if (empty($user->qr_token)) {
            throw ValidationException::withMessages([
                'qr_token' => ['No QR code enrolled for your account.'],
            ]);
        }

        if (! hash_equals((string) $user->qr_token, (string) $validated['qr_token'])) {
            throw ValidationException::withMessages([
                'qr_token' => ['Invalid QR code. Please scan again.'],
            ]);
        }

        return response()->json(['verified' => true]);
    }

    /**
     * Standard user payload for login, register, and user endpoints.
     */
    public function userResponse(User $user, array $options = []): array
    {
        $start = microtime(true);
        $lite = (bool) ($options['lite'] ?? false);
        $includeLeaveCredits = $lite ? false : (bool) ($options['include_leave_credits'] ?? true);
        if (! $lite && $user->id) {
            $rechargeStart = microtime(true);
            $rechargeCooldownKey = sprintf('auth:user:recharge:cooldown:%d', (int) $user->id);
            $didRecharge = false;
            if (! Cache::has($rechargeCooldownKey)) {
                app(LeaveCreditService::class)->ensureAnnualRechargeForUserId((int) $user->id);
                Cache::put($rechargeCooldownKey, true, now()->addMinutes(15));
                $didRecharge = true;
            }
            if ($didRecharge) {
                $user->refresh();
            }
            Log::info('Auth userResponse timing step', [
                'endpoint' => 'Auth.userResponse',
                'user_id' => $user->id,
                'step' => 'leave_credit_recharge_and_refresh',
                'time_ms' => round((microtime(true) - $rechargeStart) * 1000),
                'recharged' => $didRecharge,
            ]);
        }

        // Ensure org relations and working schedule are loaded (single source of truth for shift)
        $relationsStart = microtime(true);
        $user->loadMissing([
            'companyHeadships:id,name,logo,company_head_id',
            'company:id,name,logo,default_pay_cycle_id',
            'branch:id,name,company_id',
            'branch.company:id,name,logo',
            'managedBranch:id,name,company_id,branch_manager_id',
            'managedDepartment:id,name,branch_id,department_head_id',
            'departmentRelation:id,name,branch_id,department_head_id',
            'departmentRelation.branch:id,name,company_id',
            'departmentRelation.branch.company:id,name,logo',
            'workingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
            'pendingWorkingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
        ]);
        Log::info('Auth userResponse timing step', [
            'endpoint' => 'Auth.userResponse',
            'user_id' => $user->id,
            'step' => 'load_missing_relations',
            'time_ms' => round((microtime(true) - $relationsStart) * 1000),
        ]);

        $computedStart = microtime(true);
        $hrResolver = app(HrRoleResolver::class);
        $hr = $hrResolver->resolve($user);
        $hrRolesList = $hrResolver->listEffectiveHrRoles($user);
        $rbac = app(RbacService::class);
        $payCycleService = app(PayCycleService::class);
        $monthlyBase = (float) ($user->monthly_salary ?? $user->monthly_rate ?? 0);
        if ($lite) {
            $scheduleRates = [
                'working_days_per_week' => 0,
                'working_days_per_month' => 0,
                'working_days_in_calendar_month' => 0,
                'working_hours_per_day' => 0,
                'rate_divisor_source' => null,
                'derived_daily_rate' => null,
                'derived_hourly_rate' => null,
            ];
        } else {
            $scheduleRates = app(ScheduleRateService::class)->describeForUser(
                $user,
                $monthlyBase > 0 ? $monthlyBase : null
            );
        }
        Log::info('Auth userResponse timing step', [
            'endpoint' => 'Auth.userResponse',
            'user_id' => $user->id,
            'step' => 'resolve_permissions_and_schedule_rates',
            'time_ms' => round((microtime(true) - $computedStart) * 1000),
            'lite' => $lite,
        ]);

        $effectiveCompany = $user->companyHeadships->first()
            ?? $user->company
            ?? $user->branch?->company
            ?? $user->departmentRelation?->branch?->company;

        $managementRole = ManagementRole::resolve($user);
        $branchNameForSelf = $managementRole === 'company_head'
            ? $user->branch?->name
            : ($user->branch?->name ?? $user->departmentRelation?->branch?->name);

        $payload = [
            'id' => $user->id,
            'user_id' => $user->id,
            'employee_id' => $user->isRosterEligible() ? $user->id : null,
            'employee_code' => $user->employee_code,
            'employee_name' => $user->isRosterEligible() ? $user->name : null,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'gender' => $user->gender,
            'civil_status' => $user->civil_status,
            'nationality' => $user->nationality,
            'home_address' => $user->home_address,
            'role' => $user->role,
            /** Aligned with {@see User::ROSTER_ELIGIBLE_ROLES} for SPA filters (assign pools, profile, etc.). */
            'is_roster_staff' => $user->isRosterEligible(),
            'is_hr_admin' => $user->isAdmin(),
            'hr_role' => $hr->value,
            'hr_role_label' => $hr->badgeLabel(),
            'hr_roles' => array_map(fn (HrRole $r) => $r->value, $hrRolesList),
            'hr_roles_labels' => array_map(fn (HrRole $r) => $r->badgeLabel(), $hrRolesList),
            'can_file_leave_for_others' => $hrResolver->canFileLeaveForOthers($user),
            /** True when user is company head, branch manager, or department head in org data (SPA routing; not the same as badge-only hr_role). */
            'is_assigned_organization_head' => $hrResolver->isAssignedOrganizationHead($user),
            'is_super_admin' => (bool) $user->is_super_admin,
            'permissions' => $rbac->getPermissionsForUser($user)->values()->all(),
            'can_access_hr_panel' => $hr->canAccessHrPanel(),
            /** Org hints for attendance filters (department / branch / company heads). */
            'attendance_scope' => app(DataScopeService::class)->getAttendanceScopeMeta($user),
            'department' => $user->department,
            'department_id' => $user->department_id,
            'company_id' => $user->company_id ?? $effectiveCompany?->id,
            'company_name' => $effectiveCompany?->name,
            'company_logo_url' => $this->publicMediaUrl($effectiveCompany?->logo),
            'branch_id' => $user->branch_id,
            'branch_name' => $branchNameForSelf,
            'managed_branch_id' => $user->managedBranch?->id,
            'managed_branch_name' => $user->managedBranch?->name,
            'management_role' => $managementRole,
            'position' => $user->position,
            'branch_office_location' => $user->branch_office_location,
            'employment_type' => $user->employment_type,
            'employment_status' => $user->employment_status,
            'employment_status_effective_date' => $user->employment_status_effective_date?->toDateString(),
            'hire_date' => $user->hire_date?->toDateString(),
            'contract_start_date' => $user->contract_start_date?->toDateString(),
            'contract_end_date' => $user->contract_end_date?->toDateString(),
            'supervisor_id' => $user->supervisor_id,
            'supervisor_name' => $user->supervisor?->name,
            'pay_cycle_id' => $user->pay_cycle_id,
            'pay_cycle_preview' => $lite ? null : $payCycleService->previewForUser($user),
            'pay_cycle_inherited_from_company' => $lite ? false : $payCycleService->isPayCycleInheritedFromCompany($user),
            'working_schedule_id' => $user->working_schedule_id,
            'pending_working_schedule_id' => $user->pending_working_schedule_id,
            'pending_schedule_effective_from' => $user->pending_schedule_effective_from?->toDateString(),
            'pending_working_schedule_name' => $user->pendingWorkingSchedule?->name,
            'pending_working_schedule_time' => $user->pendingWorkingSchedule
                ? ($user->pendingWorkingSchedule->time_in.' – '.$user->pendingWorkingSchedule->time_out)
                : null,
            'working_schedule_name' => $user->workingSchedule?->name,
            'working_schedule_time' => $user->workingSchedule
                ? ($user->workingSchedule->time_in.' – '.$user->workingSchedule->time_out)
                : (self::scheduleTimeFromJson($user->schedule)),
            'working_schedule_rest_days' => $user->workingSchedule?->rest_days,
            'working_schedule_rest_days_label' => LeaveScheduleSupport::formatRestDaysLabels($user->workingSchedule?->rest_days ?? []),
            'working_schedule_break_start' => $user->workingSchedule?->break_start,
            'working_schedule_break_end' => $user->workingSchedule?->break_end,
            'working_schedule_grace_minutes' => $user->workingSchedule?->grace_period_minutes,
            /** Per-day JSON when custom schedule; same shape as admin employee schedule editor. */
            'schedule_per_day' => $user->schedule,
            'schedule_assigned' => self::userHasSchedule($user),
            'is_active' => (bool) $user->is_active,
            'active_status' => $user->employment_active_status,
            'is_deactivated' => $user->isAccountDeactivated(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            // Self-service profile: base pay (aligned with payroll fields on users)
            'monthly_salary' => $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'daily_rate' => $user->daily_rate !== null ? (string) $user->daily_rate : null,
            'monthly_rate' => $user->monthly_rate !== null ? (string) $user->monthly_rate : null,
            'hourly_rate' => $user->hourly_rate !== null ? (string) $user->hourly_rate : null,
            'salary_effectivity_date' => $user->salary_effectivity_date?->toDateString(),
            'schedule_working_days_per_week' => $scheduleRates['working_days_per_week'],
            'schedule_working_days_per_month' => $scheduleRates['working_days_per_month'],
            'schedule_working_days_in_calendar_month' => $scheduleRates['working_days_in_calendar_month'],
            'schedule_working_hours_per_day' => $scheduleRates['working_hours_per_day'],
            'schedule_rate_divisor_source' => $scheduleRates['rate_divisor_source'],
            'schedule_derived_daily_rate' => $scheduleRates['derived_daily_rate'],
            'schedule_derived_hourly_rate' => $scheduleRates['derived_hourly_rate'],
            'signature_image' => $user->signature_image_url,
            'signature_signed_at' => $user->signature_signed_at?->toIso8601String(),
        ];

        // So frontend knows if employee has an issued QR token
        $payload['has_qr'] = ! empty($user->qr_token);

        // Face registration status for Admin Employees table
        $payload['has_face'] = self::userHasFace($user);

        $leaveCreditsStart = microtime(true);
        if ($includeLeaveCredits) {
            $leaveCreditsPayload = app(LeaveCreditService::class)->buildLeaveCreditsApiPayload($user);
            $payload['leave_credits'] = $leaveCreditsPayload['remaining'];
            $payload['leave_credits_annual_allocation'] = $leaveCreditsPayload['annual_allocation'];
            $payload['leave_credits_reset_date'] = $leaveCreditsPayload['reset_date'];
            $payload['leave_credits_last_recharged_display'] = $leaveCreditsPayload['last_recharged_display'];
            $payload['leave_credits_recharge_policy'] = $leaveCreditsPayload['recharge_policy'];
            $payload['leave_credits_eligible_for_paid_pool'] = $leaveCreditsPayload['eligible_for_paid_leave_pool'];
            $payload['leave_credits_probationary'] = $leaveCreditsPayload['probationary'];
            $payload['leave_credits_has_one_year_service'] = $leaveCreditsPayload['has_one_year_of_service'];
            $payload['leave_credits_display'] = $leaveCreditsPayload['display'];
            $payload['leave_credits_status_summary'] = $leaveCreditsPayload['status_summary'] ?? null;
            $payload['leave_credits_unpaid_notice'] = $leaveCreditsPayload['unpaid_leave_notice'] ?? null;
            $payload['leave_credits_warning'] = $leaveCreditsPayload['warning'];
            $payload['leave_credits_effective_available'] = $leaveCreditsPayload['effective_available'];
            $payload['leave_credits_pending_reserved_days'] = $leaveCreditsPayload['pending_reserved_days'];
            $payload['leave_credits_is_regular_employment'] = $leaveCreditsPayload['is_regular_employment'] ?? null;
            $payload['leave_credits_service_anchor_date'] = $leaveCreditsPayload['service_anchor_date'] ?? null;
        } else {
            $payload['leave_credits'] = null;
            $payload['leave_credits_annual_allocation'] = null;
            $payload['leave_credits_reset_date'] = null;
            $payload['leave_credits_last_recharged_display'] = null;
            $payload['leave_credits_recharge_policy'] = null;
            $payload['leave_credits_eligible_for_paid_pool'] = null;
            $payload['leave_credits_probationary'] = null;
            $payload['leave_credits_has_one_year_service'] = null;
            $payload['leave_credits_display'] = null;
            $payload['leave_credits_status_summary'] = null;
            $payload['leave_credits_unpaid_notice'] = null;
            $payload['leave_credits_warning'] = null;
            $payload['leave_credits_effective_available'] = null;
            $payload['leave_credits_pending_reserved_days'] = null;
            $payload['leave_credits_is_regular_employment'] = null;
            $payload['leave_credits_service_anchor_date'] = null;
        }
        Log::info('Auth userResponse timing step', [
            'endpoint' => 'Auth.userResponse',
            'user_id' => $user->id,
            'step' => 'build_leave_credits_payload',
            'time_ms' => round((microtime(true) - $leaveCreditsStart) * 1000),
            'included' => $includeLeaveCredits,
        ]);

        $payload['profile_image'] = $user->profile_image_url;
        $payload['profile_image_url'] = $user->profile_image_url;
        $payload['profile_picture_url'] = $user->profile_image_url;
        $payload['avatar_url'] = $user->profile_image_url;
        $payload['photo_url'] = $user->profile_image_url;

        // Basic account metadata for profile UI
        $payload['email_verified_at'] = $user->email_verified_at
            ? $user->email_verified_at->toIso8601String()
            : null;
        $payload['last_login_at'] = $user->last_login_at
            ? $user->last_login_at->toIso8601String()
            : null;
        $payload['updated_at'] = $user->updated_at
            ? $user->updated_at->toIso8601String()
            : null;

        $statusEnum = EmploymentStatus::tryFrom((string) ($user->employment_status ?? ''));
        $payload['employment_status_label'] = $statusEnum?->label() ?? ($user->employment_status ? ucfirst(str_replace('_', ' ', (string) $user->employment_status)) : null);

        if (! $lite && $user->hire_date && $statusEnum === EmploymentStatus::Probationary) {
            $es = app(EmployeeStatusService::class);
            $payload['probation_milestones'] = $es->getMilestoneDates($user);
            $payload['probation_review_phase'] = $es->getProbationReviewPhase($user);
        } else {
            $payload['probation_milestones'] = null;
            $payload['probation_review_phase'] = null;
        }

        if (! $lite && $user->contract_end_date && $statusEnum === EmploymentStatus::Contractual) {
            $end = \Carbon\Carbon::parse($user->contract_end_date)->startOfDay();
            $today = \Carbon\Carbon::now(config('attendance.timezone', 'Asia/Manila'))->startOfDay();
            $days = $today->diffInDays($end, false);
            $payload['contract_days_until_end'] = $days;
            $payload['contract_expiring_within_30_days'] = $days >= 0 && $days <= 30;
        } else {
            $payload['contract_days_until_end'] = null;
            $payload['contract_expiring_within_30_days'] = false;
        }

        Log::info('Auth userResponse timing end', [
            'endpoint' => 'Auth.userResponse',
            'user_id' => $user->id,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return $payload;
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = ltrim(trim($path), '/');
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        $segments = explode('/', $normalized);
        $encoded = array_map(static fn (string $s) => rawurlencode($s), $segments);

        return '/api/media/public/'.implode('/', $encoded);
    }

    private static function userHasSchedule(User $user): bool
    {
        if ($user->working_schedule_id !== null) {
            return true;
        }
        $schedule = $user->schedule;
        if (! is_array($schedule) || empty($schedule)) {
            return false;
        }
        foreach ($schedule as $dayConfig) {
            if (is_array($dayConfig) && trim((string) ($dayConfig['in'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function scheduleTimeFromJson(?array $schedule): ?string
    {
        if (! is_array($schedule) || empty($schedule)) {
            return null;
        }
        $dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        foreach ($dayKeys as $day) {
            $dayConfig = $schedule[$day] ?? null;
            if (is_array($dayConfig) && ! empty($dayConfig['in']) && ! empty($dayConfig['out'])) {
                return $dayConfig['in'].' – '.$dayConfig['out'];
            }
        }

        return null;
    }

    private static function userHasFace(User $user): bool
    {
        return $user->hasRegisteredFace();
    }
}
