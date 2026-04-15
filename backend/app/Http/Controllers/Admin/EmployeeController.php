<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessFaceRegistrationJob;
use App\Jobs\UpdateEmployeeProfileJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\DuplicateFaceRegistrationAttempt;
use App\Models\EmployeeTransferLog;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Models\UserPhoneChangeLog;
use App\Models\WorkingSchedule;
use App\Services\DataScopeService;
use App\Services\ESignatureService;
use App\Services\FaceRegistrationStatusService;
use App\Services\FaceVerificationService;
use App\Services\HrRoleResolver;
use App\Services\LeaveCreditService;
use App\Services\PayCycleService;
use App\Services\RbacService;
use App\Services\ScheduleRateService;
use App\Support\LeaveScheduleSupport;
use App\Support\ManagementRole;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly ESignatureService $eSignatureService,
        private readonly DataScopeService $dataScopeService,
        private readonly RbacService $rbacService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly PayCycleService $payCycleService,
        private readonly ScheduleRateService $scheduleRateService,
    ) {}

    /**
     * List staff roster (employees + Admin HR accounts) with simple pagination.
     *
     * Query params:
     * - page (int, default 1)
     * - per_page (int, default 10, max 100). Use 0 or "all" for schedule assignment (returns all, cap 2000).
     * - for_schedule_assignment (bool): if 1, returns all employees without pagination (for Assign Schedule modal).
     * - q (string, optional): search by name/email/phone/department/position.
     *
     * Heavy work (exports, bulk reports) belongs in queued jobs — this listing stays paginated + lite by default.
     */
    public function index(Request $request): JsonResponse
    {
        $start = microtime(true);
        $queryStart = microtime(true);
        Log::info('AdminEmployees index timing start', [
            'endpoint' => 'AdminEmployees.index',
            'user_id' => $request->user()?->id,
        ]);
        $forScheduleAssignment = $request->boolean('for_schedule_assignment', false);
        // Default to lightweight rows; full payload is opt-in via ?lite=0 for legacy screens.
        $lite = $request->boolean('lite', true);
        $perPageParam = $request->query('per_page', '10');
        $q = trim((string) $request->query('q', ''));
        $companyId = $request->filled('company_id') ? (int) $request->query('company_id') : null;
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $assignableToCompanyId = $request->filled('assignable_to_company_id') ? (int) $request->query('assignable_to_company_id') : null;
        $forDepartmentAssignment = $request->boolean('for_department_assignment', false);
        $assignmentBranchId = $request->filled('assignment_branch_id') ? (int) $request->query('assignment_branch_id') : null;
        $employeeScopeOptions = [];
        if ($forDepartmentAssignment && $assignmentBranchId !== null) {
            $employeeScopeOptions['branch_id_for_department_assignment'] = $assignmentBranchId;
        }

        $applySearch = function ($query) use ($q) {
            if ($q === '') {
                return $query;
            }
            $like = '%'.$q.'%';

            return $query->where(function ($sub) use ($like) {
                $sub->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('position', 'like', $like);
            });
        };

        $applyOrgFilters = function ($query) use ($companyId, $branchId, $departmentId, $assignableToCompanyId) {
            if ($companyId !== null) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)
                        ->orWhereHas('branch', fn ($b) => $b->where('company_id', $companyId))
                        ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)));
                });
                // Exclude employees who are Company Head of another company — they belong to that company only
                $query->where(function ($q) use ($companyId) {
                    $q->whereDoesntHave('companyHeadships')
                        ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $companyId));
                });
            }
            if ($branchId !== null) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                        ->orWhereHas('departmentRelation', fn ($d) => $d->where('branch_id', $branchId));
                });
            }
            if ($departmentId !== null) {
                $query->where('department_id', $departmentId);
            }
            if ($assignableToCompanyId !== null) {
                // Include: (1) employees in this company (and not head of another), or (2) unassigned employees
                $query->where(function ($q) use ($assignableToCompanyId) {
                    $q->where(function ($sub) use ($assignableToCompanyId) {
                        $sub->where('company_id', $assignableToCompanyId)
                            ->orWhereHas('branch', fn ($b) => $b->where('company_id', $assignableToCompanyId))
                            ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $assignableToCompanyId)));
                    })->where(function ($sub) use ($assignableToCompanyId) {
                        $sub->whereDoesntHave('companyHeadships')
                            ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $assignableToCompanyId));
                    });
                })->orWhere(function ($q) {
                    $q->whereNull('company_id')
                        ->whereNull('branch_id')
                        ->whereNull('department_id')
                        ->whereDoesntHave('companyHeadships');
                });
            }

            return $query;
        };

        $companyEagerLoads = [
            'companyHeadships:id,name,company_head_id',
            'company:id,name',
            'branch:id,name,company_id',
            'branch.company:id,name',
            'managedBranch:id,name,company_id',
            'managedDepartment',
            'departmentRelation:id,name,branch_id,department_head_id',
            'departmentRelation.branch:id,name,company_id',
            'departmentRelation.branch.company:id,name',
        ];
        $fullEagerLoads = array_merge($companyEagerLoads, [
            'workingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
            'pendingWorkingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
        ]);
        $liteSelectColumns = [
            'id',
            'employee_code',
            'name',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'phone_number',
            'role',
            'department',
            'department_id',
            'company_id',
            'branch_id',
            'position',
            'employment_status',
            'employment_status_effective_date',
            'hire_date',
            'schedule',
            'working_schedule_id',
            'monthly_salary',
            'daily_rate',
            'monthly_rate',
            'hourly_rate',
            'salary_effectivity_date',
            'is_active',
            'profile_image',
            'qr_token',
            'qr_token_generated_at',
            'face_status',
            'face_liveness_type',
            'face_registered_at',
            'created_at',
            'updated_at',
        ];
        $selectColumns = [
            'id',
            'employee_code',
            'name',
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
            'role',
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
            'schedule',
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
            'qr_token',
            'qr_token_generated_at',
            'face_status',
            'face_liveness_type',
            'face_registered_at',
            'face_descriptor',
            'face_embedding',
            'face_descriptor_samples',
            'face_image',
            'created_at',
            'updated_at',
        ];
        Log::info('AdminEmployees index timing step', [
            'endpoint' => 'AdminEmployees.index',
            'step' => 'request_parse_and_query_config',
            'time_ms' => round((microtime(true) - $start) * 1000),
            'lite' => $lite,
            'for_schedule_assignment' => $forScheduleAssignment,
            'q_present' => $q !== '',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
        ]);

        if ($forScheduleAssignment || $perPageParam === 'all' || (int) $perPageParam === 0) {
            $buildStart = microtime(true);
            $query = $applyOrgFilters($applySearch(User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->select($lite ? $liteSelectColumns : $selectColumns)))
                ->orderBy('name');
            $this->dataScopeService->restrictEmployeeQuery($request->user(), $query, $employeeScopeOptions);
            Log::info('AdminEmployees index timing step', [
                'endpoint' => 'AdminEmployees.index',
                'step' => 'build_and_scope_query_all_mode',
                'time_ms' => round((microtime(true) - $buildStart) * 1000),
            ]);
            $cap = 2000;
            if (! $lite) {
                $query->with($fullEagerLoads);
            }
            $queryExecStart = microtime(true);
            $users = $query->limit($cap)->get();
            $queryExecMs = round((microtime(true) - $queryExecStart) * 1000);
            $hydrationMs = round((microtime(true) - $queryStart) * 1000);
            $transformStart = microtime(true);
            $canSensitive = $this->viewerCanSensitive($request->user());
            $mapBuildStart = microtime(true);
            $orgMaps = $lite ? $this->buildLiteOrgMaps($users) : [];
            $mapBuildMs = round((microtime(true) - $mapBuildStart) * 1000);
            $rowsStart = microtime(true);
            $employees = $users->map(fn (User $u) => $lite
                ? $this->employeeLiteResponse($u, $canSensitive, $orgMaps)
                : $this->employeeResponse($u, $canSensitive, true, false, true))->values();
            $rowsMs = round((microtime(true) - $rowsStart) * 1000);
            $transformMs = round((microtime(true) - $transformStart) * 1000);

            Log::info('Endpoint timing', [
                'endpoint' => 'AdminEmployees.index',
                'lite' => $lite,
                'all_mode' => true,
                'time_ms' => round((microtime(true) - $start) * 1000),
                'query_hydration_ms' => $hydrationMs,
                'query_execute_ms' => $queryExecMs,
                'transform_ms' => $transformMs,
                'transform_build_maps_ms' => $mapBuildMs,
                'transform_rows_ms' => $rowsMs,
                'count' => $users->count(),
            ]);

            return response()->json([
                'employees' => $employees,
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $users->count(),
                    'total' => $users->count(),
                    'last_page' => 1,
                ],
            ]);
        }

        $perPage = (int) $perPageParam;
        if ($perPage <= 0) {
            $perPage = 10;
        }
        $perPage = min($perPage, 100);

        $buildStart = microtime(true);
        $query = $applyOrgFilters($applySearch(User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->select($lite ? $liteSelectColumns : $selectColumns)))
            ->orderBy('name');
        if (! $lite) {
            $query->with($fullEagerLoads);
        }

        $this->dataScopeService->restrictEmployeeQuery($request->user(), $query, $employeeScopeOptions);
        Log::info('AdminEmployees index timing step', [
            'endpoint' => 'AdminEmployees.index',
            'step' => 'build_and_scope_query_paginated',
            'time_ms' => round((microtime(true) - $buildStart) * 1000),
            'per_page' => $perPage,
        ]);

        $queryExecStart = microtime(true);
        $paginator = $query->paginate($perPage);
        $queryExecMs = round((microtime(true) - $queryExecStart) * 1000);
        $hydrationMs = round((microtime(true) - $queryStart) * 1000);

        $transformStart = microtime(true);
        $canSensitive = $this->viewerCanSensitive($request->user());
        $items = collect($paginator->items());
        $mapBuildStart = microtime(true);
        $orgMaps = $lite ? $this->buildLiteOrgMaps($items) : [];
        $mapBuildMs = round((microtime(true) - $mapBuildStart) * 1000);
        $rowsStart = microtime(true);
        $employees = collect($paginator->items())
            ->map(fn (User $u) => $lite
                ? $this->employeeLiteResponse($u, $canSensitive, $orgMaps)
                : $this->employeeResponse($u, $canSensitive, true, false, true))
            ->values();
        $rowsMs = round((microtime(true) - $rowsStart) * 1000);
        $transformMs = round((microtime(true) - $transformStart) * 1000);

        Log::info('Endpoint timing', [
            'endpoint' => 'AdminEmployees.index',
            'lite' => $lite,
            'all_mode' => false,
            'time_ms' => round((microtime(true) - $start) * 1000),
            'query_hydration_ms' => $hydrationMs,
            'query_execute_ms' => $queryExecMs,
            'transform_ms' => $transformMs,
            'transform_build_maps_ms' => $mapBuildMs,
            'transform_rows_ms' => $rowsMs,
            'count' => $employees->count(),
            'page' => $paginator->currentPage(),
        ]);

        return response()->json([
            'employees' => $employees,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Export the full employee roster as CSV (one row per employee).
     *
     * Includes Personal, Employment, Salary, compensation components, deductions/loans,
     * government IDs, tax profile hints, and schedule metadata.
     * Streamed + chunked for large datasets.
     */
    public function exportAllCsv(Request $request)
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            abort(401);
        }

        $fileName = 'employees_export_'.now()->format('Ymd_His').'.csv';

        $header = [
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

        return response()->streamDownload(function () use ($viewer, $header) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            $query = User::query()
                ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
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
                ->orderBy('id');

            $this->dataScopeService->restrictEmployeeQuery($viewer, $query);

            $query->chunkById(250, function (Collection $users) use ($out) {
                foreach ($users as $user) {
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

                    fputcsv($out, [
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
                    ]);
                }
            }, 'id');

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * Add a new employee.
     */
    public function store(Request $request): JsonResponse
    {
        $phoneRequired = (bool) config('attendance.employee_phone_required', true);

        $phoneRules = [
            $phoneRequired ? 'required' : 'nullable',
            'string',
            'regex:/^(\+63\s?9\d{9}|09\d{9})$/u',
            'unique:users,phone_number',
        ];

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'civil_status' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'home_address' => ['nullable', 'string', 'max:1000'],
            'full_address' => ['nullable', 'string', 'max:1000'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone_number' => $phoneRules,
            'schedule' => ['nullable', 'array'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'branch_office_location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', 'in:full_time,part_time,contract,probationary'],
            'employment_status' => ['nullable', 'string', Rule::in(array_column(EmploymentStatus::cases(), 'value'))],
            'hire_date' => ['nullable', 'date'],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'working_schedule_id' => ['nullable', 'integer', 'exists:working_schedules,id'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'signature_data_url' => ['nullable', 'string'],
        ], [
            'phone_number.regex' => 'The phone number must be in Philippine mobile format (e.g. +63 912 345 6789 or 09123456789).',
            'phone_number.unique' => 'This phone number is already in use by another account.',
        ]);

        $departmentName = $validated['department'] ?? null;
        $departmentId = $validated['department_id'] ?? null;

        // If the provided department matches an existing Department record by name,
        // link the employee via department_id so that department employee counts
        // and "View Employees" lists include this user.
        if ($departmentId !== null) {
            $department = Department::find($departmentId);
            if ($department) {
                $departmentName = $department->name;
            }
        } elseif ($departmentName !== null && $departmentName !== '') {
            $department = Department::where('name', $departmentName)->first();
            if ($department) {
                $departmentId = $department->id;
                $departmentName = $department->name;
            }
        }

        if (! empty($validated['supervisor_id'])) {
            $supervisor = User::where('id', (int) $validated['supervisor_id'])
                ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->first();
            if (! $supervisor || ! $this->isManagerialPosition($supervisor->position)) {
                throw ValidationException::withMessages([
                    'supervisor_id' => ['The selected supervisor must be a staff member with a managerial/supervisory position.'],
                ]);
            }
        }

        $resolvedCompanyId = $validated['company_id'] ?? null;
        $resolvedBranchId = $validated['branch_id'] ?? null;
        if ($resolvedCompanyId === null && $resolvedBranchId !== null) {
            $branch = Branch::query()->find((int) $resolvedBranchId);
            $resolvedCompanyId = $branch?->company_id;
        }

        $this->dataScopeService->assertCanCreateEmployeeInOrg(
            $request->user(),
            $resolvedCompanyId !== null ? (int) $resolvedCompanyId : null,
            $resolvedBranchId !== null ? (int) $resolvedBranchId : null,
            $departmentId
        );

        $rawPhone = $validated['phone_number'] ?? null;
        $phone = is_string($rawPhone) && trim($rawPhone) !== '' ? \App\Services\SmsService::normalizePhone($rawPhone) : null;

        $resolvedHomeAddress = $this->resolveHomeAddressForEmployeeCreate($validated);

        $user = User::create([
            'name' => $this->composeFullName(
                $validated['first_name'],
                $validated['middle_name'] ?? null,
                $validated['last_name']
            ),
            'first_name' => trim($validated['first_name']),
            'middle_name' => isset($validated['middle_name']) && trim((string) $validated['middle_name']) !== '' ? trim((string) $validated['middle_name']) : null,
            'last_name' => trim($validated['last_name']),
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'gender' => isset($validated['gender']) && trim((string) $validated['gender']) !== '' ? trim((string) $validated['gender']) : null,
            'civil_status' => isset($validated['civil_status']) && trim((string) $validated['civil_status']) !== '' ? trim((string) $validated['civil_status']) : null,
            'nationality' => isset($validated['nationality']) && trim((string) $validated['nationality']) !== '' ? trim((string) $validated['nationality']) : null,
            'home_address' => $resolvedHomeAddress,
            'full_address' => $resolvedHomeAddress,
            'street_address' => isset($validated['street_address']) && trim((string) $validated['street_address']) !== '' ? trim((string) $validated['street_address']) : null,
            'barangay' => isset($validated['barangay']) && trim((string) $validated['barangay']) !== '' ? trim((string) $validated['barangay']) : null,
            'city' => isset($validated['city']) && trim((string) $validated['city']) !== '' ? trim((string) $validated['city']) : null,
            'province' => isset($validated['province']) && trim((string) $validated['province']) !== '' ? trim((string) $validated['province']) : null,
            'postal_code' => isset($validated['postal_code']) && trim((string) $validated['postal_code']) !== '' ? trim((string) $validated['postal_code']) : null,
            'email' => $validated['email'],
            'phone_number' => $phone,
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_EMPLOYEE,
            'schedule' => $validated['schedule'] ?? null,
            'is_active' => true,
            'department' => $departmentName,
            'department_id' => $departmentId,
            'company_id' => $resolvedCompanyId !== null ? (int) $resolvedCompanyId : null,
            'branch_id' => $resolvedBranchId !== null ? (int) $resolvedBranchId : null,
            'position' => isset($validated['position']) && trim((string) $validated['position']) !== '' ? trim($validated['position']) : null,
            'branch_office_location' => isset($validated['branch_office_location']) && trim((string) $validated['branch_office_location']) !== '' ? trim((string) $validated['branch_office_location']) : null,
            'employment_type' => $validated['employment_type'] ?? null,
            'employment_status' => isset($validated['employment_status'])
                ? EmploymentStatus::from((string) $validated['employment_status'])->value
                : EmploymentStatus::Probationary->value,
            'hire_date' => $validated['hire_date'] ?? null,
            'supervisor_id' => $validated['supervisor_id'] ?? null,
            'working_schedule_id' => $validated['working_schedule_id'] ?? null,
            'daily_rate' => isset($validated['daily_rate']) && $validated['daily_rate'] > 0 ? (float) $validated['daily_rate'] : null,
            'monthly_rate' => isset($validated['monthly_rate']) && $validated['monthly_rate'] > 0 ? (float) $validated['monthly_rate'] : null,
        ]);

        $user->employee_code = $this->generateEmployeeCode($user->id);
        $user->save();

        if ($user->company_id) {
            $company = Company::query()->find($user->company_id);
            if ($company?->default_pay_cycle_id) {
                $user->pay_cycle_id = $company->default_pay_cycle_id;
                $user->save();
            }
        }

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('profiles', 'public');
            $user->profile_image = $path;
            $user->save();
        }

        if (! empty($validated['signature_data_url'])) {
            try {
                $user = $this->eSignatureService->saveFromDataUrl($user, (string) $validated['signature_data_url']);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'signature_data_url' => [$e->getMessage()],
                ]);
            }
        }

        if ($phone !== null) {
            UserPhoneChangeLog::create([
                'user_id' => $user->id,
                'changed_by_user_id' => $request->user()?->id,
                'old_phone_number' => null,
                'new_phone_number' => $phone,
            ]);
        }

        // Auto-generate QR code based on employee ID.
        $user->forceFill([
            'qr_token' => User::generateQrTokenFor($user),
            'qr_token_generated_at' => now(),
        ])->save();

        try {
            UpdateEmployeeProfileJob::dispatch((int) $user->id, true, true)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch post-employee-create queue job', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Employee added successfully.',
            'recalculation_queued' => true,
            'employee' => $this->employeeResponse(
                $user->fresh(),
                $this->viewerCanSensitive($request->user()),
                false,
                false
            ),
        ], 201);
    }

    /**
     * Get employee QR token (for printing / issuing badge).
     */
    public function getQr(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id);

        if (empty($employee->qr_token)) {
            return response()->json([
                'message' => 'No QR token generated yet.',
                'has_qr' => false,
            ], 404);
        }

        $employee->loadMissing([
            'companyHeadships:id,name,logo,company_head_id',
            'company:id,name,logo',
            'branch.company:id,name,logo',
            'departmentRelation.branch.company:id,name,logo',
        ]);
        $effectiveCompany = $employee->companyHeadships->first()
            ?? $employee->company
            ?? $employee->branch?->company
            ?? $employee->departmentRelation?->branch?->company;
        $companyLogoUrl = $effectiveCompany?->logo ? $this->companyLogoUrl($effectiveCompany->logo) : null;

        return response()->json([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'qr_token' => $employee->qr_token,
            'qr_token_generated_at' => $employee->qr_token_generated_at?->toIso8601String(),
            'company_logo_url' => $companyLogoUrl,
        ]);
    }

    /**
     * Regenerate QR token for an employee (invalidates old QR).
     */
    public function regenerateQr(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $employee->update([
            'qr_token' => User::generateQrTokenFor($employee),
            'qr_token_generated_at' => now(),
        ]);

        $employee->loadMissing([
            'companyHeadships:id,name,logo,company_head_id',
            'company:id,name,logo',
            'branch.company:id,name,logo',
            'departmentRelation.branch.company:id,name,logo',
        ]);
        $effectiveCompany = $employee->companyHeadships->first()
            ?? $employee->company
            ?? $employee->branch?->company
            ?? $employee->departmentRelation?->branch?->company;
        $companyLogoUrl = $effectiveCompany?->logo ? $this->companyLogoUrl($effectiveCompany->logo) : null;

        return response()->json([
            'message' => 'QR token regenerated.',
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'qr_token' => $employee->qr_token,
            'qr_token_generated_at' => $employee->qr_token_generated_at?->toIso8601String(),
            'company_logo_url' => $companyLogoUrl,
        ]);
    }

    /**
     * Clear QR token for an employee (disables QR-based attendance until regenerated).
     */
    public function clearQr(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $employee->update([
            'qr_token' => null,
            'qr_token_generated_at' => null,
        ]);

        return response()->json([
            'message' => 'QR token cleared.',
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Assign or clear schedule for employee. Pass schedule array or null/empty to clear (no shift assigned).
     */
    public function updateSchedule(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $validated = $request->validate([
            'schedule' => ['nullable', 'array'],
        ]);

        $schedule = $validated['schedule'] ?? null;
        if ($schedule !== null) {
            if ($schedule === []) {
                $schedule = null;
            } else {
                $hasWorkingDay = false;
                foreach ($schedule as $dayConfig) {
                    if (is_array($dayConfig) && trim((string) ($dayConfig['in'] ?? '')) !== '') {
                        $hasWorkingDay = true;
                        break;
                    }
                }
                if (! $hasWorkingDay) {
                    $schedule = null;
                }
            }
        }
        $employee->update([
            'schedule' => $schedule,
            'working_schedule_id' => null,
        ]);

        try {
            UpdateEmployeeProfileJob::dispatch((int) $employee->id, false, false)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch post-schedule-update queue job', [
                'user_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Schedule updated.',
            'recalculation_queued' => true,
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Update employee profile (personal info).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $startedAt = microtime(true);
        try {
            $employee = $this->loadScopedEmployee($request, $id, true);
            $oldPhone = $employee->phone_number;

            $request->validate([
                'first_name' => ['sometimes', 'required', 'string', 'max:255'],
                'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'last_name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,'.$id],
                'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
                'department' => ['sometimes', 'nullable', 'string', 'max:255'],
                'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
                'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
                'phone_number' => [
                    'nullable',
                    'string',
                    'regex:/^(\+63\s?9\d{9}|09\d{9})$/u',
                    'unique:users,phone_number,'.$id,
                ],
                'date_of_birth' => ['sometimes', 'nullable', 'date'],
                'gender' => ['sometimes', 'nullable', 'string', 'max:50'],
                'civil_status' => ['sometimes', 'nullable', 'string', 'max:50'],
                'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
                'home_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'full_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
                'barangay' => ['sometimes', 'nullable', 'string', 'max:255'],
                'city' => ['sometimes', 'nullable', 'string', 'max:255'],
                'province' => ['sometimes', 'nullable', 'string', 'max:255'],
                'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
                'position' => ['nullable', 'string', 'max:255'],
                'branch_office_location' => ['sometimes', 'nullable', 'string', 'max:255'],
                'employment_type' => ['sometimes', 'nullable', 'string', 'in:full_time,part_time,contract,probationary'],
                'employment_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(EmploymentStatus::cases(), 'value'))],
                'employment_status_effective_date' => ['sometimes', 'nullable', 'date'],
                'hire_date' => ['sometimes', 'nullable', 'date'],
                'contract_start_date' => ['sometimes', 'nullable', 'date'],
                'contract_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:contract_start_date'],
                'supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
                'working_schedule_id' => ['sometimes', 'nullable', 'integer', 'exists:working_schedules,id'],
                'daily_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'monthly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'monthly_salary' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
                'salary_effectivity_date' => ['sometimes', 'nullable', 'date'],
            ], [
                'phone_number.regex' => 'The phone number must be in Philippine mobile format (e.g. +63 912 345 6789 or 09123456789).',
                'phone_number.unique' => 'This phone number is already in use by another account.',
            ]);

            if ($this->requestHasSalaryMoneyInput($request) && ! $this->canEditSalaryViaProfile($request->user())) {
                return response()->json([
                    'message' => 'Salary details can only be edited by HR/Admin. Please contact HR.',
                ], 403);
            }

            if ($this->requestHasInput($request, 'first_name')) {
                $employee->first_name = trim((string) $request->input('first_name'));
            }
            if ($this->requestHasInput($request, 'middle_name')) {
                $middleRaw = $request->input('middle_name');
                $employee->middle_name = is_string($middleRaw) && trim($middleRaw) !== '' ? trim($middleRaw) : null;
            }
            if ($this->requestHasInput($request, 'last_name')) {
                $employee->last_name = trim((string) $request->input('last_name'));
            }
            if ($this->requestHasInput($request, 'email')) {
                $employee->email = trim((string) $request->input('email'));
            }
            if ($this->requestHasInput($request, 'phone_number')) {
                $raw = $request->input('phone_number');
                $employee->phone_number = is_string($raw) && trim($raw) !== '' ? \App\Services\SmsService::normalizePhone($raw) : null;
            }
            if ($this->requestHasInput($request, 'date_of_birth')) {
                $dobRaw = $request->input('date_of_birth');
                $employee->date_of_birth = is_string($dobRaw) && trim($dobRaw) !== '' ? $dobRaw : null;
            }
            if ($this->requestHasInput($request, 'gender')) {
                $genderRaw = $request->input('gender');
                $employee->gender = is_string($genderRaw) && trim($genderRaw) !== '' ? trim($genderRaw) : null;
            }
            if ($this->requestHasInput($request, 'civil_status')) {
                $civilRaw = $request->input('civil_status');
                $employee->civil_status = is_string($civilRaw) && trim($civilRaw) !== '' ? trim($civilRaw) : null;
            }
            if ($this->requestHasInput($request, 'nationality')) {
                $nationalityRaw = $request->input('nationality');
                $employee->nationality = is_string($nationalityRaw) && trim($nationalityRaw) !== '' ? trim($nationalityRaw) : null;
            }
            $this->applyHomeAddressFromAdminRequest($request, $employee);

            if ($this->requestHasInput($request, 'position')) {
                $posRaw = $request->input('position');
                $employee->position = is_string($posRaw) && trim($posRaw) !== '' ? trim($posRaw) : null;
            }

            if ($this->requestHasInput($request, 'department_id')) {
                $deptId = $request->input('department_id');
                if ($deptId === null || $deptId === '') {
                    $employee->department_id = null;
                    $employee->department = null;
                } else {
                    $department = Department::find((int) $deptId);
                    $employee->department_id = $department?->id;
                    $employee->department = $department?->name;
                }
            } elseif ($this->requestHasInput($request, 'department')) {
                $depRaw = trim((string) $request->input('department'));
                if ($depRaw === '') {
                    $employee->department_id = null;
                    $employee->department = null;
                } else {
                    $department = Department::where('name', $depRaw)->first();
                    $employee->department_id = $department?->id;
                    $employee->department = $department?->name ?? $depRaw;
                }
            }

            if ($this->requestHasInput($request, 'company_id')) {
                $employee->company_id = ($request->input('company_id') === null || $request->input('company_id') === '') ? null : (int) $request->input('company_id');
                $cid = $employee->company_id;
                if ($cid) {
                    $co = Company::query()->find($cid);
                    $employee->pay_cycle_id = $co?->default_pay_cycle_id;
                } else {
                    $employee->pay_cycle_id = null;
                }
            }
            if ($this->requestHasInput($request, 'branch_id')) {
                $employee->branch_id = ($request->input('branch_id') === null || $request->input('branch_id') === '') ? null : (int) $request->input('branch_id');
            }
            if ($this->requestHasInput($request, 'branch_office_location')) {
                $branchRaw = $request->input('branch_office_location');
                $employee->branch_office_location = is_string($branchRaw) && trim($branchRaw) !== '' ? trim($branchRaw) : null;
            }
            if ($this->requestHasInput($request, 'employment_type')) {
                $typeRaw = $request->input('employment_type');
                $employee->employment_type = is_string($typeRaw) && trim($typeRaw) !== '' ? trim($typeRaw) : null;
            }
            if ($this->requestHasInput($request, 'hire_date')) {
                $hireRaw = $request->input('hire_date');
                $employee->hire_date = is_string($hireRaw) && trim($hireRaw) !== '' ? $hireRaw : null;
            }
            if ($this->requestHasInput($request, 'contract_start_date')) {
                $raw = $request->input('contract_start_date');
                $employee->contract_start_date = is_string($raw) && trim($raw) !== '' ? $raw : null;
            }
            if ($this->requestHasInput($request, 'contract_end_date')) {
                $raw = $request->input('contract_end_date');
                $employee->contract_end_date = is_string($raw) && trim($raw) !== '' ? $raw : null;
            }
            if ($this->requestHasInput($request, 'working_schedule_id')) {
                $wsRaw = $request->input('working_schedule_id');
                $employee->working_schedule_id = ($wsRaw === null || $wsRaw === '') ? null : (int) $wsRaw;
                // Keep legacy JSON schedule null when using schedules module.
                if ($employee->working_schedule_id !== null) {
                    $employee->schedule = null;
                }
                $employee->pending_working_schedule_id = null;
                $employee->pending_schedule_effective_from = null;
            }
            if ($this->requestHasInput($request, 'supervisor_id')) {
                $supRaw = $request->input('supervisor_id');
                if ($supRaw === null || $supRaw === '') {
                    $employee->supervisor_id = null;
                } else {
                    $supervisor = User::where('id', (int) $supRaw)
                        ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                        ->first();
                    if (! $supervisor || ! $this->isManagerialPosition($supervisor->position)) {
                        throw ValidationException::withMessages([
                            'supervisor_id' => ['The selected supervisor must be a staff member with a managerial/supervisory position.'],
                        ]);
                    }
                    $employee->supervisor_id = $supervisor->id;
                }
            }
            if ($this->canEditSalaryViaProfile($request->user())) {
                if ($this->requestHasInput($request, 'monthly_salary')) {
                    $raw = $request->input('monthly_salary');
                    $employee->monthly_salary = ($raw === null || $raw === '') ? null : $raw;
                }
                if ($this->requestHasInput($request, 'salary_effectivity_date')) {
                    $raw = $request->input('salary_effectivity_date');
                    $employee->salary_effectivity_date = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
                }
                if ($this->requestHasInput($request, 'monthly_rate')) {
                    $raw = $request->input('monthly_rate');
                    $employee->monthly_rate = ($raw === null || $raw === '') ? null : $raw;
                }

                if ($this->requestHasInput($request, 'monthly_salary') || $this->requestHasInput($request, 'working_schedule_id')) {
                    $this->applyScheduleDerivedRatesFromMonthlySalary($employee);
                } elseif ($this->requestHasInput($request, 'hourly_rate')) {
                    $raw = $request->input('hourly_rate');
                    $employee->hourly_rate = ($raw === null || $raw === '') ? null : $raw;
                } elseif ($this->requestHasInput($request, 'daily_rate')) {
                    $raw = $request->input('daily_rate');
                    $employee->daily_rate = ($raw === null || $raw === '') ? null : $raw;
                }
            } elseif ($this->rbacService->can($request->user(), 'employees.sensitive')
                && $this->requestHasInput($request, 'working_schedule_id')) {
                // Work schedule lives on Employment; refresh derived rates when monthly salary is set.
                $this->applyScheduleDerivedRatesFromMonthlySalary($employee);
            }

            if ($this->requestHasInput($request, 'first_name') || $this->requestHasInput($request, 'middle_name') || $this->requestHasInput($request, 'last_name')) {
                $employee->name = $this->composeFullName(
                    $employee->first_name ?? '',
                    $employee->middle_name,
                    $employee->last_name ?? ''
                );
            }

            $employmentFieldsTouched = false;

            if ($this->requestHasInput($request, 'employment_status') && $request->filled('employment_status')) {
                $employmentFieldsTouched = true;
                $newStatus = EmploymentStatus::from((string) $request->input('employment_status'));
                $effRaw = $request->input('employment_status_effective_date');
                $effDate = is_string($effRaw) && trim($effRaw) !== '' ? \Carbon\Carbon::parse($effRaw) : null;
                if ($employee->employment_status !== $newStatus->value) {
                    app(\App\Services\EmployeeStatusService::class)->changeStatus(
                        $employee,
                        $newStatus,
                        'manual_admin',
                        $request->user(),
                        null,
                        $effDate
                    );
                } elseif ($this->requestHasInput($request, 'employment_status_effective_date')) {
                    $employee->employment_status_effective_date = $effDate;
                }
            } elseif ($this->requestHasInput($request, 'employment_status_effective_date')) {
                $employmentFieldsTouched = true;
                $effRaw = $request->input('employment_status_effective_date');
                $employee->employment_status_effective_date = is_string($effRaw) && trim($effRaw) !== ''
                    ? \Carbon\Carbon::parse($effRaw)
                    : null;
            }

            // Memory-safe guard: leave-credit recomputation is expensive and should only run when
            // employment lifecycle fields actually change — not on every save that re-sends hire_date unchanged.
            $leaveCreditRelevantFieldsTouched = $employmentFieldsTouched
                || $employee->isDirty([
                    'hire_date',
                    'contract_start_date',
                    'contract_end_date',
                    'employment_type',
                ]);

            $salaryAuditFieldKeys = [
                'monthly_salary',
                'hourly_rate',
                'daily_rate',
                'monthly_rate',
                'salary_effectivity_date',
                'working_schedule_id',
            ];
            $salaryAuditDirty = array_intersect_key($employee->getDirty(), array_flip($salaryAuditFieldKeys));

            $employee->save();

            if ($salaryAuditDirty !== [] && $this->rbacService->can($request->user(), 'employees.sensitive')) {
                UserAdminActivityLog::query()->create([
                    'subject_user_id' => $employee->id,
                    'actor_user_id' => $request->user()?->id,
                    'action' => $this->canEditSalaryViaProfile($request->user())
                        ? 'salary_profile_updated'
                        : 'working_schedule_derived_rates_refreshed',
                    'meta' => [
                        'via_profile_salary_edit' => $this->canEditSalaryViaProfile($request->user()),
                        'fields' => array_keys($salaryAuditDirty),
                        'changes' => $salaryAuditDirty,
                    ],
                    'ip_address' => $request->ip(),
                ]);
            }

            if ($oldPhone !== $employee->phone_number) {
                UserPhoneChangeLog::create([
                    'user_id' => $employee->id,
                    'changed_by_user_id' => $request->user()?->id,
                    'old_phone_number' => $oldPhone,
                    'new_phone_number' => $employee->phone_number,
                ]);
            }

            // Org fields may change effective company; stored qr_token must stay in sync (transfer() already does this).
            $employee->syncQrTokenWithEffectiveCompany();

            $uid = (int) $employee->id;
            try {
                UpdateEmployeeProfileJob::dispatch(
                    $uid,
                    $leaveCreditRelevantFieldsTouched,
                    $employmentFieldsTouched
                )->onQueue('default');
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch post-employee-update queue job', [
                    'user_id' => $uid,
                    'message' => $e->getMessage(),
                ]);
            }

            Log::info('EmployeeController@update succeeded', [
                'employee_id' => (int) $employee->id,
                'actor_id' => $request->user()?->id,
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'fast_response' => true,
            ]);

            return response()->json([
                'message' => 'Employee updated.',
                'recalculation_queued' => true,
                'employee' => $this->employeeResponse(
                    $employee->fresh(),
                    $this->viewerCanSensitive($request->user()),
                    false,
                    false
                ),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('EmployeeController@update failed', [
                'employee_id' => $id,
                'actor_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
            report($e);

            return response()->json([
                'message' => 'Unable to save employee changes. Please try again.',
            ], 500);
        }
    }

    /**
     * Transfer employee to a new branch.
     * - Blocks Company Heads (must reassign head first).
     * - Blocks transfer to same branch.
     * - Intra-company only: target branch must belong to employee's current company.
     * - Automatically removes Branch Manager role from old branch if applicable.
     * - Clears department on transfer.
     */
    public function transfer(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);
        $employee->loadMissing(['branch', 'departmentRelation.branch', 'companyHeadships']);

        $validated = $request->validate([
            'target_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transfer_date' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetBranchId = (int) $validated['target_branch_id'];
        $targetBranch = Branch::with('company')->findOrFail($targetBranchId);
        $transferDate = isset($validated['transfer_date']) && $validated['transfer_date']
            ? $validated['transfer_date']
            : now()->toDateString();
        $departmentId = isset($validated['department_id']) && $validated['department_id']
            ? (int) $validated['department_id']
            : null;
        $reason = isset($validated['reason']) && trim($validated['reason']) !== ''
            ? trim($validated['reason'])
            : null;

        // Block: Company Head cannot be transferred
        $companyHeadOf = $employee->companyHeadships->first();
        if ($companyHeadOf) {
            throw ValidationException::withMessages([
                'target_branch_id' => [
                    'Company Heads cannot be transferred via this flow. Please reassign the company head first.',
                ],
            ]);
        }

        $fromBranch = $employee->branch ?? $employee->departmentRelation?->branch;
        $fromBranchId = $fromBranch?->id;
        $fromCompanyId = $employee->getEffectiveCompanyId();

        // Block: Cannot transfer to same branch
        if ($fromBranchId !== null && (int) $fromBranchId === $targetBranchId) {
            throw ValidationException::withMessages([
                'target_branch_id' => ['Employee is already in this branch.'],
            ]);
        }

        // Intra-company: target branch must belong to employee's current company
        if ($fromCompanyId !== null) {
            if ($targetBranch->company_id !== $fromCompanyId) {
                throw ValidationException::withMessages([
                    'target_branch_id' => [
                        'Transfer to a different company is not allowed. Select a branch within the same company.',
                    ],
                ]);
            }
        }

        // If selecting a department, it must belong to the target branch
        if ($departmentId !== null) {
            $dept = Department::where('id', $departmentId)->where('branch_id', $targetBranchId)->first();
            if (! $dept) {
                throw ValidationException::withMessages([
                    'department_id' => ['The selected department does not belong to the target branch.'],
                ]);
            }
        }

        $branchManagerRemoved = false;
        if ($fromBranchId !== null) {
            $oldBranch = Branch::find($fromBranchId);
            if ($oldBranch && (int) $oldBranch->branch_manager_id === (int) $employee->id) {
                $oldBranch->branch_manager_id = null;
                $oldBranch->save();
                $branchManagerRemoved = true;
            }
        }

        $toCompanyId = $targetBranch->company_id;
        $employee->branch_id = $targetBranchId;
        $employee->company_id = $toCompanyId;
        $employee->department_id = $departmentId;
        $employee->department = $departmentId
            ? Department::find($departmentId)?->name
            : null;
        $employee->save();

        EmployeeTransferLog::create([
            'employee_id' => $employee->id,
            'admin_id' => $request->user()->id,
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $targetBranchId,
            'from_company_id' => $fromCompanyId,
            'to_company_id' => $toCompanyId,
            'transfer_date' => $transferDate,
            'reason' => $reason,
            'branch_manager_removed' => $branchManagerRemoved,
        ]);

        $fresh = $employee->fresh();
        $fresh->update([
            'qr_token' => User::generateQrTokenFor($fresh),
            'qr_token_generated_at' => now(),
        ]);

        try {
            UpdateEmployeeProfileJob::dispatch((int) $employee->id, true, true)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch post-transfer queue job', [
                'user_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Employee transferred successfully.',
            'recalculation_queued' => true,
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Upload/replace profile photo for an employee (admin).
     */
    public function uploadPhoto(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
        ]);

        if ($employee->profile_image) {
            Storage::disk('public')->delete($employee->profile_image);
        }

        $path = $request->file('photo')->store('profiles', 'public');
        $employee->profile_image = $path;
        $employee->save();

        return response()->json([
            'message' => 'Employee photo updated.',
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Remove profile photo for an employee (admin).
     */
    public function removePhoto(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);
        if ($employee->profile_image) {
            Storage::disk('public')->delete($employee->profile_image);
            $employee->profile_image = null;
            $employee->save();
        }

        return response()->json([
            'message' => 'Employee photo removed.',
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Toggle employee active status.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $employee->update(['is_active' => ! $employee->is_active]);
        $employee->refresh();

        try {
            UpdateEmployeeProfileJob::dispatch((int) $employee->id, false, false)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch post-toggle-active queue job', [
                'user_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $employee->is_active ? 'Employee activated.' : 'Employee deactivated.',
            'recalculation_queued' => true,
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Register employee face. Amazon Rekognition Face Liveness (session) or legacy image capture.
     * Liveness + DeepFace embedding + duplicate check run in {@see ProcessFaceRegistrationJob} under cache/DB locks.
     */
    public function registerFace(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $validated = $request->validate([
            'liveness_session_id' => ['nullable', 'string', 'max:255'],
            'image_base64' => ['nullable', 'string'],
            'liveness_type' => ['nullable', 'string', 'in:rekognition,mediapipe,hybrid'],
        ]);
        $sessionId = $validated['liveness_session_id'] ?? null;
        $imageBase64 = $validated['image_base64'] ?? null;
        if (! $sessionId && ! $imageBase64) {
            return response()->json([
                'message' => 'Perform face liveness first or provide a face image.',
                'errors' => ['face' => ['Face liveness session or face image is required.']],
                'error_code' => 'validation_error',
            ], 422);
        }

        $trackId = (string) Str::uuid();
        FaceRegistrationStatusService::create($trackId, ['target_user_id' => $employee->id]);

        ProcessFaceRegistrationJob::dispatch(
            $trackId,
            $employee->id,
            $sessionId,
            $imageBase64,
            $validated['liveness_type'] ?? 'rekognition',
            $request->user()?->id,
            $request->ip(),
            $request->userAgent(),
            'admin',
        )->onQueue('face-registration');

        return $this->adminFaceRegistrationHttpResponse($request, $trackId, $employee->id, true);
    }

    /**
     * Poll async face registration for an employee (admin HR).
     */
    public function faceRegistrationStatus(Request $request, int $id, string $trackId): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        return $this->adminFaceRegistrationHttpResponse($request, $trackId, $employee->id, false);
    }

    /**
     * @param  bool  $isInitialPost  When true, return 202 for in-progress; when false (GET poll), return 200 for in-progress.
     */
    private function adminFaceRegistrationHttpResponse(Request $request, string $trackId, int $expectedUserId, bool $isInitialPost): JsonResponse
    {
        $row = FaceRegistrationStatusService::get($trackId);
        if ($row === null) {
            return response()->json(['message' => 'Unknown or expired face registration request.'], 404);
        }
        if ((int) ($row['target_user_id'] ?? 0) !== $expectedUserId) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $status = $row['status'] ?? 'pending';
        if ($status === 'completed') {
            $employee = User::query()->find($expectedUserId);

            return response()->json([
                'status' => 'completed',
                'message' => 'Face registered successfully.',
                'employee' => $employee
                    ? $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false)
                    : null,
            ]);
        }
        if ($status === 'failed') {
            $msg = $row['message'] ?? 'Face registration failed.';

            return response()->json([
                'status' => 'failed',
                'message' => $msg,
                'errors' => ['face' => [$msg]],
                'error_code' => $row['error_code'] ?? 'registration_failed',
            ], 422);
        }

        return response()->json([
            'status' => $status,
            'message' => 'Processing face…',
            'track_id' => $trackId,
            'employee_id' => $expectedUserId,
        ], $isInitialPost ? 202 : 200);
    }

    /**
     * Update or clear employee face template (128D descriptor).
     */
    public function updateFace(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $validated = $request->validate([
            'face_descriptor' => ['nullable', 'array'],
            'face_descriptor.*' => ['numeric'],
            'face_image' => ['nullable', 'string'],
        ]);

        $descriptor = $validated['face_descriptor'] ?? null;
        $faceImage = $validated['face_image'] ?? null;

        if ($descriptor !== null) {
            if (count($descriptor) !== 128) {
                return response()->json(['message' => 'Face descriptor must be 128 dimensions.'], 422);
            }
            $descriptorArray = array_values(array_map('floatval', $descriptor));
            $existingOwner = FaceVerificationService::findExistingOwnerOfFace($descriptorArray, $employee->id);
            if ($existingOwner !== null) {
                DuplicateFaceRegistrationAttempt::create([
                    'attempted_for_user_id' => $employee->id,
                    'existing_user_id' => $existingOwner->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'message' => FaceVerificationService::duplicateRegistrationUserMessage(),
                    'errors' => ['face' => [FaceVerificationService::duplicateRegistrationUserMessage()]],
                    'error_code' => 'face_already_registered',
                ], 422);
            }
            $samples = $employee->face_descriptor_samples;
            if (! is_array($samples)) {
                $samples = [];
            }
            $maxSamples = (int) config('attendance.face_samples_max', 10);
            $samples[] = array_values(array_map('floatval', $descriptor));
            $samples = array_slice($samples, -$maxSamples);
            $primaryEmbedding = json_encode($samples[0]);
            $employee->face_descriptor = $primaryEmbedding;
            $employee->face_embedding = $primaryEmbedding;
            $employee->face_descriptor_samples = $samples;
            $employee->face_image = $faceImage;
            $employee->face_registered_at = now();
            $employee->face_status = 'registered';
            UserAdminActivityLog::query()->create([
                'subject_user_id' => $employee->id,
                'actor_user_id' => $request->user()?->id,
                'action' => 'face_registered',
                'meta' => [
                    'channel' => 'admin_manual',
                    'liveness_type' => 'manual_descriptor',
                ],
                'ip_address' => $request->ip(),
            ]);
        } else {
            $employee->clearFaceRegistrationData($request->user()?->id);
        }

        $employee->save();

        return response()->json([
            'message' => $descriptor ? 'Face registered.' : 'Face removed.',
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Reset employee password.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $employee->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password reset successfully.',
        ], 200);
    }

    /**
     * Get employee's registered face image for preview (Admin only).
     */
    public function getFace(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id);

        if (! $this->userHasFace($employee) || empty($employee->face_image)) {
            return response()->json([
                'has_face' => false,
                'face_image' => null,
                'message' => 'No face registered.',
            ]);
        }

        $img = $employee->face_image;
        $dataUrl = is_string($img) && (str_starts_with($img, 'data:') || preg_match('/^[A-Za-z0-9+\/=]+$/', $img))
            ? (str_starts_with($img, 'data:') ? $img : 'data:image/jpeg;base64,'.$img)
            : null;

        return response()->json([
            'has_face' => true,
            'face_image' => $dataUrl,
        ]);
    }

    /**
     * Permanently delete an employee and cascade related data (attendance logs, etc.).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);

        $name = $employee->name;
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted.',
            'employee_id' => $id,
            'employee_name' => $name,
        ]);
    }

    public function saveSignature(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);
        $validated = $request->validate([
            'signature_data_url' => ['required', 'string'],
        ]);
        try {
            $updated = $this->eSignatureService->saveFromDataUrl($employee, (string) $validated['signature_data_url']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Signature saved.',
            'employee' => $this->employeeResponse($updated, $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    public function clearSignature(Request $request, int $id): JsonResponse
    {
        $employee = $this->loadScopedEmployee($request, $id, true);
        try {
            $updated = $this->eSignatureService->clear($employee);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Signature removed.',
            'employee' => $this->employeeResponse($updated, $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    /**
     * Manual leave credit adjustment (audited). HR with employees.edit only.
     */
    public function adjustLeaveCredits(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $employee = $this->loadScopedEmployee($request, $id, true);

        try {
            $balance = $this->leaveCreditService->adjustLeaveCredits(
                (int) $employee->id,
                (int) $validated['delta'],
                trim((string) $validated['reason']),
                $request->user()
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Invalid adjustment.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Leave credits updated.',
            'leave_credits' => $balance,
            'employee' => $this->employeeResponse($employee->fresh(), $this->viewerCanSensitive($request->user()), false, false),
        ]);
    }

    private function loadScopedEmployee(Request $request, int $id, bool $forMutation = false): User
    {
        $employee = User::where('id', $id)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        if ($forMutation) {
            $this->ensureActorCanMutateEmployee($request, $employee);
        }

        return $employee;
    }

    /**
     * Non Admin/HR users can only mutate their own profile record.
     * Admin/HR retain full edit/delete access across scoped employees.
     */
    private function ensureActorCanMutateEmployee(Request $request, User $employee): void
    {
        $actor = $request->user();
        if (! $actor) {
            throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
        }
        if ($this->canMutateAnyEmployee($actor)) {
            return;
        }
        if ((int) $actor->id === (int) $employee->id) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Forbidden. You may only edit your own profile.',
        ], 403));
    }

    private function canMutateAnyEmployee(User $actor): bool
    {
        $hrRole = strtolower(trim((string) ($actor->hr_role ?? '')));

        return $actor->isAdmin() || in_array($hrRole, ['admin_hr', 'admin'], true);
    }

    private function viewerCanSensitive(?User $viewer): bool
    {
        if ($viewer === null) {
            return true;
        }

        return $this->rbacService->can($viewer, 'employees.sensitive');
    }

    /**
     * Preview schedule-derived rate divisors for an employee as if they were assigned {@see $workingScheduleId}.
     * Uses {@see ScheduleRateService::describeForUser()} with a schedule override (calendar month + holidays).
     */
    public function scheduleRatePreview(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'working_schedule_id' => ['required', 'integer', 'exists:working_schedules,id'],
        ]);

        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $target = User::query()->find($id);
        if (! $target) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $target);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        $ws = WorkingSchedule::query()->findOrFail((int) $request->query('working_schedule_id'));
        $schedule = $this->scheduleRateService->buildScheduleFromWorkingSchedule($ws);
        $canSensitive = $this->viewerCanSensitive($actor);
        $monthlyBase = $canSensitive
            ? (float) ($target->monthly_salary ?? $target->monthly_rate ?? 0)
            : 0.0;
        $metrics = $this->scheduleRateService->describeForUser(
            $target,
            $canSensitive && $monthlyBase > 0 ? $monthlyBase : null,
            null,
            $schedule,
            $ws->name
        );

        return response()->json([
            'schedule_name' => $ws->name,
            'schedule_working_days_per_week' => $metrics['working_days_per_week'],
            'schedule_working_days_per_month' => $metrics['working_days_per_month'],
            'schedule_working_hours_per_day' => $metrics['working_hours_per_day'],
            'schedule_working_days_in_calendar_month' => $metrics['working_days_in_calendar_month'],
            'schedule_rate_divisor_source' => $metrics['rate_divisor_source'],
            'schedule_derived_daily_rate' => $metrics['derived_daily_rate'],
            'schedule_derived_hourly_rate' => $metrics['derived_hourly_rate'],
        ]);
    }

    /**
     * @param  bool  $includePayCyclePreviewAndLeaveSnapshot  When false, pay-cycle preview is omitted (lighter JSON).
     * @param  bool  $includeLeaveCreditsWhenPayCyclePreviewSkipped  When the pay-cycle block is off, set true to still merge {@see leaveCreditsSnapshotFields()} (admin employee index uses this so list rows match profile / Leave Credits).
     */
    private function employeeResponse(User $user, bool $includeSensitive = true, bool $includeComputed = true, bool $includePayCyclePreviewAndLeaveSnapshot = true, bool $includeLeaveCreditsWhenPayCyclePreviewSkipped = false): array
    {
        $includePayCyclePreview = $includeComputed && $includePayCyclePreviewAndLeaveSnapshot;
        $includeLeaveCreditsSnapshot = $includeComputed && ($includePayCyclePreviewAndLeaveSnapshot || $includeLeaveCreditsWhenPayCyclePreviewSkipped);

        if ($includeComputed) {
            // `refresh()` clears already-loaded relations, so we must rehydrate org relations
            // to keep list rows consistent (dept / branch / company / HR role) with profile views.
            $user->loadMissing([
                'companyHeadships:id,name,company_head_id',
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
        } else {
            $user->loadMissing([
                'companyHeadships:id,name,company_head_id',
                'company:id,name',
                'branch:id,name,company_id',
                'branch.company:id,name',
                'managedBranch:id,name,company_id,branch_manager_id',
                'managedDepartment:id,name,branch_id,department_head_id',
                'departmentRelation:id,name,branch_id,department_head_id',
                'departmentRelation.branch:id,name,company_id',
                'departmentRelation.branch.company:id,name',
                'supervisor:id,name',
                'team:id,name',
                'workingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
                'pendingWorkingSchedule:id,name,time_in,time_out,break_start,break_end,grace_period_minutes,rest_days',
            ]);
        }

        $monthlyBaseForSchedule = ($includeSensitive && $includeComputed)
            ? (float) ($user->monthly_salary ?? $user->monthly_rate ?? 0)
            : 0.0;
        $scheduleRates = $includeComputed
            ? $this->scheduleRateService->describeForUser(
                $user,
                $includeSensitive && $monthlyBaseForSchedule > 0 ? $monthlyBaseForSchedule : null
            )
            : [
                'working_days_per_week' => 0,
                'working_days_per_month' => 0,
                'working_hours_per_day' => 0,
                'working_days_in_calendar_month' => 0,
                'rate_divisor_source' => null,
                'derived_daily_rate' => null,
                'derived_hourly_rate' => null,
            ];

        $managementRole = ManagementRole::resolve($user);
        // Company heads are not scoped to a single branch; avoid inferring branch from department row.
        $branchNameForProfile = $managementRole === 'company_head'
            ? $user->branch?->name
            : ($user->branch?->name ?? $user->departmentRelation?->branch?->name);

        [$firstNameFallback, $middleNameFallback, $lastNameFallback] = $this->splitNameParts($user->name);

        $hr = $this->hrRoleResolver->resolve($user);

        $row = [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'first_name' => $user->first_name ?: $firstNameFallback,
            'middle_name' => $user->middle_name ?: $middleNameFallback,
            'last_name' => $user->last_name ?: $lastNameFallback,
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
            'hr_role_label' => $hr->badgeLabel(),
            'has_qr' => ! empty($user->qr_token),
            'has_face' => $this->userHasFace($user),
            'face_status' => $user->face_status ?? ($this->userHasFace($user) ? 'registered' : 'not_registered'),
            'face_liveness_type' => $user->face_liveness_type,
            'face_registered_at' => $user->face_registered_at?->toIso8601String(),
            'qr_token_generated_at' => $user->qr_token_generated_at?->toIso8601String(),
            'department' => $user->departmentRelation?->name ?? $user->department,
            'department_id' => $user->department_id,
            'company_id' => $user->company_id,
            'company_name' => ($user->companyHeadships->first() ?? $user->company ?? $user->branch?->company ?? $user->departmentRelation?->branch?->company)?->name,
            'branch_id' => $user->branch_id,
            'branch_name' => $branchNameForProfile,
            'managed_branch_id' => $user->managedBranch?->id,
            'managed_branch_name' => $user->managedBranch?->name,
            'management_role' => $managementRole,
            'team_id' => $user->team_id,
            'team_name' => $user->team?->name,
            'position' => $user->position,
            'branch_office_location' => $user->branch_office_location,
            'employment_type' => $user->employment_type,
            'employment_status' => $user->employment_status,
            'employment_status_label' => EmploymentStatus::normalizeToCanonicalLabel($user->employment_status)
                ?? ($user->employment_status
                    ? ucfirst(str_replace('_', ' ', (string) $user->employment_status))
                    : null),
            'employment_status_effective_date' => $user->employment_status_effective_date?->toDateString(),
            'hire_date' => $user->hire_date?->toDateString(),
            'contract_start_date' => $user->contract_start_date?->toDateString(),
            'contract_end_date' => $user->contract_end_date?->toDateString(),
            'supervisor_id' => $user->supervisor_id,
            'supervisor_name' => $user->supervisor?->name,
            'pay_cycle_id' => $user->pay_cycle_id,
            'pay_cycle_preview' => $includePayCyclePreview ? $this->payCycleService->previewForUser($user) : null,
            'pay_cycle_inherited_from_company' => $includePayCyclePreview ? $this->payCycleService->isPayCycleInheritedFromCompany($user) : false,
            'schedule' => $user->schedule,
            'working_schedule_id' => $user->working_schedule_id,
            'working_schedule_name' => $user->workingSchedule?->name,
            'working_schedule_time' => $user->workingSchedule
                ? ($user->workingSchedule->time_in.' – '.$user->workingSchedule->time_out)
                : null,
            'working_schedule_rest_days' => $user->workingSchedule?->rest_days,
            'working_schedule_rest_days_label' => LeaveScheduleSupport::formatRestDaysLabels($user->workingSchedule?->rest_days ?? []),
            'working_schedule_break_start' => $user->workingSchedule?->break_start,
            'working_schedule_break_end' => $user->workingSchedule?->break_end,
            'working_schedule_grace_minutes' => $user->workingSchedule?->grace_period_minutes,
            'schedule_working_days_per_week' => $scheduleRates['working_days_per_week'],
            'schedule_working_days_per_month' => $scheduleRates['working_days_per_month'],
            'schedule_working_hours_per_day' => $scheduleRates['working_hours_per_day'],
            'schedule_working_days_in_calendar_month' => $scheduleRates['working_days_in_calendar_month'],
            'schedule_rate_divisor_source' => $scheduleRates['rate_divisor_source'],
            'schedule_derived_daily_rate' => $scheduleRates['derived_daily_rate'],
            'schedule_derived_hourly_rate' => $scheduleRates['derived_hourly_rate'],
            'pending_working_schedule_id' => $user->pending_working_schedule_id,
            'pending_schedule_effective_from' => $user->pending_schedule_effective_from?->toDateString(),
            'pending_working_schedule_name' => $user->pendingWorkingSchedule?->name,
            'pending_working_schedule_time' => $user->pendingWorkingSchedule
                ? ($user->pendingWorkingSchedule->time_in.' – '.$user->pendingWorkingSchedule->time_out)
                : null,
            'monthly_salary' => $includeSensitive && $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'daily_rate' => $includeSensitive && $user->daily_rate ? (float) $user->daily_rate : null,
            'monthly_rate' => $includeSensitive && $user->monthly_rate ? (float) $user->monthly_rate : null,
            'hourly_rate' => $includeSensitive && $user->hourly_rate !== null ? (string) $user->hourly_rate : null,
            'salary_effectivity_date' => $includeSensitive ? $user->salary_effectivity_date?->toDateString() : null,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'profile_image' => $user->profile_image_url,
            'signature_image' => $user->signature_image_url,
            'signature_signed_at' => $user->signature_signed_at?->toIso8601String(),
            ...($includeLeaveCreditsSnapshot ? $this->leaveCreditsSnapshotFields($user) : [
                'leave_credits' => null,
                'leave_credits_annual_allocation' => null,
                'leave_credits_reset_date' => null,
                'leave_credits_last_recharged_display' => null,
                'leave_credits_recharge_policy' => null,
                'leave_credits_eligible_for_paid_pool' => null,
                'leave_credits_probationary' => null,
                'leave_credits_has_one_year_service' => null,
                'leave_credits_display' => null,
                'leave_credits_status_summary' => null,
                'leave_credits_unpaid_notice' => null,
                'leave_credits_warning' => null,
                'leave_credits_effective_available' => null,
                'leave_credits_pending_reserved_days' => null,
                'leave_credits_is_regular_employment' => null,
                'leave_credits_service_anchor_date' => null,
            ]),
        ];

        return $row;
    }

    /**
     * True when the key exists on the request (including explicit JSON null). {@see Request::has()}
     * returns false for null/empty values, so admin PATCH bodies that send null to clear fields were skipped.
     */
    private function requestHasInput(Request $request, string $key): bool
    {
        return array_key_exists($key, $request->all());
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

    /**
     * Force phone values to text in spreadsheet apps (avoid scientific notation).
     */
    private function csvPhone(mixed $value): string
    {
        $phone = trim((string) ($value ?? ''));
        if ($phone === '') {
            return '';
        }

        // Leading apostrophe keeps CSV import as text in Excel/Sheets.
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
     * Simple case-insensitive name matcher for canonical allowance component labels.
     *
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

    private function canEditSalaryViaProfile(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->rbacService->can($user, 'employees.sensitive')
            && $this->rbacService->can($user, 'profile.salary.edit');
    }

    /** Salary tab monetary fields (not work schedule — that may be edited under Employment with `employees.sensitive`). */
    private function requestHasSalaryMoneyInput(Request $request): bool
    {
        foreach (['monthly_salary', 'salary_effectivity_date', 'monthly_rate', 'hourly_rate', 'daily_rate'] as $key) {
            if ($this->requestHasInput($request, $key)) {
                return true;
            }
        }

        return false;
    }

    private function applyScheduleDerivedRatesFromMonthlySalary(User $employee): void
    {
        $monthlySalary = $employee->monthly_salary !== null ? (float) $employee->monthly_salary : null;
        $computedRates = $this->scheduleRateService->calculateDailyAndHourlyRate((int) $employee->id, $monthlySalary);

        $employee->daily_rate = $computedRates['daily_rate'];
        $employee->hourly_rate = $computedRates['hourly_rate'];
    }

    /** @var list<string> */
    private const STRUCTURED_ADDRESS_KEYS = ['street_address', 'barangay', 'city', 'province', 'postal_code'];

    private function structuredAddressHasFilledPart(Request $request): bool
    {
        foreach (self::STRUCTURED_ADDRESS_KEYS as $k) {
            if ($request->filled($k)) {
                return true;
            }
        }

        return false;
    }

    private function requestHasAnyStructuredAddressInput(Request $request): bool
    {
        foreach (self::STRUCTURED_ADDRESS_KEYS as $k) {
            if ($this->requestHasInput($request, $k)) {
                return true;
            }
        }

        return false;
    }

    private function nullableTrimRequestValue(Request $request, string $key): ?string
    {
        if (! $this->requestHasInput($request, $key)) {
            return null;
        }

        $raw = $request->input($key);
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Join non-empty parts with ", " — same as frontend {@see composeHomeAddress} in AdminEmployeeProfile.jsx.
     */
    private function composeStructuredHomeAddressFromRequest(Request $request): ?string
    {
        $parts = [];
        foreach (self::STRUCTURED_ADDRESS_KEYS as $k) {
            $parts[] = trim((string) $request->input($k, ''));
        }
        $parts = array_values(array_filter($parts, fn (string $s) => $s !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function arrayHasFilledStructuredAddress(array $data): bool
    {
        foreach (self::STRUCTURED_ADDRESS_KEYS as $k) {
            if (! empty(trim((string) ($data[$k] ?? '')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function composeStructuredHomeAddressFromArray(array $data): ?string
    {
        $parts = [];
        foreach (self::STRUCTURED_ADDRESS_KEYS as $k) {
            $parts[] = trim((string) ($data[$k] ?? ''));
        }
        $parts = array_values(array_filter($parts, fn (string $s) => $s !== ''));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Prefer structured fields when any part is non-empty; else full_address; else home_address.
     * Users table stores a single {@see User::$home_address} text column (payslips / tax context use this).
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveHomeAddressForEmployeeCreate(array $validated): ?string
    {
        if ($this->arrayHasFilledStructuredAddress($validated)) {
            return $this->composeStructuredHomeAddressFromArray($validated);
        }
        if (! empty($validated['full_address'] ?? null)) {
            $t = trim((string) $validated['full_address']);

            return $t !== '' ? $t : null;
        }
        if (! empty($validated['home_address'] ?? null)) {
            $t = trim((string) $validated['home_address']);

            return $t !== '' ? $t : null;
        }

        return null;
    }

    private function applyHomeAddressFromAdminRequest(Request $request, User $employee): void
    {
        $hasStructuredAddressInput = $this->requestHasAnyStructuredAddressInput($request);

        if ($hasStructuredAddressInput) {
            foreach (self::STRUCTURED_ADDRESS_KEYS as $key) {
                $employee->{$key} = $this->nullableTrimRequestValue($request, $key);
            }
        }

        if ($this->structuredAddressHasFilledPart($request)) {
            $composed = $this->composeStructuredHomeAddressFromRequest($request);
            $employee->home_address = $composed;
            $employee->full_address = $composed;

            return;
        }

        if ($this->requestHasInput($request, 'full_address')) {
            $f = $request->input('full_address');
            $normalized = is_string($f) && trim($f) !== '' ? trim($f) : null;
            $employee->full_address = $normalized;
            $employee->home_address = $normalized;

            if ($hasStructuredAddressInput) {
                return;
            }

            return;
        }

        if ($this->requestHasInput($request, 'home_address')) {
            $addressRaw = $request->input('home_address');
            $normalized = is_string($addressRaw) && trim($addressRaw) !== '' ? trim($addressRaw) : null;
            $employee->home_address = $normalized;
            // Keep canonical full_address aligned for payroll/payslip profile payloads.
            $employee->full_address = $normalized;
        }
    }

    private function buildLiteOrgMaps(Collection $users): array
    {
        $companyIds = $users->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $branchIds = $users->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $departmentIds = $users->pluck('department_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $companiesById = $companyIds->isEmpty()
            ? collect()
            : Company::query()->whereIn('id', $companyIds)->pluck('name', 'id');
        $branchesById = $branchIds->isEmpty()
            ? collect()
            : Branch::query()->whereIn('id', $branchIds)->get(['id', 'name', 'company_id'])->keyBy('id');
        $departmentsById = $departmentIds->isEmpty()
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'name', 'branch_id'])->keyBy('id');

        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $headRoles = ['company' => [], 'branch' => [], 'dept' => []];
        $managedBranchByUser = [];
        if ($userIds !== []) {
            foreach (Company::query()->whereIn('company_head_id', $userIds)->get(['company_head_id']) as $c) {
                if ($c->company_head_id) {
                    $headRoles['company'][(int) $c->company_head_id] = true;
                }
            }
            foreach (Branch::query()->whereIn('branch_manager_id', $userIds)->get(['id', 'name', 'branch_manager_id']) as $b) {
                if ($b->branch_manager_id) {
                    $uid = (int) $b->branch_manager_id;
                    $headRoles['branch'][$uid] = true;
                    $managedBranchByUser[$uid] = ['id' => (int) $b->id, 'name' => (string) $b->name];
                }
            }
            foreach (Department::query()->whereIn('department_head_id', $userIds)->pluck('department_head_id') as $hid) {
                if ($hid) {
                    $headRoles['dept'][(int) $hid] = true;
                }
            }
        }

        return [
            'companies' => $companiesById,
            'branches' => $branchesById,
            'departments' => $departmentsById,
            'head_roles' => $headRoles,
            'managed_branch_by_user' => $managedBranchByUser,
        ];
    }

    /**
     * Match {@see HrRoleResolver::resolveOrgHierarchyFromAssignments()} priority without N+1 queries per row.
     *
     * @param  array{company: array<int, bool>, branch: array<int, bool>, dept: array<int, bool>}  $headRoles
     */
    private function resolveHrRoleFromLiteHeadMaps(int $userId, array $headRoles): HrRole
    {
        if (! empty($headRoles['company'][$userId])) {
            return HrRole::CompanyHead;
        }
        if (! empty($headRoles['branch'][$userId])) {
            return HrRole::BranchHead;
        }
        if (! empty($headRoles['dept'][$userId])) {
            return HrRole::DepartmentHead;
        }

        return HrRole::Employee;
    }

    private function managementRoleFromHrRole(HrRole $hr): ?string
    {
        return match ($hr) {
            HrRole::CompanyHead => 'company_head',
            HrRole::BranchHead => 'branch_head',
            HrRole::DepartmentHead => 'department_head',
            default => null,
        };
    }

    private function employeeLiteResponse(User $user, bool $includeSensitive, array $orgMaps): array
    {
        $branchesById = $orgMaps['branches'] ?? collect();
        $departmentsById = $orgMaps['departments'] ?? collect();
        $companiesById = $orgMaps['companies'] ?? collect();

        $department = $user->department;
        $departmentBranchId = null;
        if ($user->department_id) {
            $dept = $departmentsById->get((int) $user->department_id);
            if ($dept) {
                $department = $dept->name ?: $department;
                $departmentBranchId = (int) $dept->branch_id;
            }
        }

        $branchName = null;
        $companyIdResolved = $user->company_id ? (int) $user->company_id : null;
        if ($user->branch_id) {
            $branch = $branchesById->get((int) $user->branch_id);
            if ($branch) {
                $branchName = $branch->name;
                $companyIdResolved = $companyIdResolved ?: (int) $branch->company_id;
            }
        }
        if (! $branchName && $departmentBranchId) {
            $branch = $branchesById->get((int) $departmentBranchId);
            if ($branch) {
                $branchName = $branch->name;
                $companyIdResolved = $companyIdResolved ?: (int) $branch->company_id;
            }
        }
        $companyName = $companyIdResolved ? ($companiesById->get((int) $companyIdResolved) ?? null) : null;

        $hasFace = ($user->face_status === 'registered') || ($user->face_registered_at !== null);

        $uid = (int) $user->id;
        $headRoles = $orgMaps['head_roles'] ?? ['company' => [], 'branch' => [], 'dept' => []];
        $hr = $this->resolveHrRoleFromLiteHeadMaps($uid, is_array($headRoles) ? $headRoles : ['company' => [], 'branch' => [], 'dept' => []]);
        $managedBy = $orgMaps['managed_branch_by_user'] ?? [];
        $managedBranch = is_array($managedBy) ? ($managedBy[$uid] ?? null) : null;

        return [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'role' => $user->role,
            'hr_role' => $hr->value,
            'hr_role_label' => $hr->badgeLabel(),
            'management_role' => $this->managementRoleFromHrRole($hr),
            'managed_branch_id' => $managedBranch['id'] ?? null,
            'managed_branch_name' => $managedBranch['name'] ?? null,
            'department' => $department,
            'department_id' => $user->department_id,
            'company_id' => $companyIdResolved,
            'company_name' => $companyName,
            'branch_id' => $user->branch_id,
            'branch_name' => $branchName,
            'position' => $user->position,
            'employment_status' => $user->employment_status,
            'employment_status_label' => EmploymentStatus::normalizeToCanonicalLabel($user->employment_status)
                ?? ($user->employment_status ? ucfirst(str_replace('_', ' ', (string) $user->employment_status)) : null),
            'employment_status_effective_date' => $user->employment_status_effective_date?->toDateString(),
            'hire_date' => $user->hire_date?->toDateString(),
            'schedule' => $user->schedule,
            'working_schedule_id' => $user->working_schedule_id,
            'has_qr' => ! empty($user->qr_token),
            'has_face' => $hasFace,
            'face_status' => $user->face_status ?? ($hasFace ? 'registered' : 'not_registered'),
            'face_liveness_type' => $user->face_liveness_type,
            'face_registered_at' => $user->face_registered_at?->toIso8601String(),
            'qr_token_generated_at' => $user->qr_token_generated_at?->toIso8601String(),
            'monthly_salary' => $includeSensitive && $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'daily_rate' => $includeSensitive && $user->daily_rate ? (float) $user->daily_rate : null,
            'monthly_rate' => $includeSensitive && $user->monthly_rate ? (float) $user->monthly_rate : null,
            'hourly_rate' => $includeSensitive && $user->hourly_rate !== null ? (string) $user->hourly_rate : null,
            'salary_effectivity_date' => $includeSensitive ? $user->salary_effectivity_date?->toDateString() : null,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'profile_image' => $user->profile_image_url,
            ...$this->leaveCreditsSnapshotFields($user),
        ];
    }

    /**
     * Leave credits for list/detail JSON — same pipeline as Employee Profile (`buildLeaveCreditsApiPayload`).
     * Runs annual recharge + DB refresh so `users.leave_credits` is current; eligibility uses
     * {@see LeaveCreditService::eligibleForPaidLeavePool()} (Regular via {@see EmploymentStatus::tryFromStored()},
     * one full year from `employment_status_effective_date`).
     *
     * @return array<string, mixed>
     */
    private function leaveCreditsSnapshotFields(User $user): array
    {
        $this->leaveCreditService->ensureAnnualRechargeForUserId((int) $user->id);
        $user->refresh();
        $lc = $this->leaveCreditService->buildLeaveCreditsApiPayload($user);

        return [
            'leave_credits' => $lc['remaining'],
            'leave_credits_annual_allocation' => $lc['annual_allocation'],
            'leave_credits_reset_date' => $lc['reset_date'],
            'leave_credits_last_recharged_display' => $lc['last_recharged_display'],
            'leave_credits_recharge_policy' => $lc['recharge_policy'],
            'leave_credits_eligible_for_paid_pool' => $lc['eligible_for_paid_leave_pool'],
            'leave_credits_probationary' => $lc['probationary'],
            'leave_credits_has_one_year_service' => $lc['has_one_year_of_service'],
            'leave_credits_display' => $lc['display'],
            'leave_credits_status_summary' => $lc['status_summary'] ?? null,
            'leave_credits_unpaid_notice' => $lc['unpaid_leave_notice'] ?? null,
            'leave_credits_warning' => $lc['warning'],
            'leave_credits_effective_available' => $lc['effective_available'],
            'leave_credits_pending_reserved_days' => $lc['pending_reserved_days'],
            'leave_credits_is_regular_employment' => $lc['is_regular_employment'] ?? null,
            'leave_credits_service_anchor_date' => $lc['service_anchor_date'] ?? null,
        ];
    }

    private function composeFullName(string $firstName, ?string $middleName, string $lastName): string
    {
        $parts = [
            trim($firstName),
            is_string($middleName) ? trim($middleName) : '',
            trim($lastName),
        ];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        return trim(implode(' ', $parts));
    }

    private function generateEmployeeCode(int $id): string
    {
        return sprintf('EMP-%06d', $id);
    }

    private function isManagerialPosition(?string $position): bool
    {
        if (! is_string($position) || trim($position) === '') {
            return false;
        }
        $p = strtolower($position);

        return str_contains($p, 'manager') || str_contains($p, 'supervisor') || str_contains($p, 'lead') || str_contains($p, 'head');
    }

    /**
     * Backward-compatible fallback for legacy records that only stored full name.
     *
     * @return array{0:string,1:?string,2:string}
     */
    private function splitNameParts(?string $fullName): array
    {
        $name = is_string($fullName) ? trim($fullName) : '';
        if ($name === '') {
            return ['', null, ''];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null, ''];
        }
        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }
        $first = array_shift($parts) ?: '';
        $last = array_pop($parts) ?: '';
        $middle = implode(' ', $parts);

        return [$first, $middle !== '' ? $middle : null, $last];
    }

    private function companyLogoUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = ltrim(trim($path), '/');
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, 7), '/');
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $normalized)));

        return url('/api/media/public/'.$encoded);
    }

    private function userHasFace(User $user): bool
    {
        return $user->hasRegisteredFace();
    }
}
