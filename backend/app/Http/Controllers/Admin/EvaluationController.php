<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\HrRole;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\HrisNotification;
use App\Models\User;
use App\Models\Branch;
use App\Services\DataScopeService;
use App\Services\EvaluationAssignmentService;
use App\Services\EvaluationEvaluatorResolver;
use App\Services\EvaluationPrefillService;
use App\Services\EvaluationScoringService;
use App\Services\HrRoleResolver;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly EvaluationScoringService $evaluationScoringService,
        private readonly EvaluationPrefillService $evaluationPrefillService,
        private readonly EvaluationAssignmentService $assignmentService,
        private readonly EvaluationEvaluatorResolver $evaluatorResolver,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly RbacService $rbacService,
    ) {}

    // ─── Scope Meta ───────────────────────────────────────────────

    public function scopeMeta(Request $request): JsonResponse
    {
        return response()->json($this->buildScopeMeta($request->user()));
    }

    /**
     * Consolidated payload for the evaluation module's initial load. Computes the
     * (expensive) data scope a single time and reuses it across every dataset,
     * collapsing what used to be 5 parallel requests into one round-trip.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        $scopeMeta = $this->buildScopeMeta($user);
        $scopedEmployeeIds = $scopeMeta['scoped_employee_ids'];

        $forms = ($scopeMeta['can_manage_templates'] || $scopeMeta['can_evaluate'])
            ? $this->buildFormsPayload($user, false)
            : [];

        $companiesQuery = Company::query()->orderBy('name');
        $this->dataScopeService->restrictCompanyQuery($user, $companiesQuery);
        $companies = $companiesQuery->get(['id', 'name']);

        // Initial employee set for the module. For scoped org heads this is their
        // fixed roster; for unrestricted Admin HR it is the full evaluatable roster
        // (matching a company-less employees fetch). Company-specific narrowing
        // still happens client-side via the lazy /employees endpoint.
        $employees = $this->buildEmployeesPayload($scopedEmployeeIds, null);

        return response()->json([
            'scope_meta' => $scopeMeta,
            'forms' => $forms,
            'companies' => $companies,
            'employees' => $employees,
            'evaluations' => $this->buildEvaluationsQuery($scopedEmployeeIds)->paginate(50),
            'assignments' => $this->assignmentService
                ->buildAssignmentsQuery($scopedEmployeeIds)
                ->paginate(50),
            'my_pending_evaluations' => $this->assignmentService->pendingForEvaluator($user),
            'dashboard' => $scopeMeta['can_view_dashboard']
                ? $this->buildDashboardSummary($scopedEmployeeIds)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScopeMeta(User $user): array
    {
        $permissions = $this->rbacService->getPermissionsForUser($user);
        $permSet = $permissions->map(fn ($p) => $p['slug'] ?? $p)->values()->all();

        $isAdmin = $user->isAdmin();
        $hrRole = $this->hrRoleResolver->resolve($user);

        $canManageTemplates = $isAdmin || $hrRole === HrRole::AdminHr || in_array('evaluations.templates.manage', $permSet, true);
        // Template managers (HR) always assign — avoids missing tab when evaluations.assign isn't seeded yet.
        $canAssign = $canManageTemplates || in_array('evaluations.assign', $permSet, true);
        $canEvaluate = $isAdmin || $hrRole === HrRole::AdminHr || in_array('evaluations.create', $permSet, true);
        $canReview = $isAdmin || $hrRole === HrRole::AdminHr || in_array('evaluations.review', $permSet, true);

        return [
            'can_manage_templates' => $canManageTemplates,
            'can_assign' => $canAssign,
            'can_evaluate' => $canEvaluate,
            'can_review' => $canReview,
            'can_view_dashboard' => $isAdmin,
            'evaluator_role_types' => EvaluationEvaluatorResolver::ROLE_TYPES,
            'hr_role' => $hrRole->value,
            'hr_role_label' => $hrRole->badgeLabel(),
            'scope' => $this->resolveScopeKind($user),
            'scoped_employee_ids' => $this->getEvaluationScopeEmployeeIds($user),
        ];
    }

    private function getEvaluationScopeEmployeeIds(User $user): ?array
    {
        $hrRole = $this->hrRoleResolver->resolve($user);

        if ($hrRole === HrRole::AdminHr) {
            return null;
        }

        // All roster employees participate in evaluations (self, peer, assigned evaluator).
        if ($hrRole === HrRole::Employee) {
            return [(int) $user->id];
        }

        $ids = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($user);
        if ($ids === null) {
            return null;
        }

        return array_values(array_filter($ids, fn ($id) => (int) $id !== (int) $user->id));
    }

    private function resolveScopeKind(User $user): ?array
    {
        $hrRole = $this->hrRoleResolver->resolve($user);

        if ($hrRole === HrRole::AdminHr) {
            return [
                'kind' => 'all',
            ];
        }

        return $this->dataScopeService->getAttendanceScopeMeta($user);
    }

    // ─── Evaluation Forms ───────────────────────────────────────────

    public function formsIndex(Request $request): JsonResponse
    {
        return response()->json([
            'forms' => $this->buildFormsPayload($request->user(), $request->boolean('active_only')),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFormsPayload(User $user, bool $activeOnly): array
    {
        $query = EvaluationForm::query()
            ->with('createdBy:id,name,first_name,middle_name,last_name')
            ->withCount('evaluations')
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            $hrRole = $this->hrRoleResolver->resolve($user);
            if ($hrRole !== HrRole::AdminHr) {
                $query->where('is_active', true);
                $this->restrictFormsByScope($user, $query);
            }
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->map(fn (EvaluationForm $f) => [
            'id' => $f->id,
            'company_id' => $f->company_id,
            'title' => $f->title,
            'description' => $f->description,
            'sections' => $f->sections,
            'survey_json' => $f->survey_json,
            'is_active' => $f->is_active,
            'organization_scope' => $f->organization_scope,
            'created_by' => $f->createdBy?->display_name,
            'evaluations_count' => $f->evaluations_count,
            'created_at' => $f->created_at?->toIso8601String(),
            'updated_at' => $f->updated_at?->toIso8601String(),
        ])->all();
    }

    private function restrictFormsByScope(User $user, $query): void
    {
        $hrRole = $this->hrRoleResolver->resolve($user);

        $orgIds = match ($hrRole) {
            HrRole::CompanyHead => ['company_ids' => $this->dataScopeService->getCompanyScopeIds($user)],
            HrRole::AreaHead => ['area_ids' => $this->dataScopeService->getAreaScopeIds($user)],
            HrRole::BranchHead => ['branch_ids' => $this->dataScopeService->getBranchScopeIds($user)],
            HrRole::DivisionHead => ['division_ids' => $this->dataScopeService->getDivisionScopeIds($user)],
            HrRole::DepartmentHead => ['department_ids' => $this->dataScopeService->getDepartmentScopeIds($user)],
            HrRole::SectionUnitHead => ['section_unit_ids' => $this->dataScopeService->getSectionUnitScopeIds($user)],
            default => null,
        };

        if ($orgIds === null) {
            return;
        }

        $query->where(function ($q) use ($orgIds) {
            $q->whereNull('organization_scope');
            foreach ($orgIds as $key => $ids) {
                foreach ($ids as $id) {
                    $q->orWhereJsonContains('organization_scope->' . $key, $id);
                }
            }
        });
    }

    public function formsStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sections' => ['sometimes', 'array'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.weight' => ['required_with:sections', 'numeric', 'min:0', 'max:100'],
            'sections.*.questions' => ['required_with:sections', 'array'],
            'sections.*.questions.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.questions.*.type' => ['required_with:sections', 'string', 'in:rating,text'],
            'sections.*.questions.*.max' => ['required_if:sections.*.questions.*.type,rating', 'integer', 'min:1', 'max:100'],
            'survey_json' => ['nullable', 'array'],
            'organization_scope' => ['nullable', 'array'],
        ]);

        if (array_key_exists('survey_json', $validated) && is_array($validated['survey_json'])) {
            $validated['survey_json'] = $this->evaluationScoringService->applyWeightedSummaryExpressions($validated['survey_json']);
        }

        $form = EvaluationForm::create([
            'company_id' => $validated['company_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sections' => $validated['sections'] ?? null,
            'survey_json' => $validated['survey_json'] ?? null,
            'organization_scope' => $validated['organization_scope'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Evaluation form created successfully.',
            'form' => $form->fresh('createdBy:id,name,first_name,middle_name,last_name'),
        ], 201);
    }

    public function formsShow(int $id): JsonResponse
    {
        $form = EvaluationForm::with('createdBy:id,name,first_name,middle_name,last_name')
            ->withCount('evaluations')
            ->findOrFail($id);

        return response()->json(['form' => $form]);
    }

    public function formsUpdate(Request $request, int $id): JsonResponse
    {
        $form = EvaluationForm::findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sections' => ['sometimes', 'array'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.weight' => ['required_with:sections', 'numeric', 'min:0', 'max:100'],
            'sections.*.questions' => ['required_with:sections', 'array'],
            'sections.*.questions.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.questions.*.type' => ['required_with:sections', 'string', 'in:rating,text'],
            'sections.*.questions.*.max' => ['required_if:sections.*.questions.*.type,rating', 'integer', 'min:1', 'max:100'],
            'survey_json' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'organization_scope' => ['nullable', 'array'],
        ]);

        if (array_key_exists('survey_json', $validated) && is_array($validated['survey_json'])) {
            $validated['survey_json'] = $this->evaluationScoringService->applyWeightedSummaryExpressions($validated['survey_json']);
        }

        $form->update($validated);

        return response()->json([
            'message' => 'Evaluation form updated successfully.',
            'form' => $form->fresh('createdBy:id,name,first_name,middle_name,last_name'),
        ]);
    }

    public function formsDestroy(int $id): JsonResponse
    {
        $form = EvaluationForm::findOrFail($id);

        if ($form->evaluations()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this form because it has existing evaluations.',
            ], 422);
        }

        $form->delete();

        return response()->json(['message' => 'Evaluation form deleted successfully.']);
    }

    // ─── Companies & Employees (for select dropdowns) ───────────────

    public function companies(Request $request): JsonResponse
    {
        $query = Company::query()->orderBy('name');

        $this->dataScopeService->restrictCompanyQuery($request->user(), $query);

        return response()->json([
            'companies' => $query->get(['id', 'name']),
        ]);
    }

    public function employees(Request $request): JsonResponse
    {
        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($request->user());
        $companyId = $request->filled('company_id') ? $request->integer('company_id') : null;

        return response()->json([
            'employees' => $this->buildEmployeesPayload($scopedEmployeeIds, $companyId),
        ]);
    }

    /**
     * @param  list<int>|null  $scopedEmployeeIds
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function buildEmployeesPayload(?array $scopedEmployeeIds, ?int $companyId)
    {
        $query = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->with([
                'departmentRelation:id,name',
                'branch:id,name',
                'company:id,name',
            ])
            ->select([
                'id', 'first_name', 'middle_name', 'last_name', 'suffix',
                'profile_image', 'position', 'employee_code',
                'department_id', 'branch_id', 'company_id',
            ]);

        if ($scopedEmployeeIds !== null) {
            // Scoped IDs already define the exact evaluatable set (including cross-company
            // "shared" assignments), so the company_id filter must not narrow it further.
            $query->whereIn('id', $scopedEmployeeIds);
        } elseif ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->get()->map(function (User $employee) {
            $employee->setAttribute('department_name', $employee->departmentRelation?->name);
            $employee->setAttribute('branch_name', $employee->branch?->name);
            $employee->setAttribute('company_name', $employee->company?->name);

            return $employee;
        });
    }

    // ─── Evaluations CRUD ───────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($request->user());
        $query = $this->buildEvaluationsQuery($scopedEmployeeIds);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('evaluation_form_id')) {
            $query->where('evaluation_form_id', $request->integer('evaluation_form_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = min($request->integer('per_page', 25), 100);

        return response()->json($query->paginate($perPage));
    }

    /**
     * @param  list<int>|null  $scopedEmployeeIds
     */
    private function buildEvaluationsQuery(?array $scopedEmployeeIds): \Illuminate\Database\Eloquent\Builder
    {
        $query = Evaluation::query()
            ->with([
                'evaluationForm:id,title,survey_json',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'reviewer:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ])
            ->orderBy('created_at', 'desc');

        if ($scopedEmployeeIds !== null) {
            $query->whereIn('employee_id', $scopedEmployeeIds);
        }

        return $query;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'evaluation_form_id' => ['required', 'integer', 'exists:evaluation_forms,id'],
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'scores' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'string', 'in:draft,submitted'],
        ]);

        $user = $request->user();

        $employee = User::findOrFail($validated['employee_id']);
        $form = EvaluationForm::findOrFail($validated['evaluation_form_id']);

        $scopedIds = $this->getEvaluationScopeEmployeeIds($user);
        if ($scopedIds !== null && !in_array((int) $employee->id, $scopedIds, true)) {
            return response()->json(['message' => 'You are not authorized to evaluate this employee.'], 403);
        }

        $alreadyEvaluated = Evaluation::query()
            ->where('employee_id', $validated['employee_id'])
            ->whereNull('evaluation_assignment_id')
            ->whereIn('status', ['submitted', 'under_review', 'completed'])
            ->exists();
        if ($alreadyEvaluated) {
            return response()->json([
                'message' => 'This employee has already been evaluated and cannot be evaluated again.',
            ], 422);
        }

        $branchId = null;
        $departmentId = null;
        if ($employee) {
            $assignment = $employee->organizationAssignments()->first();
            if ($assignment) {
                $branchId = $assignment->branch_id;
                $departmentId = $assignment->department_id;
            }
        }

        $hrRole = $this->hrRoleResolver->resolve($user)?->value;
        $scores = $this->evaluationPrefillService->mergePrefill(
            $validated['scores'] ?? [],
            $form->survey_json,
            $employee,
            $user,
            $hrRole,
        );

        $submitNow = ($validated['status'] ?? 'draft') === 'submitted';
        $status = $submitNow ? Evaluation::STATUS_COMPLETED : Evaluation::STATUS_DRAFT;

        $evaluation = Evaluation::create([
            'company_id' => $validated['company_id'],
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'evaluation_form_id' => $validated['evaluation_form_id'],
            'employee_id' => $validated['employee_id'],
            'evaluator_id' => $user->id,
            'scores' => $scores,
            'remarks' => $validated['remarks'] ?? null,
            'status' => $status,
            'submitted_at' => $submitNow ? now() : null,
            'evaluated_at' => $submitNow ? now() : null,
        ]);

        if ($evaluation->scores && $submitNow) {
            $evaluation->load('evaluationForm');
            $this->computeOverallRating($evaluation);
            $evaluation->save();
        }

        $this->sendNotification($evaluation, 'assigned');

        return response()->json([
            'message' => 'Evaluation created successfully.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ]),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::with([
            'evaluationForm',
            'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
            'evaluator:id,first_name,middle_name,last_name,suffix',
            'reviewer:id,first_name,middle_name,last_name,suffix',
            'company:id,name',
            'branch:id,name',
            'department:id,name',
        ])->findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        return response()->json(['evaluation' => $evaluation]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if ($evaluation->status !== Evaluation::STATUS_DRAFT) {
            return response()->json(['message' => 'Cannot update a submitted evaluation.'], 422);
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string', 'max:10000'],
        ]);

        if (array_key_exists('scores', $validated)) {
            $evaluation->load(['employee', 'evaluator', 'evaluationForm']);
            $hrRole = $this->hrRoleResolver->resolve($request->user())?->value;
            $validated['scores'] = $this->evaluationPrefillService->mergePrefill(
                $validated['scores'],
                $evaluation->evaluationForm?->survey_json,
                $evaluation->employee,
                $evaluation->evaluator ?? $request->user(),
                $hrRole,
            );
        }

        $evaluation->update($validated);

        return response()->json([
            'message' => 'Evaluation updated successfully.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ]),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if ($evaluation->evaluation_assignment_id !== null) {
            return response()->json([
                'message' => 'Assigned evaluations cannot be deleted.',
            ], 422);
        }

        $evaluation->delete();

        return response()->json(['message' => 'Evaluation deleted successfully.']);
    }

    // ─── Workflow Actions ────────────────────────────────────────────

    public function submit(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if ($evaluation->status !== Evaluation::STATUS_DRAFT) {
            return response()->json(['message' => 'Evaluation is already submitted.'], 422);
        }

        if ((int) $evaluation->evaluator_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the assigned evaluator can submit this evaluation.'], 403);
        }

        $evaluation->load('evaluationForm');
        $this->computeOverallRating($evaluation);

        $evaluation->update([
            'status' => Evaluation::STATUS_COMPLETED,
            'submitted_at' => now(),
            'evaluated_at' => now(),
            'overall_score' => $evaluation->overall_score,
            'overall_rating' => $evaluation->overall_rating,
        ]);

        $this->sendNotification($evaluation, 'completed');

        if ($evaluation->evaluation_assignment_id) {
            $assignment = EvaluationAssignment::find($evaluation->evaluation_assignment_id);
            if ($assignment) {
                $this->assignmentService->syncAssignmentStatus($assignment);
            }
        }

        return response()->json([
            'message' => 'Evaluation submitted and completed.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
            ]),
        ]);
    }

    // ─── Assignments ────────────────────────────────────────────────

    public function assignmentsIndex(Request $request): JsonResponse
    {
        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($request->user());
        $query = $this->assignmentService->buildAssignmentsQuery($scopedEmployeeIds);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        $perPage = min($request->integer('per_page', 25), 100);

        return response()->json($query->paginate($perPage));
    }

    /**
     * Given the selected employees (explicit IDs or org filters), return only the
     * hierarchy evaluator levels that resolve to someone in their reporting chains,
     * plus the always-available organizational options (HR, self, custom).
     */
    public function evaluatorPreview(Request $request): JsonResponse
    {
        $user = $request->user();
        $scopeMeta = $this->buildScopeMeta($user);
        if (!$scopeMeta['can_assign']) {
            return response()->json(['message' => 'You are not authorized to assign evaluations.'], 403);
        }

        $validated = $request->validate([
            'employee_ids' => ['sometimes', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'division_id' => ['sometimes', 'integer', 'exists:divisions,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'section_unit_id' => ['sometimes', 'integer', 'exists:section_units,id'],
        ]);

        $employeeIds = $this->resolveTargetEmployeeIds($validated, $user);
        if ($employeeIds === []) {
            return response()->json([
                'hierarchy' => [],
                'special' => array_map(fn (string $role) => [
                    'role' => $role,
                    'label' => $this->evaluatorResolver->roleLabel($role),
                ], EvaluationEvaluatorResolver::SPECIAL_ROLES),
                'employee_count' => 0,
            ]);
        }

        $employees = User::query()
            ->whereIn('id', $employeeIds)
            ->get();

        return response()->json($this->evaluatorResolver->previewForEmployees($employees));
    }

    public function assignmentsStore(Request $request): JsonResponse
    {
        $user = $request->user();
        $scopeMeta = $this->buildScopeMeta($user);
        if (!$scopeMeta['can_assign']) {
            return response()->json(['message' => 'You are not authorized to assign evaluations.'], 403);
        }

        $validated = $request->validate([
            'evaluation_form_id' => ['required', 'integer', 'exists:evaluation_forms,id'],
            'employee_ids' => ['sometimes', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'division_id' => ['sometimes', 'integer', 'exists:divisions,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'section_unit_id' => ['sometimes', 'integer', 'exists:section_units,id'],
            'evaluator_roles' => ['required', 'array', 'min:1'],
            'evaluator_roles.*' => ['string', 'in:' . implode(',', EvaluationEvaluatorResolver::ROLE_TYPES)],
            'custom_evaluator_ids' => ['sometimes', 'array'],
            'custom_evaluator_ids.*' => ['integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reminder_days' => ['sometimes', 'array'],
            'reminder_days.*' => ['integer', 'min:0', 'max:30'],
        ]);

        $employeeIds = $this->resolveTargetEmployeeIds($validated, $user);
        if ($employeeIds === []) {
            return response()->json(['message' => 'No employees matched the selection.'], 422);
        }

        $form = EvaluationForm::query()
            ->where('is_active', true)
            ->findOrFail($validated['evaluation_form_id']);

        $assignments = $this->assignmentService->createBulk(
            $user,
            $form,
            $employeeIds,
            $validated['evaluator_roles'],
            $validated['custom_evaluator_ids'] ?? [],
            $validated['start_date'],
            $validated['end_date'],
            $validated['reminder_days'] ?? null,
        );

        if ($assignments === []) {
            return response()->json([
                'message' => 'No evaluators could be resolved for the selected employees.',
            ], 422);
        }

        return response()->json([
            'message' => count($assignments) . ' evaluation assignment(s) created.',
            'assignments' => $assignments,
        ], 201);
    }

    public function assignmentsShow(Request $request, int $id): JsonResponse
    {
        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($request->user());
        $query = $this->assignmentService->buildAssignmentsQuery($scopedEmployeeIds);
        $assignment = $query->findOrFail($id);

        $progress = $assignment->progressCounts();
        $evaluators = $assignment->evaluations->map(fn (Evaluation $e) => [
            'id' => $e->id,
            'role' => $e->evaluator_role,
            'role_label' => $this->evaluatorResolver->roleLabel((string) $e->evaluator_role),
            'status' => $e->status,
            'evaluator' => $e->evaluator
                ? trim($e->evaluator->first_name . ' ' . $e->evaluator->last_name)
                : null,
            'submitted_at' => $e->submitted_at,
        ]);

        return response()->json([
            'assignment' => $assignment,
            'progress' => $progress,
            'evaluators' => $evaluators,
            'is_overdue' => $assignment->isOverdue(),
        ]);
    }

    public function myPendingEvaluations(Request $request): JsonResponse
    {
        return response()->json([
            'evaluations' => $this->assignmentService->pendingForEvaluator($request->user()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function resolveTargetEmployeeIds(array $validated, User $user): array
    {
        if (!empty($validated['employee_ids'])) {
            $ids = array_map('intval', $validated['employee_ids']);
        } else {
            $query = User::query()->activeRoster();

            if (!empty($validated['company_id'])) {
                $query->where('company_id', (int) $validated['company_id']);
            }
            if (!empty($validated['area_id'])) {
                $branchIds = Branch::query()
                    ->where('area_id', (int) $validated['area_id'])
                    ->pluck('id');
                $query->whereIn('branch_id', $branchIds);
            }
            if (!empty($validated['branch_id'])) {
                $query->where('branch_id', (int) $validated['branch_id']);
            }
            if (!empty($validated['division_id'])) {
                $query->where('division_id', (int) $validated['division_id']);
            }
            if (!empty($validated['department_id'])) {
                $query->where('department_id', (int) $validated['department_id']);
            }
            if (!empty($validated['section_unit_id'])) {
                $query->where('section_unit_id', (int) $validated['section_unit_id']);
            }

            $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $scopedIds = $this->getEvaluationScopeEmployeeIds($user);
        if ($scopedIds !== null) {
            $scopedSet = array_flip($scopedIds);
            $ids = array_values(array_filter($ids, fn (int $id) => isset($scopedSet[$id])));
        }

        return array_values(array_unique($ids));
    }

    // ─── Employee Profile Endpoint ──────────────────────────────────

    public function employeeHistory(Request $request, int $employeeId): JsonResponse
    {
        $user = $request->user();

        $scopedIds = $this->getEvaluationScopeEmployeeIds($user);
        if ($scopedIds !== null && !in_array((int) $employeeId, $scopedIds, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $query = Evaluation::query()
            ->with([
                'evaluationForm:id,title',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'reviewer:id,first_name,middle_name,last_name,suffix',
            ])
            ->where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc');

        $evaluations = $query->get();

        $current = $evaluations->firstWhere(fn (Evaluation $e) => in_array($e->status, [
            Evaluation::STATUS_DRAFT,
            Evaluation::STATUS_SUBMITTED,
            Evaluation::STATUS_UNDER_REVIEW,
        ]));

        $previous = $evaluations->filter(fn (Evaluation $e) => $e->status === Evaluation::STATUS_COMPLETED);

        return response()->json([
            'evaluations' => $evaluations,
            'current_evaluation' => $current,
            'previous_ratings' => $previous->pluck('overall_score'),
            'overall_score' => $current?->overall_score,
            'overall_rating' => $current?->overall_rating,
        ]);
    }

    // ─── Dashboard Endpoints ────────────────────────────────────────

    public function dashboardSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Forbidden. Admin access required.'], 403);
        }

        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($user);

        return response()->json($this->buildDashboardSummary($scopedEmployeeIds));
    }

    /**
     * @param  list<int>|null  $scopedEmployeeIds
     * @return array<string, mixed>
     */
    private function buildDashboardSummary(?array $scopedEmployeeIds): array
    {
        $formsCount = EvaluationForm::query()->where('is_active', true)->count();

        $assignmentBase = EvaluationAssignment::query();
        if ($scopedEmployeeIds !== null) {
            $assignmentBase->whereIn('employee_id', $scopedEmployeeIds);
        }

        $assignmentCounts = (clone $assignmentBase)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $activeAssignments = (int) ($assignmentCounts[EvaluationAssignment::STATUS_PENDING] ?? 0)
            + (int) ($assignmentCounts[EvaluationAssignment::STATUS_IN_PROGRESS] ?? 0);
        $completedAssignments = (int) ($assignmentCounts[EvaluationAssignment::STATUS_COMPLETED] ?? 0);
        $pendingAssignments = (int) ($assignmentCounts[EvaluationAssignment::STATUS_PENDING] ?? 0);

        $overdue = (clone $assignmentBase)
            ->whereNotIn('status', [EvaluationAssignment::STATUS_COMPLETED, EvaluationAssignment::STATUS_CANCELLED])
            ->whereDate('end_date', '<', now()->toDateString())
            ->count();

        $base = Evaluation::query();
        if ($scopedEmployeeIds !== null) {
            $base->whereIn('employee_id', $scopedEmployeeIds);
        }

        // Single pass for the status counts instead of two separate COUNT queries.
        $statusCounts = (clone $base)
            ->whereIn('status', [
                Evaluation::STATUS_DRAFT,
                Evaluation::STATUS_SUBMITTED,
                Evaluation::STATUS_UNDER_REVIEW,
                Evaluation::STATUS_COMPLETED,
            ])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        $employeesEvaluated = (int) ($statusCounts[Evaluation::STATUS_SUBMITTED] ?? 0)
            + (int) ($statusCounts[Evaluation::STATUS_UNDER_REVIEW] ?? 0)
            + (int) ($statusCounts[Evaluation::STATUS_COMPLETED] ?? 0);
        $pending = (int) ($statusCounts[Evaluation::STATUS_DRAFT] ?? 0);

        $completed = (clone $base)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->whereNotNull('overall_score')
            ->with('employee:id,first_name,middle_name,last_name,suffix,profile_image,position')
            ->get();

        $percentages = $completed
            ->map(fn (Evaluation $e) => $this->evaluationScoringService->resolveOverallPercentage($e->scores, $e->overall_score))
            ->filter(fn (?float $pct) => $pct !== null);

        $avgScore = $percentages->isEmpty() ? null : round($percentages->avg(), 2);

        $topPerformers = $completed
            ->map(function (Evaluation $e) {
                $pct = $this->evaluationScoringService->resolveOverallPercentage($e->scores, $e->overall_score);
                return $pct === null ? null : ['evaluation' => $e, 'percentage' => $pct];
            })
            ->filter()
            ->sortByDesc('percentage')
            ->values()
            ->map(fn (array $row) => [
                'id' => $row['evaluation']->id,
                'employee_id' => $row['evaluation']->employee_id,
                'employee' => $row['evaluation']->employee
                    ? trim($row['evaluation']->employee->first_name . ' ' . $row['evaluation']->employee->last_name)
                    : 'Unknown',
                'profile_image' => $row['evaluation']->employee?->profile_image,
                'position' => $row['evaluation']->employee?->position,
                'score' => round($row['percentage'], 2),
                'rating' => $row['evaluation']->overall_rating,
            ]);

        return [
            'total_templates' => $formsCount,
            'active_assignments' => $activeAssignments,
            'completed_assignments' => $completedAssignments,
            'pending_assignments' => $pendingAssignments,
            'overdue_assignments' => $overdue,
            'employees_evaluated' => $employeesEvaluated,
            'pending_evaluations' => $pending,
            'average_score' => $avgScore ? round($avgScore, 2) : null,
            'top_performers' => $topPerformers,
        ];
    }

    public function employeeDashboardWidget(Request $request): JsonResponse
    {
        $user = $request->user();

        $evaluation = Evaluation::query()
            ->with(['evaluator:id,first_name,middle_name,last_name,suffix'])
            ->where('employee_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$evaluation) {
            return response()->json(['widget' => null]);
        }

        $evaluatorLabel = $this->resolveEvaluatorLabel($evaluation);

        return response()->json([
            'widget' => [
                'status' => $evaluation->status,
                'latest_score' => $evaluation->overall_score,
                'latest_rating' => $evaluation->overall_rating,
                'last_evaluated' => $evaluation->evaluated_at?->format('F d, Y') ?? '—',
                'evaluator' => $evaluatorLabel,
            ],
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function ensureEvaluationInScope(User $user, Evaluation $evaluation): void
    {
        if ((int) $evaluation->evaluator_id === (int) $user->id) {
            return;
        }

        $scopedIds = $this->getEvaluationScopeEmployeeIds($user);
        if ($scopedIds !== null && !in_array((int) $evaluation->employee_id, $scopedIds, true)) {
            abort(403, 'Forbidden.');
        }
    }

    private function computeOverallRating(Evaluation $evaluation): void
    {
        $scores = $evaluation->scores;
        if (!$scores) {
            return;
        }

        $evaluation->loadMissing('evaluationForm');
        $surveyData = $scores['survey_data'] ?? null;

        if (is_array($surveyData)) {
            $computed = $this->computeWeightedScoreFromSurveyData($surveyData);
            if ($computed !== null) {
                $evaluation->overall_score = $computed['score'];
                $evaluation->overall_rating = $computed['rating'];

                return;
            }
        }

        $numericValues = [];
        foreach ($scores['sections'] ?? [] as $sectionTitle => $sectionScores) {
            if (!is_array($sectionScores) || str_contains((string) $sectionTitle, 'Summary')) {
                continue;
            }

            $numericValues = array_merge($numericValues, $this->collectNumericScores($sectionScores));
        }

        if (empty($numericValues)) {
            return;
        }

        $score = round(array_sum($numericValues) / count($numericValues), 2);
        $maxScore = $this->getMaxScoreFromForm($evaluation->evaluationForm);

        $evaluation->overall_score = $score;

        $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
        $evaluation->overall_rating = $this->ratingLabelFromPercentage($percentage);
    }

    /**
     * @param  array<string, mixed>  $surveyData
     * @return array{score: float, rating: string, percentage: float}|null
     */
    private function computeWeightedScoreFromSurveyData(array $surveyData): ?array
    {
        $result = $this->evaluationScoringService->computeFromSurveyData($surveyData);
        if ($result === null) {
            return null;
        }

        return [
            'score' => $result['overall_score'],
            'rating' => $result['overall_rating'],
            'percentage' => $result['overall_percentage'],
        ];
    }

    /**
     * @return list<float>
     */
    private function collectNumericScores(array $values): array
    {
        $numericValues = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $numericValues[] = (float) $value;
            } elseif (is_array($value)) {
                $numericValues = array_merge($numericValues, $this->collectNumericScores($value));
            }
        }

        return $numericValues;
    }

    /**
     * @return list<float>
     */
    private function ratingLabelFromPercentage(float $percentage): string
    {
        return $this->evaluationScoringService->ratingLabelFromPercentage($percentage);
    }

    private function getMaxScoreFromForm(?EvaluationForm $form): int
    {
        if (!$form) {
            return 5;
        }

        $max = 0;

        if ($form->sections) {
            foreach ($form->sections as $section) {
                foreach (($section['questions'] ?? []) as $question) {
                    if (($question['type'] ?? '') === 'rating') {
                        $max = max($max, (int) ($question['max'] ?? 5));
                    }
                }
            }
        }

        $surveyJson = $form->survey_json;
        if (is_array($surveyJson) && !empty($surveyJson['pages'])) {
            $walk = function (array $elements) use (&$walk, &$max): void {
                foreach ($elements as $el) {
                    if (!is_array($el)) {
                        continue;
                    }

                    if (($el['type'] ?? '') === 'panel') {
                        $walk($el['elements'] ?? []);
                        continue;
                    }

                    if (($el['type'] ?? '') === 'rating') {
                        $max = max($max, (int) ($el['rateMax'] ?? $el['rateCount'] ?? 5));
                        continue;
                    }

                    if (($el['type'] ?? '') === 'matrix') {
                        foreach ($el['columns'] ?? [] as $col) {
                            if (is_numeric($col)) {
                                $max = max($max, (int) $col);
                            } elseif (is_array($col) && isset($col['value']) && is_numeric($col['value'])) {
                                $max = max($max, (int) $col['value']);
                            }
                        }
                    }
                }
            };

            foreach ($surveyJson['pages'] as $page) {
                $walk($page['elements'] ?? []);
            }
        }

        return $max > 0 ? $max : 5;
    }

    private function resolveEvaluatorLabel(Evaluation $evaluation): string
    {
        if ($evaluation->evaluator) {
            $name = trim($evaluation->evaluator->first_name . ' ' . $evaluation->evaluator->last_name);
            $role = $evaluation->evaluator->role;
            $labels = [
                'company_head' => 'Company Head',
                'area_head' => 'Area Head',
                'branch_head' => 'Branch Manager',
                'department_head' => 'Department Head',
                'division_head' => 'Division Head',
                'section_unit_head' => 'Section/Unit Head',
                'admin_hr' => 'HR Administrator',
            ];
            return $name . ' (' . ($labels[$role] ?? $role) . ')';
        }
        return '—';
    }

    private function sendNotification(Evaluation $evaluation, string $action): void
    {
        $employee = $evaluation->employee;
        $evaluator = $evaluation->evaluator;

        $actionLabels = [
            'assigned' => 'Evaluation Assigned',
            'submitted' => 'Evaluation Submitted',
            'reviewed' => 'Evaluation Reviewed',
            'completed' => 'Evaluation Completed',
        ];

        $label = $actionLabels[$action] ?? 'Evaluation Update';
        $employeeName = $employee ? trim($employee->first_name . ' ' . $employee->last_name) : 'Unknown';
        $message = "{$label} for {$employeeName}";

        $notifyUserIds = collect([$employee?->id, $evaluator?->id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($notifyUserIds as $userId) {
            HrisNotification::create([
                'notifiable_type' => User::class,
                'notifiable_id' => $userId,
                'type' => 'evaluation',
                'title' => $label,
                'message' => $message,
                'module' => 'evaluation',
                'entity_id' => $evaluation->id,
                'entity_type' => Evaluation::class,
                'recipient_user_id' => $userId,
                'company_id' => $evaluation->company_id,
                'action_url' => "/admin/evaluations/{$evaluation->id}",
                'priority' => 'normal',
            ]);
        }
    }
}
