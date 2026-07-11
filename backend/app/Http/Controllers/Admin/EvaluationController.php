<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\HrRole;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationForm;
use App\Models\HrisNotification;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EvaluationScoringService;
use App\Services\EvaluationWorkflowSettingService;
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
        private readonly EvaluationWorkflowSettingService $evalWorkflowService,
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
            'dashboard' => $this->buildDashboardSummary($scopedEmployeeIds),
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
        $canEvaluate = $isAdmin || $hrRole === HrRole::AdminHr || in_array('evaluations.create', $permSet, true);
        $canReview = $isAdmin || $hrRole === HrRole::AdminHr || in_array('evaluations.review', $permSet, true);

        return [
            'can_manage_templates' => $canManageTemplates,
            'can_evaluate' => $canEvaluate,
            'can_review' => $canReview,
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

        $scopedIds = $this->getEvaluationScopeEmployeeIds($user);
        if ($scopedIds !== null && !in_array((int) $employee->id, $scopedIds, true)) {
            return response()->json(['message' => 'You are not authorized to evaluate this employee.'], 403);
        }

        $alreadyEvaluated = Evaluation::query()
            ->where('employee_id', $validated['employee_id'])
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

        $evaluation = Evaluation::create([
            'company_id' => $validated['company_id'],
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'evaluation_form_id' => $validated['evaluation_form_id'],
            'employee_id' => $validated['employee_id'],
            'evaluator_id' => $user->id,
            'scores' => $validated['scores'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'submitted_at' => isset($validated['status']) && $validated['status'] === 'submitted' ? now() : null,
            'evaluated_at' => isset($validated['status']) && $validated['status'] === 'submitted' ? now() : null,
        ]);

        if ($evaluation->scores && $evaluation->status === 'submitted') {
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

        if ($evaluation->status === Evaluation::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Cannot update a submitted evaluation.'], 422);
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string', 'max:10000'],
        ]);

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

        $evaluation->delete();

        return response()->json(['message' => 'Evaluation deleted successfully.']);
    }

    // ─── Workflow Actions ────────────────────────────────────────────

    public function submit(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if ($evaluation->status === Evaluation::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Evaluation is already submitted.'], 422);
        }

        $evaluation->load('evaluationForm');
        $this->computeOverallRating($evaluation);

        $workflowSettings = $this->evalWorkflowService->resolveSetting();
        $useHierarchy = (bool) ($workflowSettings['use_hierarchy_approval'] ?? false);

        $newStatus = $useHierarchy ? Evaluation::STATUS_SUBMITTED : Evaluation::STATUS_COMPLETED;

        $evaluation->update([
            'status' => $newStatus,
            'submitted_at' => now(),
            'evaluated_at' => now(),
            'overall_score' => $evaluation->overall_score,
            'overall_rating' => $evaluation->overall_rating,
        ]);

        $this->sendNotification($evaluation, $useHierarchy ? 'submitted' : 'completed');

        return response()->json([
            'message' => $useHierarchy ? 'Evaluation submitted successfully.' : 'Evaluation submitted and completed.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
            ]),
            'workflow' => [
                'use_hierarchy_approval' => $useHierarchy,
                'status' => $newStatus,
            ],
        ]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if ($evaluation->status !== Evaluation::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Only submitted evaluations can be reviewed.'], 422);
        }

        $evaluation->update([
            'status' => Evaluation::STATUS_UNDER_REVIEW,
            'reviewed_at' => now(),
            'reviewed_by' => request()->user()->id,
        ]);

        $this->sendNotification($evaluation, 'reviewed');

        return response()->json([
            'message' => 'Evaluation is now under review.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'reviewer:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ]),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        $this->ensureEvaluationInScope($request->user(), $evaluation);

        if (!in_array($evaluation->status, [Evaluation::STATUS_SUBMITTED, Evaluation::STATUS_UNDER_REVIEW])) {
            return response()->json(['message' => 'Only submitted or reviewed evaluations can be completed.'], 422);
        }

        $evaluation->update([
            'status' => Evaluation::STATUS_COMPLETED,
        ]);

        $this->sendNotification($evaluation, 'completed');

        return response()->json([
            'message' => 'Evaluation completed successfully.',
            'evaluation' => $evaluation->fresh([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
            ]),
        ]);
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
        $scopedEmployeeIds = $this->getEvaluationScopeEmployeeIds($request->user());

        return response()->json($this->buildDashboardSummary($scopedEmployeeIds));
    }

    /**
     * @param  list<int>|null  $scopedEmployeeIds
     * @return array<string, mixed>
     */
    private function buildDashboardSummary(?array $scopedEmployeeIds): array
    {
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
            ->whereNotNull('overall_score');

        $avgScore = (clone $completed)->avg('overall_score');

        $topPerformers = $completed
            ->with('employee:id,first_name,middle_name,last_name,suffix,profile_image,position')
            ->orderByDesc('overall_score')
            ->take(5)
            ->get()
            ->map(fn (Evaluation $e) => [
                'id' => $e->id,
                'employee' => $e->employee ? trim($e->employee->first_name . ' ' . $e->employee->last_name) : 'Unknown',
                'score' => $e->overall_score,
                'rating' => $e->overall_rating,
            ]);

        return [
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
            ->merge($this->getOrgHeadUserIds($evaluation))
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

    private function getOrgHeadUserIds(Evaluation $evaluation): array
    {
        $employee = $evaluation->employee;
        if (!$employee) {
            return [];
        }

        $stepFlags = $this->evalWorkflowService->hierarchyStepFlags();
        $ids = [];

        $assignment = $employee->organizationAssignments()->first();
        if ($assignment) {
            if ($stepFlags['include_section_head'] ?? false) {
                $sectionUnitId = $assignment->section_unit_id ?? $employee->section_unit_id;
                if ($sectionUnitId) {
                    $section = \App\Models\SectionUnit::find($sectionUnitId);
                    if ($section && $section->section_unit_head_id) {
                        $ids[] = $section->section_unit_head_id;
                    }
                }
            }

            if ($stepFlags['include_department_head'] ?? false) {
                $departmentId = $assignment->department_id ?? $employee->department_id;
                if ($departmentId) {
                    $department = \App\Models\Department::find($departmentId);
                    if ($department && $department->department_head_id) {
                        $ids[] = $department->department_head_id;
                    }
                }
            }

            if ($stepFlags['include_division_head'] ?? false) {
                $divisionId = $assignment->division_id ?? $employee->division_id;
                if ($divisionId) {
                    $division = \App\Models\Division::find($divisionId);
                    if ($division && $division->division_head_id) {
                        $ids[] = $division->division_head_id;
                    }
                }
            }

            if ($stepFlags['include_branch_head'] ?? false) {
                $branchId = $assignment->branch_id ?? $employee->branch_id;
                if ($branchId) {
                    $branch = \App\Models\Branch::find($branchId);
                    if ($branch && $branch->branch_manager_id) {
                        $ids[] = $branch->branch_manager_id;
                    }
                }
            }

            if ($stepFlags['include_area_head'] ?? false) {
                $branchId = $assignment->branch_id ?? $employee->branch_id;
                if ($branchId) {
                    $branch = \App\Models\Branch::find($branchId);
                    if ($branch && $branch->area_id) {
                        $area = \App\Models\Area::find($branch->area_id);
                        if ($area && $area->area_manager_employee_id) {
                            $ids[] = $area->area_manager_employee_id;
                        }
                    }
                }
            }
        }

        if ($stepFlags['include_company_head'] ?? false) {
            $companyId = $evaluation->company_id;
            $company = Company::find($companyId);
            if ($company && $company->company_head_id) {
                $ids[] = $company->company_head_id;
            }
        }

        return array_unique(array_filter($ids));
    }
}
