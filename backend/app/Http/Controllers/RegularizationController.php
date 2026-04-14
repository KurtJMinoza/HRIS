<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentStatus;
use App\Models\RegularizationRecommendation;
use App\Models\RegularizationRequirement;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\HrRoleResolver;
use App\Services\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RegularizationController extends Controller
{
    public function __construct(
        private readonly RegularizationService $regularizationService,
        private readonly EmployeeStatusService $statusService,
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * Get employees eligible for a regularization recommendation (scoped: org heads see their scope; HR sees all).
     */
    public function eligibleEmployees(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->hrRoleResolver->maySubmitRegularization($actor)) {
            return response()->json([
                'message' => 'You are not authorized to view eligible employees for regularization.',
            ], Response::HTTP_FORBIDDEN);
        }

        $query = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->whereNotNull('hire_date');

        $this->dataScopeService->restrictEmployeeQuery($actor, $query);

        $rows = $query->get();
        $today = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->startOfDay();

        // Prevent duplicate submissions: when latest approved recommendation is already in effect
        // (today/future), employee must not appear in "Submit new recommendation".
        $latestActiveApprovedByUser = RegularizationRecommendation::query()
            ->activeApproved($today)
            ->whereIn('user_id', $rows->pluck('id')->all())
            ->orderByDesc('recommended_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($items) => $items->first());

        $employees = $rows->map(function (User $employee) use ($latestActiveApprovedByUser) {
            $canonical = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);

            // This endpoint powers the "Submit recommendation" modal. Include the employee only when
            // there's a meaningful HR action available for their current status.
            $isProbationary = $canonical === EmploymentStatus::Probationary->label();
            $isContractual = $canonical === EmploymentStatus::Contractual->label();
            $isProjectBased = $canonical === EmploymentStatus::ProjectBased->label();

            if (! ($isProbationary || $isContractual || $isProjectBased)) {
                return null;
            }

            $latestActiveApproved = $latestActiveApprovedByUser->get($employee->id);
            if ($latestActiveApproved) {
                return null;
            }

            $milestones = $isProbationary ? $this->statusService->getMilestoneDates($employee) : null;
            $monthsSinceHire = $isProbationary ? $this->statusService->getMonthsSinceHire($employee) : null;
            $latestPending = RegularizationRecommendation::query()
                ->where('user_id', $employee->id)
                ->where('status', RegularizationRecommendation::STATUS_PENDING)
                ->latest('recommended_at')
                ->first();
            $latestRecommendation = RegularizationRecommendation::query()
                ->where('user_id', $employee->id)
                ->latest('recommended_at')
                ->first();
            $requiredActions = $this->statusService->getRequiredActions($employee);
            $earlyMonths = $this->statusService->getAutomationSettings()['early_regularization_months'];
            $eligibleByTenure = $isProbationary && $monthsSinceHire !== null && $monthsSinceHire >= $earlyMonths;
            $eligibleByContractDates = ($isContractual || $isProjectBased) && $employee->contract_end_date !== null;

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'profile_image_url' => $employee->profile_image_url,
                'hire_date' => $employee->hire_date->toDateString(),
                'employment_status' => $employee->employment_status,
                'months_since_hire' => $monthsSinceHire ? round($monthsSinceHire, 1) : null,
                'three_month_target_date' => is_array($milestones) ? ($milestones['three_months'] ?? null) : null,
                'milestones' => $milestones,
                'contract_start_date' => $employee->contract_start_date?->toDateString(),
                'contract_end_date' => $employee->contract_end_date?->toDateString(),
                'has_recommendation' => $latestRecommendation !== null,
                'recommendation_status' => $latestRecommendation?->status,
                'has_pending_recommendation' => $latestPending !== null,
                'has_active_approved_recommendation' => $latestActiveApproved !== null,
                'active_approved_recommendation' => $latestActiveApproved ? [
                    'id' => $latestActiveApproved->id,
                    'effective_date' => $latestActiveApproved->effective_date?->toDateString(),
                    'status' => $latestActiveApproved->status,
                    'workflow_status' => $latestActiveApproved->workflowStatus(),
                ] : null,
                'eligible_for_early_recommendation' => $eligibleByTenure,
                // Used only to gate submissions; UI chooses type list based on employment_status.
                'can_recommend' => ($eligibleByTenure || $eligibleByContractDates) && $latestPending === null,
                'required_actions' => $requiredActions,
            ];
        })->filter()->values();

        return response()->json(['employees' => $employees]);
    }

    /**
     * Submit regularization recommendation (org heads or HR). Optional one-step approve via auto_complete (HR admin accounts only).
     */
    public function submitRecommendation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'recommendation_type' => [
                'required',
                'string',
                Rule::in([
                    RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR,
                    RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
                    RegularizationRecommendation::TYPE_CONTRACT_EXTENSION,
                    RegularizationRecommendation::TYPE_END_CONTRACT,
                    RegularizationRecommendation::TYPE_PROJECT_EXTENSION,
                    RegularizationRecommendation::TYPE_PROJECT_COMPLETION,
                    RegularizationRecommendation::TYPE_PERFORMANCE_BASED,
                ]),
            ],
            'recommendation_notes' => ['required', 'string', 'max:2000'],
            'effective_date' => ['nullable', 'date'],
            'expiration_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'auto_complete' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();
        $employee = User::findOrFail($validated['user_id']);

        try {
            if (($validated['recommendation_type'] ?? null) === RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR) {
                if (! $this->statusService->hasCompletedRequiredActions($employee)) {
                    throw ValidationException::withMessages([
                        'required_actions' => ['Performance review and checklist must be completed before regularization.'],
                    ]);
                }
            }

            $autoComplete = (bool) ($validated['auto_complete'] ?? false);
            $recommendation = $this->regularizationService->submitHrRecommendation(
                $employee,
                $actor,
                $validated['recommendation_notes'],
                $autoComplete,
                $validated['recommendation_type'],
                $validated['effective_date'] ?? null,
                $validated['expiration_date'] ?? null
            );

            $message = $recommendation->status === RegularizationRecommendation::STATUS_PENDING
                ? 'Regularization recommendation submitted. Pending HR approval.'
                : 'Regularization submitted and approved. Status will update to Regular immediately or on the 3-month hire-date anniversary, as applicable.';

            return response()->json([
                'message' => $message,
                'recommendation' => $this->formatRecommendation($recommendation->load(['user', 'recommendedBy', 'hrReviewedBy'])),
            ], 201);
        } catch (ValidationException $e) {
            $type = (string) ($validated['recommendation_type'] ?? RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR);
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $msg) {
                    $details[] = [
                        'code' => $field === 'employee' && str_contains((string) $msg, 'regularization')
                            ? 'REGULARIZATION_NOT_ELIGIBLE'
                            : ($field === 'employee' ? 'EMPLOYEE_NOT_ELIGIBLE' : 'VALIDATION_ERROR'),
                        'field' => $field,
                        'message' => $msg,
                        'recommendationType' => $type,
                    ];
                }
            }

            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
                'error_details' => $details,
                'recommendation_type' => $type,
            ], 422);
        }
    }

    /**
     * Recommendations submitted by the current user (same roles as submit).
     */
    public function myRecommendations(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $this->hrRoleResolver->maySubmitRegularization($actor)) {
            return response()->json([
                'message' => 'You are not authorized to view your regularization submissions.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recommendations = RegularizationRecommendation::query()
            ->with(['user', 'recommendedBy', 'hrReviewedBy'])
            ->where('recommended_by', $actor->id)
            ->orderByDesc('recommended_at')
            ->get()
            ->map(fn ($rec) => $this->formatRecommendation($rec));

        return response()->json(['recommendations' => $recommendations]);
    }

    /**
     * Employee (subject): view recommendations for the authenticated user only (read-only).
     */
    public function myRegularizationAsSubject(Request $request): JsonResponse
    {
        $actor = $request->user();

        $recommendations = RegularizationRecommendation::query()
            ->with(['recommendedBy', 'hrReviewedBy', 'user'])
            ->where('user_id', $actor->id)
            ->orderByDesc('recommended_at')
            ->get()
            ->map(fn ($rec) => $this->formatRecommendation($rec));

        return response()->json(['recommendations' => $recommendations]);
    }

    /**
     * Required-action checklist state for scoped employees before regularization.
     */
    public function requiredActions(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->hrRoleResolver->maySubmitRegularization($actor)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        if (! Schema::hasTable('regularization_requirements')) {
            return response()->json([
                'message' => 'Regularization checklist is not yet available. Please run database migrations.',
                'errors' => [
                    'regularization_requirements' => ['Table regularization_requirements does not exist.'],
                ],
            ], 503);
        }

        $ids = collect((array) $request->query('user_ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->values();

        $q = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true);
        if ($ids->isNotEmpty()) {
            $q->whereIn('id', $ids->all());
        }
        $this->dataScopeService->restrictEmployeeQuery($actor, $q);

        $employees = $q->get(['id', 'name']);
        $rows = $employees->map(function (User $employee) {
            return [
                'user_id' => $employee->id,
                'name' => $employee->name,
                'required_actions' => $this->statusService->getRequiredActions($employee),
            ];
        })->values();

        return response()->json(['employees' => $rows]);
    }

    /**
     * Update required actions (performance review + onboarding checklist completion).
     */
    public function updateRequiredActions(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $this->hrRoleResolver->maySubmitRegularization($actor)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        if (! Schema::hasTable('regularization_requirements')) {
            return response()->json([
                'message' => 'Regularization checklist is not yet available. Please run database migrations.',
                'errors' => [
                    'regularization_requirements' => ['Table regularization_requirements does not exist.'],
                ],
            ], 503);
        }

        $validated = $request->validate([
            'performance_review_completed' => ['sometimes', 'boolean'],
            'performance_review_notes' => ['nullable', 'string', 'max:2000'],
            'checklist_completed' => ['sometimes', 'boolean'],
            'checklist_notes' => ['nullable', 'string', 'max:2000'],
            'training_completed' => ['sometimes', 'boolean'],
            'documents_submitted' => ['sometimes', 'boolean'],
            'manager_recommendation_received' => ['sometimes', 'boolean'],
        ]);

        $employee = User::query()->where('role', User::ROLE_EMPLOYEE)->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);

        $row = RegularizationRequirement::query()->firstOrCreate(['user_id' => $employee->id]);
        if (array_key_exists('performance_review_completed', $validated)) {
            $row->performance_review_completed = (bool) $validated['performance_review_completed'];
            $row->performance_review_completed_at = $row->performance_review_completed ? now() : null;
            if (Schema::hasColumn('regularization_requirements', 'performance_review_completed_by')) {
                $row->performance_review_completed_by = $row->performance_review_completed ? $actor->id : null;
            }
        }
        if (array_key_exists('performance_review_notes', $validated)) {
            $row->performance_review_notes = $validated['performance_review_notes'];
        }
        if (array_key_exists('checklist_completed', $validated)) {
            $row->checklist_completed = (bool) $validated['checklist_completed'];
            $row->checklist_completed_at = $row->checklist_completed ? now() : null;
            if (Schema::hasColumn('regularization_requirements', 'checklist_completed_by')) {
                $row->checklist_completed_by = $row->checklist_completed ? $actor->id : null;
            }
        }
        if (array_key_exists('checklist_notes', $validated)) {
            $row->checklist_notes = $validated['checklist_notes'];
        }

        if (array_key_exists('training_completed', $validated) && Schema::hasColumn('regularization_requirements', 'training_completed')) {
            $row->training_completed = (bool) $validated['training_completed'];
            if (Schema::hasColumn('regularization_requirements', 'training_completed_at')) {
                $row->training_completed_at = $row->training_completed ? now() : null;
            }
            if (Schema::hasColumn('regularization_requirements', 'training_completed_by')) {
                $row->training_completed_by = $row->training_completed ? $actor->id : null;
            }
        }

        if (array_key_exists('documents_submitted', $validated) && Schema::hasColumn('regularization_requirements', 'documents_submitted')) {
            $row->documents_submitted = (bool) $validated['documents_submitted'];
            if (Schema::hasColumn('regularization_requirements', 'documents_submitted_at')) {
                $row->documents_submitted_at = $row->documents_submitted ? now() : null;
            }
            if (Schema::hasColumn('regularization_requirements', 'documents_submitted_by')) {
                $row->documents_submitted_by = $row->documents_submitted ? $actor->id : null;
            }
        }

        if (array_key_exists('manager_recommendation_received', $validated) && Schema::hasColumn('regularization_requirements', 'manager_recommendation_received')) {
            $row->manager_recommendation_received = (bool) $validated['manager_recommendation_received'];
            if (Schema::hasColumn('regularization_requirements', 'manager_recommendation_received_at')) {
                $row->manager_recommendation_received_at = $row->manager_recommendation_received ? now() : null;
            }
            if (Schema::hasColumn('regularization_requirements', 'manager_recommendation_received_by')) {
                $row->manager_recommendation_received_by = $row->manager_recommendation_received ? $actor->id : null;
            }
        }

        $row->updated_by = $actor->id;
        $row->save();

        return response()->json([
            'message' => 'Required actions updated.',
            'required_actions' => $this->statusService->getRequiredActions($employee),
        ]);
    }

    private function formatRecommendation(RegularizationRecommendation $rec): array
    {
        $employee = $rec->user;

        return [
            'id' => $rec->id,
            'employee_id' => $rec->user_id,
            'recommendation_type' => $rec->recommendation_type ?? RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR,
            'effective_date' => $rec->effective_date?->toDateString(),
            'expiration_date' => $rec->expiration_date?->toDateString(),
            'employee_name' => $employee?->name,
            'employee_code' => $employee?->employee_code,
            'employee_profile_image' => $employee?->profile_image_url,
            'employee_hire_date' => $employee?->hire_date?->toDateString(),
            'employee_position' => $employee?->position,
            'employee_employment_type' => $employee?->employment_type,
            'recommended_by_name' => $rec->recommendedBy?->name,
            'recommended_by_profile_image' => $rec->recommendedBy?->profile_image_url,
            'recommended_by_hr_role' => $rec->recommendedBy
                ? $this->hrRoleResolver->resolveForApprovalSubject($rec->recommendedBy)->value
                : null,
            'recommended_by_role_label' => $rec->recommendedBy
                ? $this->hrRoleResolver->resolveForApprovalSubject($rec->recommendedBy)->badgeLabel()
                : null,
            'recommendation_notes' => $rec->recommendation_notes,
            'status' => $rec->status,
            'hr_reviewed_by_name' => $rec->hrReviewedBy?->name,
            'hr_reviewed_by_profile_image' => $rec->hrReviewedBy?->profile_image_url,
            'hr_reviewed_by_hr_role' => $rec->hrReviewedBy
                ? $this->hrRoleResolver->resolveForApprovalSubject($rec->hrReviewedBy)->value
                : null,
            'hr_reviewed_by_role_label' => $rec->hrReviewedBy
                ? $this->hrRoleResolver->resolveForApprovalSubject($rec->hrReviewedBy)->badgeLabel()
                : null,
            'hr_reviewed_at' => $rec->hr_reviewed_at?->toIso8601String(),
            'hr_notes' => $rec->hr_notes,
            'recommended_at' => $rec->recommended_at->toIso8601String(),
            'processed' => $rec->processed,
            'workflow_status' => $rec->workflowStatus(),
            'required_actions' => $employee ? [
                ...$this->statusService->getRequiredActions($employee),
                // Approved/completed requests should no longer block as "required actions before confirmation".
                'is_blocking' => $rec->status === RegularizationRecommendation::STATUS_PENDING,
            ] : null,
        ];
    }
}
