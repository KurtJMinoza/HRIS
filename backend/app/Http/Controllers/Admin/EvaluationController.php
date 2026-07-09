<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationForm;
use App\Models\HrisNotification;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EvaluationWorkflowSettingService;
use App\Services\HrRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly EvaluationWorkflowSettingService $evalWorkflowService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    // ─── Evaluation Forms ───────────────────────────────────────────

    public function formsIndex(Request $request): JsonResponse
    {
        $query = EvaluationForm::query()
            ->with('createdBy:id,name,first_name,middle_name,last_name')
            ->withCount('evaluations')
            ->orderBy('created_at', 'desc');

        $this->dataScopeService->restrictCompanyQuery($request->user(), $query, 'evaluation_forms');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'forms' => $query->get()->map(fn (EvaluationForm $f) => [
                'id' => $f->id,
                'company_id' => $f->company_id,
                'title' => $f->title,
                'description' => $f->description,
                'sections' => $f->sections,
                'is_active' => $f->is_active,
                'created_by' => $f->createdBy?->display_name,
                'evaluations_count' => $f->evaluations_count,
                'created_at' => $f->created_at?->toIso8601String(),
                'updated_at' => $f->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function formsStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'sections.*.questions' => ['required', 'array', 'min:1'],
            'sections.*.questions.*.title' => ['required', 'string', 'max:255'],
            'sections.*.questions.*.type' => ['required', 'string', 'in:rating,text'],
            'sections.*.questions.*.max' => ['required_if:sections.*.questions.*.type,rating', 'integer', 'min:1', 'max:100'],
        ]);

        $form = EvaluationForm::create([
            'company_id' => $validated['company_id'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'sections' => $validated['sections'],
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
            'sections' => ['sometimes', 'array', 'min:1'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.weight' => ['required_with:sections', 'numeric', 'min:0', 'max:100'],
            'sections.*.questions' => ['required_with:sections', 'array', 'min:1'],
            'sections.*.questions.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.questions.*.type' => ['required_with:sections', 'string', 'in:rating,text'],
            'sections.*.questions.*.max' => ['required_if:sections.*.questions.*.type,rating', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

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
        $request->validate(['company_id' => ['required', 'integer', 'exists:companies,id']]);

        $employees = User::query()
            ->where('company_id', $request->integer('company_id'))
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_image', 'position']);

        return response()->json(['employees' => $employees]);
    }

    // ─── Evaluations CRUD ───────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Evaluation::query()
            ->with([
                'evaluationForm:id,title',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'reviewer:id,first_name,middle_name,last_name,suffix',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ])
            ->orderBy('created_at', 'desc');

        $this->dataScopeService->restrictCompanyQuery($request->user(), $query);

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

        // Resolve org assignment for branch/department
        $employee = User::find($validated['employee_id']);
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
            'evaluator_id' => $request->user()->id,
            'scores' => $validated['scores'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'submitted_at' => isset($validated['status']) && $validated['status'] === 'submitted' ? now() : null,
            'evaluated_at' => isset($validated['status']) && $validated['status'] === 'submitted' ? now() : null,
        ]);

        if ($evaluation->scores && $evaluation->status === 'submitted') {
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

    public function show(int $id): JsonResponse
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

        return response()->json(['evaluation' => $evaluation]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

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

    public function destroy(int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);
        $evaluation->delete();

        return response()->json(['message' => 'Evaluation deleted successfully.']);
    }

    // ─── Workflow Actions ────────────────────────────────────────────

    public function submit(int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

        if ($evaluation->status === Evaluation::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Evaluation is already submitted.'], 422);
        }

        $this->computeOverallRating($evaluation);

        // Check workflow settings to determine if hierarchy approval is needed
        $workflowSettings = $this->evalWorkflowService->resolveSetting();
        $useHierarchy = (bool) ($workflowSettings['use_hierarchy_approval'] ?? false);

        // When hierarchy approval is enabled, move to submitted for review chain
        // Otherwise, move directly to completed
        $newStatus = $useHierarchy ? Evaluation::STATUS_SUBMITTED : Evaluation::STATUS_COMPLETED;

        $evaluation->update([
            'status' => $newStatus,
            'submitted_at' => now(),
            'evaluated_at' => now(),
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

    public function review(int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

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

    public function complete(int $id): JsonResponse
    {
        $evaluation = Evaluation::findOrFail($id);

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
        $query = Evaluation::query()
            ->with([
                'evaluationForm:id,title',
                'evaluator:id,first_name,middle_name,last_name,suffix',
                'reviewer:id,first_name,middle_name,last_name,suffix',
            ])
            ->where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc');

        $this->dataScopeService->restrictCompanyQuery($request->user(), $query);

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
        $query = Evaluation::query();

        $this->dataScopeService->restrictCompanyQuery($user, $query);

        $employeesEvaluated = (clone $query)->whereIn('status', [
            Evaluation::STATUS_SUBMITTED,
            Evaluation::STATUS_UNDER_REVIEW,
            Evaluation::STATUS_COMPLETED,
        ])->count();

        $pending = (clone $query)->where('status', Evaluation::STATUS_DRAFT)->count();

        $avgScore = (clone $query)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->whereNotNull('overall_score')
            ->avg('overall_score');

        // Top performers: completed evaluations with highest scores
        $topPerformers = (clone $query)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->whereNotNull('overall_score')
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

        return response()->json([
            'employees_evaluated' => $employeesEvaluated,
            'pending_evaluations' => $pending,
            'average_score' => $avgScore ? round($avgScore, 2) : null,
            'top_performers' => $topPerformers,
        ]);
    }

    public function employeeDashboardWidget(Request $request): JsonResponse
    {
        $user = $request->user();

        $evaluation = Evaluation::query()
            ->with([
                'evaluator:id,first_name,middle_name,last_name,suffix',
            ])
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

    private function computeOverallRating(Evaluation $evaluation): void
    {
        $scores = $evaluation->scores;
        if (!$scores || !isset($scores['sections'])) {
            return;
        }

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($scores['sections'] as $sectionTitle => $sectionScores) {
            $sectionValues = array_filter($sectionScores, fn ($v) => is_numeric($v));
            if (empty($sectionValues)) {
                continue;
            }
            $avg = array_sum($sectionValues) / count($sectionValues);
            $totalWeight++;
            $weightedSum += $avg;
        }

        if ($totalWeight === 0) {
            return;
        }

        $score = round($weightedSum / $totalWeight, 2);
        $maxScore = $this->getMaxScoreFromForm($evaluation->evaluationForm);

        $evaluation->overall_score = $score;

        // Convert score to rating label
        $percentage = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;
        $evaluation->overall_rating = match (true) {
            $percentage >= 90 => 'Outstanding',
            $percentage >= 85 => 'Excellent',
            $percentage >= 80 => 'Very Good',
            $percentage >= 75 => 'Good',
            $percentage >= 70 => 'Satisfactory',
            default => 'Needs Improvement',
        };
    }

    private function getMaxScoreFromForm(?EvaluationForm $form): int
    {
        if (!$form || !$form->sections) {
            return 5;
        }

        $max = 0;
        foreach ($form->sections as $section) {
            foreach (($section['questions'] ?? []) as $question) {
                if (($question['type'] ?? '') === 'rating') {
                    $max = max($max, (int) ($question['max'] ?? 5));
                }
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

        // Load workflow settings to determine which hierarchy steps are enabled
        $settings = $this->evalWorkflowService->resolveSetting();
        $stepFlags = $this->evalWorkflowService->hierarchyStepFlags();

        $ids = [];
        $companyId = $evaluation->company_id;

        // Use organization assignments to find org heads based on enabled steps
        $assignment = $employee->organizationAssignments()->first();
        if ($assignment) {
            $stepMap = [
                'section_unit' => 'include_section_head',
                'department' => 'include_department_head',
                'division' => 'include_division_head',
                'branch' => 'include_branch_head',
                'area' => 'include_area_head',
                'company' => 'include_company_head',
            ];

            foreach ($stepMap as $orgType => $flagKey) {
                if (!($stepFlags[$flagKey] ?? false)) {
                    continue;
                }
                $leaderIds = $assignment->getLeaderIdsByType($orgType);
                foreach ($leaderIds as $leaderId) {
                    if ($leaderId) {
                        $ids[] = $leaderId;
                    }
                }
            }
        }

        // Include company head if company_head step is enabled
        if ($stepFlags['include_company_head'] ?? false) {
            $company = Company::find($companyId);
            if ($company && $company->head_id) {
                $ids[] = $company->head_id;
            }
        }

        return array_unique(array_filter($ids));
    }
}
