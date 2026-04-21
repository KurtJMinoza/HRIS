<?php

namespace App\Http\Controllers;

use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeGovernmentId;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\ESignatureService;
use App\Services\HrRoleResolver;
use App\Services\LeaveCreditService;
use App\Services\PayCycleService;
use App\Services\PayrollCalculatorService;
use App\Services\RbacService;
use App\Services\ScheduleRateService;
use App\Support\EmployeeProfileCache;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmployeeProfileController extends Controller
{
    public function __construct(
        private readonly ESignatureService $eSignatureService,
        private readonly RbacService $rbacService,
        private readonly PayrollCalculatorService $payrollCalculator,
        private readonly PayCycleService $payCycleService,
        private readonly ScheduleRateService $scheduleRateService,
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    private function denyProfileEditResponse(): JsonResponse
    {
        return response()->json(['message' => 'Profile details can only be edited by HR.'], 403);
    }

    /**
     * Same JSON shape as {@see show()} for a target employee, scoped to the viewer (HR panel).
     */
    public function showForViewer(Request $request, int $id): JsonResponse
    {
        $start = microtime(true);
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! $this->rbacService->can($actor, 'profile.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $targetId = $id > 0 ? $id : (int) $actor->id;
        $target = User::query()->select($this->baseProfileSelectColumns())->find($targetId);
        if (! $target && $actor->id) {
            // Defensive fallback for self-profile requests with invalid/missing route IDs.
            $target = User::query()->select($this->baseProfileSelectColumns())->find((int) $actor->id);
        }
        if (! $target) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ((int) $target->id !== (int) $actor->id) {
            try {
                $this->dataScopeService->ensureEmployeeAccessible($actor, $target);
            } catch (HttpResponseException $e) {
                return $e->getResponse();
            }
        }

        $flags = [
            'lite' => $request->boolean('lite', true),
            'include_government_ids' => $request->boolean('include_government_ids', false),
            'include_emergency_contacts' => $request->boolean('include_emergency_contacts', false),
            'include_benefits' => $request->boolean('include_benefits', false),
            'include_leave_credits' => $request->boolean('include_leave_credits', false),
            'include_leave_credits_history' => $request->boolean('include_leave_credits_history', false),
            'include_compensation_summary' => $request->boolean('include_compensation_summary', false),
            'include_pay_cycle_preview' => $request->boolean('include_pay_cycle_preview', false),
        ];

        // Single cache layer inside profilePayload (base_payload + optional sections). Avoid nested
        // final_payload + base_payload writes — reduces Windows file-cache I/O and lock contention.
        $payloadBuildStart = microtime(true);
        $payload = $this->profilePayload($target, $flags, [
            'controller_start' => $start,
            'endpoint' => 'EmployeeProfile.showForViewer',
        ]);
        Log::info('Endpoint timing', [
            'endpoint' => 'EmployeeProfile.showForViewer',
            'target_user_id' => $target->id,
            'stage' => 'profile_payload_build',
            'time_ms' => round((microtime(true) - $payloadBuildStart) * 1000),
        ]);
        Log::info('Endpoint timing', [
            'endpoint' => 'EmployeeProfile.showForViewer',
            'target_user_id' => $target->id,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request): JsonResponse
    {
        $start = microtime(true);
        $authUser = $request->user();
        $user = $authUser
            ? User::query()->select($this->baseProfileSelectColumns())->find($authUser->id)
            : null;
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->rbacService->can($user, 'profile.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $flags = [
            'include_government_ids' => $request->boolean('include_government_ids', false),
            'include_emergency_contacts' => $request->boolean('include_emergency_contacts', false),
            'include_benefits' => $request->boolean('include_benefits', false),
            'include_leave_credits' => $request->boolean('include_leave_credits', false),
            'include_leave_credits_history' => $request->boolean('include_leave_credits_history', false),
            'include_compensation_summary' => $request->boolean('include_compensation_summary', false),
            'include_pay_cycle_preview' => $request->boolean('include_pay_cycle_preview', false),
        ];
        $payloadBuildStart = microtime(true);
        $payload = $this->profilePayload($user, $flags, [
            'controller_start' => $start,
            'endpoint' => 'EmployeeProfile.show',
        ]);
        Log::info('Endpoint timing', [
            'endpoint' => 'EmployeeProfile.show',
            'target_user_id' => $user->id,
            'stage' => 'profile_payload_build',
            'time_ms' => round((microtime(true) - $payloadBuildStart) * 1000),
        ]);
        Log::info('Endpoint timing', [
            'endpoint' => 'EmployeeProfile.show',
            'target_user_id' => $user->id,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ]);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    /**
     * Export the authenticated employee profile as a single-row CSV.
     * Mirrors Admin export columns for Personal, Employment, Salary, and pay components.
     */
    public function exportMyCsv(Request $request)
    {
        $viewer = $request->user();
        if (! $viewer || ! $viewer->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->rbacService->can($viewer, 'profile.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = User::query()
            ->whereKey($viewer->id)
            ->select([
                'id',
                'employee_code',
                'name',
                'first_name',
                'middle_name',
                'last_name',
                'date_of_birth',
                'gender',
                'civil_status',
                'nationality',
                'email',
                'phone_number',
                'home_address',
                'street_address',
                'barangay',
                'city',
                'province',
                'postal_code',
                'employment_type',
                'employment_status',
                'employment_status_effective_date',
                'hire_date',
                'contract_start_date',
                'contract_end_date',
                'position',
                'department',
                'department_id',
                'company_id',
                'branch_id',
                'supervisor_id',
                'working_schedule_id',
                'pay_cycle_id',
                'monthly_salary',
                'monthly_rate',
                'daily_rate',
                'hourly_rate',
                'salary_effectivity_date',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->with([
                'company:id,name',
                'branch:id,name,company_id',
                'branch.company:id,name',
                'departmentRelation:id,name,branch_id',
                'departmentRelation.branch:id,name,company_id',
                'departmentRelation.branch.company:id,name',
                'supervisor:id,name',
                'workingSchedule:id,name,time_in,time_out,rest_days',
                'payCycle:id,name,code',
                'compensationComponents:id,user_id,pay_component_id,name,type,value,is_active',
                'compensationComponents.payComponent:id,name,code',
                'employeeDeductions:id,user_id,deduction_type_id,pay_component_id,amount,remaining_balance,is_active',
                'employeeDeductions.deductionType:id,name',
                'employeeDeductions.payComponent:id,name,code',
                'governmentIds:id,user_id,sss_number,philhealth_number,pagibig_number,tin_number',
                'taxInfo:id,user_id,tax_regime,withholding_method,dependents',
            ])
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $fileName = 'my_profile_export_'.now()->format('Ymd_His').'.csv';
        $header = $this->employeeExportCsvHeader();

        return response()->streamDownload(function () use ($user, $header) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            fputcsv($out, $this->employeeExportCsvRow($user));

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return array{user: array, government_ids: array, emergency_contacts: \Illuminate\Support\Collection, benefits: \Illuminate\Support\Collection}
     */
    private function profilePayload(User $user, array $flags = [], array $timingContext = []): array
    {
        $totalPayloadStart = microtime(true);
        $sectionStart = microtime(true);
        $lite = (bool) ($flags['lite'] ?? false);
        $includeGov = (bool) ($flags['include_government_ids'] ?? true);
        $includeEmergency = (bool) ($flags['include_emergency_contacts'] ?? true);
        $includeBenefits = (bool) ($flags['include_benefits'] ?? false);
        $includeLeaveCredits = (bool) ($flags['include_leave_credits'] ?? false);
        $includeLeaveCreditsHistory = (bool) ($flags['include_leave_credits_history'] ?? false);
        $includeCompSummary = (bool) ($flags['include_compensation_summary'] ?? false);
        $includePayCyclePreview = (bool) ($flags['include_pay_cycle_preview'] ?? false);

        $cacheDescriptor = [
            'lite' => $lite,
            'include_government_ids' => $includeGov,
            'include_emergency_contacts' => $includeEmergency,
            'include_benefits' => $includeBenefits,
            'include_pay_cycle_preview' => $includePayCyclePreview,
        ];
        $profileTtl = now()->addMinutes((int) config('cache.profile_ttl_minutes', 5));
        $basePayloadStart = microtime(true);
        $payload = EmployeeProfileCache::remember(
            (int) $user->id,
            'base_payload',
            $cacheDescriptor,
            $profileTtl,
            function () use ($user, $lite, $includeGov, $includeEmergency, $includeBenefits, $includePayCyclePreview) {
                // Keep initial profile request lightweight: only hydrate org/schedule relations outside lite mode.
                if (! $lite || $includePayCyclePreview) {
                    $user->loadMissing([
                        'company:id,name,default_pay_cycle_id',
                        'branch:id,name,company_id',
                        'departmentRelation:id,name,branch_id',
                        'workingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
                        'supervisor:id,name',
                    ]);
                }
                if ($includeGov) {
                    $user->loadMissing('governmentIds:user_id,sss_number,philhealth_number,pagibig_number,tin_number');
                }
                if ($includeEmergency) {
                    $user->loadMissing('emergencyContacts:user_id,id,full_name,relationship,phone_number,address,is_primary');
                }
                if ($includeBenefits) {
                    $user->loadMissing([
                        'employeeBenefits:id,user_id,benefit_catalog_id,effective_date,status,metadata',
                        'employeeBenefits.benefitCatalog:id,type,name,description,provider',
                    ]);
                }

                $benefits = $includeBenefits
                    ? $user->employeeBenefits
                        ->map(function ($assignment) {
                            $catalog = $assignment->benefitCatalog;

                            return [
                                'id' => $assignment->id,
                                'type' => $catalog?->type,
                                'name' => $catalog?->name,
                                'description' => $catalog?->description,
                                'provider' => $catalog?->provider,
                                'effective_date' => $assignment->effective_date?->toDateString(),
                                'status' => $assignment->status,
                                'metadata' => $assignment->metadata ?? [],
                            ];
                        })
                        ->values()
                    : collect();

                return [
                    'user' => $lite ? $this->liteProfileUserPayload($user) : $this->minimalProfileUserPayload($user),
                    'permissions' => [
                        'can_edit_own_profile' => $this->canEditOwnProfile($user),
                    ],
                    'pay_cycle_preview' => $includePayCyclePreview ? $this->payCycleService->previewForUser($user) : null,
                    'compensation_summary' => null,
                    'government_ids' => [
                        'sss_number' => $includeGov ? $user->governmentIds?->sss_number : null,
                        'philhealth_number' => $includeGov ? $user->governmentIds?->philhealth_number : null,
                        'pagibig_number' => $includeGov ? $user->governmentIds?->pagibig_number : null,
                        'tin_number' => $includeGov ? $user->governmentIds?->tin_number : null,
                    ],
                    'emergency_contacts' => $includeEmergency
                        ? $user->emergencyContacts
                            ->sortByDesc('is_primary')
                            ->values()
                            ->map(fn (EmployeeEmergencyContact $c) => [
                                'id' => $c->id,
                                'full_name' => $c->full_name,
                                'relationship' => $c->relationship,
                                'phone_number' => $c->phone_number,
                                'address' => $c->address,
                                'is_primary' => (bool) $c->is_primary,
                            ])
                        : collect(),
                    'benefits' => $benefits,
                    'leave_credits' => null,
                ];
            }
        );
        Log::info('Endpoint timing', [
            'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
            'target_user_id' => $user->id,
            'stage' => 'relations_load',
            'time_ms' => round((microtime(true) - $sectionStart) * 1000),
            'base_payload_cache_ms' => round((microtime(true) - $basePayloadStart) * 1000),
            'cache_descriptor' => $cacheDescriptor,
        ]);

        $sectionStart = microtime(true);
        Log::info('Endpoint timing', [
            'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
            'target_user_id' => $user->id,
            'stage' => 'base_payload_build',
            'time_ms' => round((microtime(true) - $sectionStart) * 1000),
        ]);

        if ($includeCompSummary) {
            $sectionStart = microtime(true);
            Log::info('Endpoint timing', [
                'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
                'target_user_id' => $user->id,
                'stage' => 'compensation_summary_start',
            ]);
            $payload['compensation_summary'] = EmployeeProfileCache::remember(
                (int) $user->id,
                'compensation_summary',
                ['as_of_date' => now()->toDateString()],
                $profileTtl,
                fn () => $this->payrollCalculator->buildEmployeeCompensationSummary($user, [
                    'as_of_date' => now()->toDateString(),
                    'proration_factor' => 1,
                    'include_deduction_schedule_catalog' => false,
                    'cache' => true,
                ])
            );
            Log::info('Endpoint timing', [
                'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
                'target_user_id' => $user->id,
                'stage' => 'compensation_summary',
                'time_ms' => round((microtime(true) - $sectionStart) * 1000),
            ]);
        }

        if ($includeLeaveCredits) {
            $sectionStart = microtime(true);
            $leaveCreditService = app(LeaveCreditService::class);
            Log::info('Endpoint timing', [
                'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
                'target_user_id' => $user->id,
                'stage' => 'leave_credits_summary_start',
                'include_history' => $includeLeaveCreditsHistory,
            ]);
            $leaveCreditsPayload = $leaveCreditService->getSummary($user, [
                'include_pending_reserved_days' => false,
            ]);
            if ($includeLeaveCreditsHistory) {
                $leaveCreditsPayload['history'] = $leaveCreditService->historyForUser((int) $user->id, 25);
            }
            $payload['leave_credits'] = $leaveCreditsPayload;
            Log::info('Endpoint timing', [
                'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
                'target_user_id' => $user->id,
                'stage' => 'leave_credits',
                'time_ms' => round((microtime(true) - $sectionStart) * 1000),
                'include_history' => $includeLeaveCreditsHistory,
            ]);
        }

        // Serialize-safe normalization: prevent hidden lazy-loads during JSON conversion.
        foreach (['emergency_contacts', 'benefits'] as $collectionKey) {
            if (($payload[$collectionKey] ?? null) instanceof Collection) {
                $payload[$collectionKey] = $payload[$collectionKey]->values()->all();
            }
        }
        if (($payload['leave_credits']['history'] ?? null) instanceof Collection) {
            $payload['leave_credits']['history'] = $payload['leave_credits']['history']->values()->all();
        }

        if (! empty($timingContext['controller_start'])) {
            Log::info('Endpoint timing', [
                'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
                'target_user_id' => $user->id,
                'stage' => 'total_payload',
                'time_ms' => round((microtime(true) - (float) $timingContext['controller_start']) * 1000),
            ]);
        }
        Log::info('Endpoint timing', [
            'endpoint' => $timingContext['endpoint'] ?? 'EmployeeProfile.profilePayload',
            'target_user_id' => $user->id,
            'stage' => 'total_payload_build_ms',
            'time_ms' => round((microtime(true) - $totalPayloadStart) * 1000),
        ]);

        return $payload;
    }

    private function minimalProfileUserPayload(User $user): array
    {
        $hr = $this->hrRoleResolver->resolve($user);
        $ws = $user->workingSchedule;
        $monthlyBase = (float) ($user->monthly_salary ?? $user->monthly_rate ?? 0);
        $scheduleRates = $this->scheduleRateService->describeForUser(
            $user,
            $monthlyBase > 0 ? $monthlyBase : null
        );

        return [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
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
            'full_address' => $user->full_address ?? $user->home_address,
            'street_address' => $user->street_address,
            'barangay' => $user->barangay,
            'city' => $user->city,
            'province' => $user->province,
            'postal_code' => $user->postal_code,
            'role' => $user->role,
            'hr_role' => $hr->value,
            'can_access_hr_panel' => $hr->canAccessHrPanel(),
            'department' => $user->departmentRelation?->name ?? $user->department,
            'department_id' => $user->department_id,
            'company_id' => $user->company_id,
            'company_name' => $user->company?->name,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
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
            'working_schedule_id' => $user->working_schedule_id,
            'pending_working_schedule_id' => $user->pending_working_schedule_id,
            'pending_schedule_effective_from' => $user->pending_schedule_effective_from?->toDateString(),
            'working_schedule_name' => $ws?->name,
            'working_schedule_time' => $ws ? ($ws->time_in.' – '.$ws->time_out) : null,
            'working_schedule_rest_days' => $ws?->rest_days,
            'working_schedule_break_start' => $ws?->break_start,
            'working_schedule_break_end' => $ws?->break_end,
            'working_schedule_grace_minutes' => $ws?->grace_period_minutes,
            // Keep self-service Salary tab aligned with Auth payload and payroll ScheduleRateService.
            'schedule_working_days_per_week' => $scheduleRates['working_days_per_week'] ?? 0,
            'schedule_working_days_per_month' => $scheduleRates['working_days_per_month'] ?? 0,
            'schedule_working_days_in_calendar_month' => $scheduleRates['working_days_in_calendar_month'] ?? 0,
            'schedule_working_hours_per_day' => $scheduleRates['working_hours_per_day'] ?? 0,
            'schedule_rate_divisor_source' => $scheduleRates['rate_divisor_source'] ?? null,
            'schedule_derived_daily_rate' => $scheduleRates['derived_daily_rate'] ?? null,
            'schedule_derived_hourly_rate' => $scheduleRates['derived_hourly_rate'] ?? null,
            'monthly_salary' => $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'daily_rate' => $user->daily_rate !== null ? (string) $user->daily_rate : null,
            'monthly_rate' => $user->monthly_rate !== null ? (string) $user->monthly_rate : null,
            'hourly_rate' => $user->hourly_rate !== null ? (string) $user->hourly_rate : null,
            'salary_effectivity_date' => $user->salary_effectivity_date?->toDateString(),
            'is_active' => (bool) $user->is_active,
            'profile_image' => $user->profile_image_url,
            'profile_image_url' => $user->profile_image_url,
            'signature_image' => $user->signature_image_url,
            'signature_signed_at' => $user->signature_signed_at?->toIso8601String(),
            // Kept for frontend org-scope UI compatibility.
            'management_role' => null,
        ];
    }

    private function liteProfileUserPayload(User $user): array
    {
        $hr = $this->hrRoleResolver->resolve($user);

        return [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
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
            'full_address' => $user->full_address ?? $user->home_address,
            'street_address' => $user->street_address,
            'barangay' => $user->barangay,
            'city' => $user->city,
            'province' => $user->province,
            'postal_code' => $user->postal_code,
            'role' => $user->role,
            'hr_role' => $hr->value,
            'can_access_hr_panel' => $hr->canAccessHrPanel(),
            'department' => $user->department,
            'department_id' => $user->department_id,
            'company_id' => $user->company_id,
            'branch_id' => $user->branch_id,
            'position' => $user->position,
            'branch_office_location' => $user->branch_office_location,
            'employment_type' => $user->employment_type,
            'employment_status' => $user->employment_status,
            'employment_status_effective_date' => $user->employment_status_effective_date?->toDateString(),
            'hire_date' => $user->hire_date?->toDateString(),
            'contract_start_date' => $user->contract_start_date?->toDateString(),
            'contract_end_date' => $user->contract_end_date?->toDateString(),
            'supervisor_id' => $user->supervisor_id,
            'pay_cycle_id' => $user->pay_cycle_id,
            'working_schedule_id' => $user->working_schedule_id,
            'pending_working_schedule_id' => $user->pending_working_schedule_id,
            'pending_schedule_effective_from' => $user->pending_schedule_effective_from?->toDateString(),
            'monthly_salary' => $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'daily_rate' => $user->daily_rate !== null ? (string) $user->daily_rate : null,
            'monthly_rate' => $user->monthly_rate !== null ? (string) $user->monthly_rate : null,
            'hourly_rate' => $user->hourly_rate !== null ? (string) $user->hourly_rate : null,
            'salary_effectivity_date' => $user->salary_effectivity_date?->toDateString(),
            'is_active' => (bool) $user->is_active,
            'profile_image' => $user->profile_image_url,
            'profile_image_url' => $user->profile_image_url,
            'signature_image' => $user->signature_image_url,
            'signature_signed_at' => $user->signature_signed_at?->toIso8601String(),
            'management_role' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function baseProfileSelectColumns(): array
    {
        return [
            'id',
            'employee_code',
            'name',
            'first_name',
            'middle_name',
            'last_name',
            'username',
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
            'role',
            'department',
            'department_id',
            'company_id',
            'branch_id',
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
            'working_schedule_id',
            'pending_working_schedule_id',
            'pending_schedule_effective_from',
            'monthly_salary',
            'daily_rate',
            'monthly_rate',
            'hourly_rate',
            'salary_effectivity_date',
            'is_active',
            'profile_image',
            'signature_image',
            'signature_signed_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function employeeExportCsvHeader(): array
    {
        return [
            'Employee ID',
            'Full Name',
            'First Name',
            'Middle Name',
            'Last Name',
            'Date of Birth',
            'Gender',
            'Marital Status',
            'Nationality',
            'Email',
            'Username',
            'Phone Number',
            'Home Address',
            'Street',
            'Barangay',
            'City',
            'Province',
            'Postal Code',
            'Employment Type',
            'Employment Status',
            'Employment Status Effective Date',
            'Date Hired',
            'Contract Start Date',
            'Contract End Date',
            'Position',
            'Department',
            'Branch',
            'Company',
            'Supervisor',
            'Working Schedule',
            'Working Time In',
            'Working Time Out',
            'Rest Days',
            'Pay Schedule',
            'Basic Salary',
            'Monthly Rate',
            'Daily Rate',
            'Hourly Rate',
            'Salary Effectivity Date',
            'Rice Allowance',
            'Transportation Allowance',
            'Other Pay Components (Active)',
            'Allowances (Active)',
            'Compensation Deductions (Active)',
            'Automated Deductions/Loans (Active)',
            'SSS Number',
            'PhilHealth Number',
            'Pag-IBIG Number',
            'TIN Number',
            'Tax Regime',
            'Withholding Method',
            'Dependents',
            'Active Account',
            'Created Date',
            'Updated Date',
        ];
    }

    /**
     * @return list<string>
     */
    private function employeeExportCsvRow(User $user): array
    {
        $departmentName = $user->departmentRelation?->name ?? $user->department;
        $branchName = $user->branch?->name ?? $user->departmentRelation?->branch?->name;
        $companyName = $user->company?->name ?? $user->branch?->company?->name ?? $user->departmentRelation?->branch?->company?->name;
        $restDays = is_array($user->workingSchedule?->rest_days)
            ? implode('|', array_map(fn ($d) => (string) $d, $user->workingSchedule->rest_days))
            : '';

        $allowances = $user->compensationComponents
            ->filter(fn ($c) => $c->is_active && $c->type === 'earning')
            ->map(function ($c) {
                $name = trim((string) ($c->name ?: $c->payComponent?->name ?: $c->payComponent?->code ?: 'Allowance'));
                $amount = $this->csvDecimal($c->value);

                return $amount !== '' ? "{$name}:{$amount}" : $name;
            })->values()->all();

        $activePayComponents = $user->compensationComponents
            ->filter(fn ($c) => $c->is_active)
            ->map(function ($c) {
                $name = trim((string) ($c->name ?: $c->payComponent?->name ?: $c->payComponent?->code ?: 'Component'));
                $amount = $this->csvDecimal($c->value);

                return [
                    'name' => $name,
                    'amount' => $amount,
                    'label' => $amount !== '' ? "{$name}:{$amount}" : $name,
                ];
            })
            ->values();

        $riceAllowance = $activePayComponents
            ->first(fn ($c) => $this->isPayComponentNamed($c['name'], ['rice allowance', 'rice subsidy']));
        $transportAllowance = $activePayComponents
            ->first(fn ($c) => $this->isPayComponentNamed($c['name'], ['transportation allowance', 'transport allowance', 'travel allowance']));
        $otherPayComponents = $activePayComponents
            ->filter(function ($c) use ($riceAllowance, $transportAllowance) {
                if ($riceAllowance && $c['name'] === $riceAllowance['name']) {
                    return false;
                }
                if ($transportAllowance && $c['name'] === $transportAllowance['name']) {
                    return false;
                }

                return true;
            })
            ->pluck('label')
            ->all();

        $compensationDeductions = $user->compensationComponents
            ->filter(fn ($c) => $c->is_active && $c->type === 'deduction')
            ->map(function ($c) {
                $name = trim((string) ($c->name ?: $c->payComponent?->name ?: $c->payComponent?->code ?: 'Deduction'));
                $amount = $this->csvDecimal($c->value);

                return $amount !== '' ? "{$name}:{$amount}" : $name;
            })->values()->all();

        $automatedDeductions = $user->employeeDeductions
            ->filter(fn ($d) => (bool) $d->is_active)
            ->map(function ($d) {
                $name = trim((string) ($d->deductionType?->name ?: $d->payComponent?->name ?: 'Deduction/Loan'));
                $amount = $this->csvDecimal($d->amount);
                $remaining = $this->csvDecimal($d->remaining_balance);

                return $remaining !== ''
                    ? "{$name}:{$amount} (remaining {$remaining})"
                    : ($amount !== '' ? "{$name}:{$amount}" : $name);
            })->values()->all();

        return [
            (string) ($user->employee_code ?? ''),
            (string) ($user->name ?? ''),
            (string) ($user->first_name ?? ''),
            (string) ($user->middle_name ?? ''),
            (string) ($user->last_name ?? ''),
            $this->csvDate($user->date_of_birth),
            (string) ($user->gender ?? ''),
            (string) ($user->civil_status ?? ''),
            (string) ($user->nationality ?? ''),
            (string) ($user->email ?? ''),
            (string) ($user->username ?? ''),
            $this->csvPhone($user->phone_number),
            (string) ($user->home_address ?? ''),
            (string) ($user->street_address ?? ''),
            (string) ($user->barangay ?? ''),
            (string) ($user->city ?? ''),
            (string) ($user->province ?? ''),
            (string) ($user->postal_code ?? ''),
            (string) ($user->employment_type ?? ''),
            (string) ($user->employment_status ?? ''),
            $this->csvDate($user->employment_status_effective_date),
            $this->csvDate($user->hire_date),
            $this->csvDate($user->contract_start_date),
            $this->csvDate($user->contract_end_date),
            (string) ($user->position ?? ''),
            (string) ($departmentName ?? ''),
            (string) ($branchName ?? ''),
            (string) ($companyName ?? ''),
            (string) ($user->supervisor?->name ?? ''),
            (string) ($user->workingSchedule?->name ?? ''),
            (string) ($user->workingSchedule?->time_in ?? ''),
            (string) ($user->workingSchedule?->time_out ?? ''),
            $restDays,
            $this->csvPayScheduleLabel($user->payCycle?->code, $user->payCycle?->name),
            $this->csvDecimal($user->monthly_salary),
            $this->csvDecimal($user->monthly_rate),
            $this->csvDecimal($user->daily_rate),
            $this->csvDecimal($user->hourly_rate),
            $this->csvDate($user->salary_effectivity_date),
            $riceAllowance['amount'] ?? '',
            $transportAllowance['amount'] ?? '',
            implode(' | ', $otherPayComponents),
            implode(' | ', $allowances),
            implode(' | ', $compensationDeductions),
            implode(' | ', $automatedDeductions),
            (string) ($user->governmentIds?->sss_number ?? ''),
            (string) ($user->governmentIds?->philhealth_number ?? ''),
            (string) ($user->governmentIds?->pagibig_number ?? ''),
            (string) ($user->governmentIds?->tin_number ?? ''),
            (string) ($user->taxInfo?->tax_regime ?? ''),
            (string) ($user->taxInfo?->withholding_method ?? ''),
            $user->taxInfo?->dependents !== null ? (string) $user->taxInfo->dependents : '',
            $user->is_active ? '1' : '0',
            $this->csvDate($user->created_at),
            $this->csvDate($user->updated_at),
        ];
    }

    private function csvDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function csvDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function csvPhone(mixed $value): string
    {
        $phone = trim((string) ($value ?? ''));
        if ($phone === '') {
            return '';
        }

        return "'".$phone;
    }

    private function csvPayScheduleLabel(?string $cycleCode, ?string $cycleName): string
    {
        $code = Str::lower(trim((string) $cycleCode));
        $name = Str::lower(trim((string) $cycleName));

        if ($code === 'semi_monthly' || str_contains($name, 'semi') || str_contains($name, '15') || str_contains($name, '30')) {
            return 'Both';
        }
        if ($code === 'monthly' || str_contains($name, 'monthly')) {
            return '30th';
        }
        if ($code === 'bi_weekly' || $code === 'weekly' || $code === 'daily') {
            return 'Both';
        }

        return trim((string) ($cycleName ?? $cycleCode ?? ''));
    }

    /**
     * @param  list<string>  $aliases
     */
    private function isPayComponentNamed(string $name, array $aliases): bool
    {
        $normalized = Str::lower(trim($name));
        if ($normalized === '') {
            return false;
        }
        foreach ($aliases as $alias) {
            $target = Str::lower(trim($alias));
            if ($target !== '' && str_contains($normalized, $target)) {
                return true;
            }
        }

        return false;
    }

    public function updatePersonal(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->canEditOwnProfile($user)) {
            return $this->denyProfileEditResponse();
        }

        try {
            Log::info('EmployeeProfileController@updatePersonal incoming', [
                'user_id' => $user->id,
                'keys' => array_keys($request->all()),
            ]);

            $validated = $request->validate([
                'first_name' => ['sometimes', 'required', 'string', 'max:255'],
                'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'last_name' => ['sometimes', 'required', 'string', 'max:255'],
                'username' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._]+$/', 'unique:users,username,'.$user->id],
                'date_of_birth' => ['sometimes', 'nullable', 'date'],
                'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
                'civil_status' => ['sometimes', 'nullable', 'string', 'max:50'],
                'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
                'home_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);

            foreach (['first_name', 'last_name', 'gender', 'civil_status', 'nationality'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $raw = $validated[$field];
                    $user->{$field} = is_string($raw) ? (trim($raw) !== '' ? trim($raw) : null) : $raw;
                }
            }
            if (array_key_exists('middle_name', $validated)) {
                $raw = $validated['middle_name'];
                $user->middle_name = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }
            if (array_key_exists('username', $validated)) {
                $raw = $validated['username'];
                $user->username = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }
            if (array_key_exists('date_of_birth', $validated)) {
                $raw = $validated['date_of_birth'];
                $user->date_of_birth = is_string($raw) && trim($raw) !== '' ? $raw : null;
            }
            if (array_key_exists('home_address', $validated)) {
                $raw = $validated['home_address'];
                $user->home_address = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }

            if (array_key_exists('first_name', $validated) || array_key_exists('middle_name', $validated) || array_key_exists('last_name', $validated)) {
                $parts = [
                    trim((string) ($user->first_name ?? '')),
                    is_string($user->middle_name) ? trim($user->middle_name) : '',
                    trim((string) ($user->last_name ?? '')),
                ];
                $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
                $user->name = trim(implode(' ', $parts));
            }

            $user->save();

            Log::info('EmployeeProfileController@updatePersonal succeeded', [
                'user_id' => $user->id,
                'saved_fields' => array_keys($validated),
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return response()->json([
                'message' => 'Profile updated.',
                'user' => app(AuthController::class)->userResponse($user->fresh(), [
                    'lite' => true,
                    'include_leave_credits' => false,
                ]),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('EmployeeProfileController@updatePersonal failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
            report($e);

            return response()->json([
                'message' => 'Unable to save profile. Please try again.',
            ], 500);
        }
    }

    public function updateGovernmentIds(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->canEditOwnProfile($user)) {
            return $this->denyProfileEditResponse();
        }

        $validated = $request->validate([
            'sss_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{2}-\d{7}-\d{1}$/u'],
            'philhealth_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{2}-\d{9}-\d{1}$/u'],
            'pagibig_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4}-\d{4}-\d{4}$/u'],
            'tin_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{3}-\d{3}-\d{3}-\d{3}$/u'],
        ], [
            'tin_number.regex' => 'TIN must use format 000-000-000-000.',
            'sss_number.regex' => 'SSS Number must use format 00-0000000-0.',
            'philhealth_number.regex' => 'PhilHealth Number must use format 00-000000000-0.',
            'pagibig_number.regex' => 'Pag-IBIG Number must use format 0000-0000-0000.',
        ]);

        $record = EmployeeGovernmentId::firstOrNew(['user_id' => $user->id]);
        $record->fill([
            'sss_number' => $this->nullableTrim($validated['sss_number'] ?? null),
            'philhealth_number' => $this->nullableTrim($validated['philhealth_number'] ?? null),
            'pagibig_number' => $this->nullableTrim($validated['pagibig_number'] ?? null),
            'tin_number' => $this->nullableTrim($validated['tin_number'] ?? null),
        ]);
        $record->save();

        return response()->json([
            'message' => 'Government IDs updated.',
            'government_ids' => [
                'sss_number' => $record->sss_number,
                'philhealth_number' => $record->philhealth_number,
                'pagibig_number' => $record->pagibig_number,
                'tin_number' => $record->tin_number,
            ],
        ]);
    }

    public function replaceEmergencyContacts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->canEditOwnProfile($user)) {
            return $this->denyProfileEditResponse();
        }

        $validated = $request->validate([
            'contacts' => ['required', 'array', 'max:10'],
            'contacts.*.full_name' => ['required', 'string', 'max:255'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone_number' => ['required', 'string', 'max:50'],
            'contacts.*.address' => ['nullable', 'string', 'max:1000'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $contacts = $validated['contacts'];
        $primaryCount = collect($contacts)->filter(fn ($c) => ! empty($c['is_primary']))->count();
        if ($primaryCount > 1) {
            return response()->json([
                'message' => 'Only one emergency contact can be marked as primary.',
                'errors' => ['contacts' => ['Only one emergency contact can be marked as primary.']],
            ], 422);
        }

        $saved = DB::transaction(function () use ($user, $contacts) {
            EmployeeEmergencyContact::where('user_id', $user->id)->delete();

            $rows = [];
            foreach ($contacts as $c) {
                $rows[] = EmployeeEmergencyContact::create([
                    'user_id' => $user->id,
                    'full_name' => trim((string) $c['full_name']),
                    'relationship' => trim((string) $c['relationship']),
                    'phone_number' => trim((string) $c['phone_number']),
                    'address' => $this->nullableTrim($c['address'] ?? null),
                    'is_primary' => (bool) ($c['is_primary'] ?? false),
                ]);
            }

            return collect($rows)
                ->sortByDesc('is_primary')
                ->values()
                ->map(fn (EmployeeEmergencyContact $c) => [
                    'id' => $c->id,
                    'full_name' => $c->full_name,
                    'relationship' => $c->relationship,
                    'phone_number' => $c->phone_number,
                    'address' => $c->address,
                    'is_primary' => (bool) $c->is_primary,
                ]);
        });

        return response()->json([
            'message' => 'Emergency contacts updated.',
            'emergency_contacts' => $saved,
        ]);
    }

    public function saveSignature(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->canEditOwnProfile($user)) {
            return $this->denyProfileEditResponse();
        }

        $validated = $request->validate([
            'signature_data_url' => ['required', 'string'],
        ]);

        try {
            $updated = $this->eSignatureService->saveFromDataUrl($user, (string) $validated['signature_data_url']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Signature saved.',
            'user' => (new AuthController)->userResponse($updated),
        ]);
    }

    public function clearSignature(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessSelfServiceEmployeeProfile()) {
            return response()->json(['message' => 'Unauthorized. Employee access required.'], 403);
        }
        if (! $this->canEditOwnProfile($user)) {
            return $this->denyProfileEditResponse();
        }

        try {
            $updated = $this->eSignatureService->clear($user);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Signature removed.',
            'user' => (new AuthController)->userResponse($updated),
        ]);
    }

    private function nullableTrim($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v !== '' ? $v : null;
    }

    private function canEditOwnProfile(User $user): bool
    {
        $hrRole = strtolower(trim((string) ($user->hr_role ?? '')));

        return $user->isAdmin()
            || $hrRole === 'admin_hr'
            || $hrRole === 'admin'
            || $this->rbacService->can($user, 'profile.edit')
            || $this->rbacService->can($user, 'edit-own-profile');
    }
}
