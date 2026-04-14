<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegularizationRecommendation;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\HrRoleResolver;
use App\Services\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RegularizationApprovalController extends Controller
{
    public function __construct(
        private readonly RegularizationService $regularizationService,
        private readonly DataScopeService $dataScopeService,
        private readonly EmployeeStatusService $statusService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * List regularization recommendations (scoped for org heads; HR sees all in scope).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $actor = $request->user();

        if (! $this->hrRoleResolver->maySubmitRegularization($actor)) {
            return response()->json([
                'message' => 'You are not authorized to view regularization recommendations.',
            ], Response::HTTP_FORBIDDEN);
        }

        $query = RegularizationRecommendation::query()
            ->with(['user', 'recommendedBy', 'hrReviewedBy']);

        $this->dataScopeService->restrictRegularizationRecommendationQuery($actor, $query);

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $recommendations = $query->orderByDesc('recommended_at')
            ->get()
            ->map(fn ($rec) => $this->formatRecommendation($rec));

        return response()->json(['recommendations' => $recommendations]);
    }

    /**
     * Approve regularization recommendation.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        if (! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return response()->json([
                'message' => 'Only HR administrators may approve regularization recommendations.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recommendation = RegularizationRecommendation::query()
            ->with(['user', 'recommendedBy'])
            ->findOrFail($id);

        try {
            $recommendation = $this->regularizationService->approveRecommendation(
                $recommendation,
                $actor,
                $validated['notes'] ?? null
            );

            return response()->json([
                'message' => 'Regularization recommendation approved. Employee is set to Regular immediately when the 3-month hire-date milestone is reached, or on that anniversary if not yet reached.',
                'recommendation' => $this->formatRecommendation($recommendation->load(['user', 'recommendedBy', 'hrReviewedBy'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Reject regularization recommendation.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        if (! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return response()->json([
                'message' => 'Only HR administrators may reject regularization recommendations.',
            ], Response::HTTP_FORBIDDEN);
        }

        $recommendation = RegularizationRecommendation::query()
            ->with(['user', 'recommendedBy'])
            ->findOrFail($id);

        try {
            $recommendation = $this->regularizationService->rejectRecommendation(
                $recommendation,
                $actor,
                $validated['reason']
            );

            return response()->json([
                'message' => 'Regularization recommendation rejected.',
                'recommendation' => $this->formatRecommendation($recommendation->load(['user', 'recommendedBy', 'hrReviewedBy'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function formatRecommendation(RegularizationRecommendation $rec): array
    {
        $employee = $rec->user;
        $milestones = null;
        $monthsSinceHire = null;

        if ($employee && $employee->hire_date) {
            $hireDate = \Carbon\Carbon::parse($employee->hire_date);
            $milestones = [
                'hire_date' => $hireDate->toDateString(),
                'three_months' => $hireDate->copy()->addMonths(3)->toDateString(),
                'six_months' => $hireDate->copy()->addMonths(6)->toDateString(),
            ];
            $monthsSinceHire = $hireDate->floatDiffInMonths(\Carbon\Carbon::now(config('attendance.timezone', 'Asia/Manila')));
        }

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
            'employee_status' => $employee?->employment_status,
            'months_since_hire' => $monthsSinceHire ? round($monthsSinceHire, 1) : null,
            'milestones' => $milestones,
            'recommended_by_id' => $rec->recommended_by,
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
            'processed_at' => $rec->processed_at?->toIso8601String(),
            'workflow_status' => $rec->workflowStatus(),
            'required_actions' => $employee ? [
                ...$this->statusService->getRequiredActions($employee),
                'is_blocking' => $rec->status === RegularizationRecommendation::STATUS_PENDING,
            ] : null,
        ];
    }
}
