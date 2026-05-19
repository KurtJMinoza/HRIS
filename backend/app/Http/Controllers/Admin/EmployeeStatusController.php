<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentStatusSetting;
use App\Models\ProbationMilestoneNotification;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\HrRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeStatusController extends Controller
{
    public function __construct(
        private readonly EmployeeStatusService $statusService,
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * Get employee status details and history.
     */
    public function show(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $employee = User::findOrFail($userId);

        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);

        $milestones = $this->statusService->getMilestoneDates($employee);
        $monthsSinceHire = $this->statusService->getMonthsSinceHire($employee);

        $history = EmployeeStatusHistory::query()
            ->with(['actor:id,name,first_name,middle_name,last_name,suffix'])
            ->where('user_id', $employee->id)
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (EmployeeStatusHistory $h) {
                return [
                    'id' => $h->id,
                    'previous_status' => $h->previous_status,
                    'new_status' => $h->new_status,
                    'effective_date' => $h->effective_date->toDateString(),
                    'trigger_type' => $h->trigger_type,
                    'actor_name' => $h->actor?->display_name,
                    'remarks' => $h->remarks,
                    'created_at' => $h->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'employee_code' => $employee->employee_code,
                'hire_date' => $employee->hire_date?->toDateString(),
                'employment_status' => $employee->employment_status,
                'employment_status_effective_date' => $employee->employment_status_effective_date?->toDateString(),
                'contract_start_date' => $employee->contract_start_date?->toDateString(),
                'contract_end_date' => $employee->contract_end_date?->toDateString(),
                'months_since_hire' => $monthsSinceHire ? round($monthsSinceHire, 1) : null,
                'milestones' => $milestones,
                'probation_review_phase' => $this->statusService->getProbationReviewPhase($employee),
                'five_month_recorded_in_system' => ProbationMilestoneNotification::query()
                    ->where('user_id', $employee->id)
                    ->where('milestone', ProbationMilestoneNotification::MILESTONE_FIVE_MONTH)
                    ->exists(),
                'six_month_recorded_in_system' => ProbationMilestoneNotification::query()
                    ->where('user_id', $employee->id)
                    ->where('milestone', ProbationMilestoneNotification::MILESTONE_SIX_MONTH)
                    ->exists(),
                'is_active' => $employee->is_active,
            ],
            'history' => $history,
        ]);
    }

    /**
     * Manually change employee status (admin override).
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $statusOptions = $this->statusService->getConfiguredStatuses();
        $validated = $request->validate([
            'employment_status' => ['required', 'string', Rule::in($statusOptions)],
            'effective_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        $employee = User::findOrFail($userId);

        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);

        $newStatus = EmploymentStatus::from($validated['employment_status']);
        $effectiveDate = $validated['effective_date']
            ? \Carbon\Carbon::parse($validated['effective_date'])
            : null;

        $history = $this->statusService->changeStatus(
            $employee,
            $newStatus,
            'manual_admin',
            $actor,
            $validated['remarks'] ?? null,
            $effectiveDate
        );

        return response()->json([
            'message' => 'Employee status updated successfully.',
            'employee' => [
                'id' => $employee->id,
                'employment_status' => $employee->fresh()->employment_status,
            ],
            'history' => [
                'id' => $history->id,
                'previous_status' => $history->previous_status,
                'new_status' => $history->new_status,
                'effective_date' => $history->effective_date->toDateString(),
                'trigger_type' => $history->trigger_type,
                'actor_name' => $history->actor?->display_name,
                'remarks' => $history->remarks,
            ],
        ]);
    }

    /**
     * Configurable status and automation settings (HR admin settings page source).
     */
    public function settings(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($this->statusService->getAutomationSettings());
    }

    /**
     * Update configurable status and automation settings (HR admin only).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'auto_regularization_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'early_regularization_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'status_options' => ['nullable', 'array', 'min:1'],
            'status_options.*' => ['required', 'string'],
        ]);

        if (isset($validated['auto_regularization_months'])) {
            EmploymentStatusSetting::query()->updateOrCreate(
                ['key' => 'auto_regularization_months'],
                ['value' => (string) $validated['auto_regularization_months'], 'updated_by' => $actor->id]
            );
        }

        if (isset($validated['early_regularization_months'])) {
            EmploymentStatusSetting::query()->updateOrCreate(
                ['key' => 'early_regularization_months'],
                ['value' => (string) $validated['early_regularization_months'], 'updated_by' => $actor->id]
            );
        }

        if (isset($validated['status_options'])) {
            $values = collect($validated['status_options'])
                ->map(fn ($v) => strtolower(trim((string) $v)))
                ->filter()
                ->values()
                ->all();
            EmploymentStatusSetting::query()->updateOrCreate(
                ['key' => 'status_options'],
                ['value' => implode(',', $values), 'updated_by' => $actor->id]
            );
        }

        return response()->json([
            'message' => 'Employment status settings updated.',
            'settings' => $this->statusService->getAutomationSettings(),
        ]);
    }

    /**
     * Get employees approaching regularization milestones.
     */
    public function upcomingRegularizations(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Keep the request param for UI, but align the algorithm with the dashboard widget.
        $windowDays = max(1, (int) ($request->query('days_ahead', 30)));
        $windowDays = min(365, $windowDays);

        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = now($tz)->startOfDay();
        $settings = $this->statusService->getAutomationSettings();
        $earlyMonths = (int) ($settings['early_regularization_months'] ?? 3);
        $autoMonths = (int) ($settings['auto_regularization_months'] ?? 6);
        $isHrAdmin = $this->hrRoleResolver->isAdminHrAccount($actor);

        // Probationary queue (milestones from hire_date).
        $query = User::query()
            ->activeRoster()
            ->whereNotNull('hire_date')
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);

        $this->dataScopeService->restrictEmployeeQuery($actor, $query);

        $employeesInScope = $query->get();
        $scopeIds = $employeesInScope->pluck('id')->all();
        $lockedByApproved = RegularizationRecommendation::query()
            ->activeApproved($today)
            ->whereIn('user_id', $scopeIds)
            ->orderByDesc('recommended_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        $recommendationsByUser = RegularizationRecommendation::query()
            ->with(['recommendedBy:id,name,first_name,middle_name,last_name,suffix'])
            ->whereIn('user_id', $scopeIds)
            ->where('recommendation_type', RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR)
            ->orderByDesc('recommended_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        $employees = $employeesInScope->map(function (User $employee) use (
            $today,
            $recommendationsByUser,
            $lockedByApproved,
            $isHrAdmin,
            $earlyMonths,
            $autoMonths,
            $windowDays
        ) {
            $hireDate = $employee->hire_date?->copy()->startOfDay();
            if (! $hireDate) {
                return null;
            }

            // Employee already has an approved recommendation effective today/future.
            // Keep them out of active "upcoming milestone" queue to prevent duplicate submissions.
            if ($lockedByApproved->has($employee->id)) {
                return null;
            }

            $canonicalEmploymentLabel = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);
            // Backward-compatible: treat missing status as probationary (default) for legacy rows.
            if ($canonicalEmploymentLabel === null) {
                $canonicalEmploymentLabel = EmploymentStatus::Probationary->label();
            }
            if ($canonicalEmploymentLabel !== EmploymentStatus::Probationary->label()) {
                return null;
            }

            $threeMonth = $hireDate->copy()->addMonths($earlyMonths);
            $fiveMonth = $hireDate->copy()->addMonths(max(0, $autoMonths - 1));
            $sixMonth = $hireDate->copy()->addMonths($autoMonths);

            $monthsSinceHire = $this->statusService->getMonthsSinceHire($employee, $today);
            if ($monthsSinceHire === null) {
                return null;
            }

            $serviceDiff = $hireDate->diff($today);
            $serviceMonths = ($serviceDiff->y * 12) + $serviceDiff->m;
            $serviceLengthLabel = ($serviceMonths ?? 0).' months '.$serviceDiff->d.' days';

            $passedThree = $today->greaterThanOrEqualTo($threeMonth);
            $passedFive = $today->greaterThanOrEqualTo($fiveMonth);
            $passedSix = $today->greaterThanOrEqualTo($sixMonth);

            $approachingThree = $today->lessThan($threeMonth) && $today->diffInDays($threeMonth) <= $windowDays;
            $approachingFive = $today->lessThan($fiveMonth) && $today->diffInDays($fiveMonth) <= $windowDays;
            $approachingSix = $today->lessThan($sixMonth) && $today->diffInDays($sixMonth) <= $windowDays;

            // Keep the Regularization module in sync with the Employment tab: once an employee is
            // probationary and has a Hire Date, always expose the milestone timeline derived from
            // that Employment data. The UI can still use windowing/urgency labels, but HR should
            // not lose visibility of the 3-/5-/6-month dates simply because the employee is still
            // earlier than the old monitoring window.
            $hasApproachingOrPassedMilestone = $passedThree || $passedFive || $passedSix || $approachingThree || $approachingFive || $approachingSix;

            $isOverdueSix = $today->greaterThan($sixMonth);
            $withinWindow = $today->diffInDays($sixMonth) <= $windowDays;
            $earlyEligible = $monthsSinceHire >= $earlyMonths && ! $isOverdueSix;

            $daysToSixMonth = $isOverdueSix ? -$sixMonth->diffInDays($today) : $today->diffInDays($sixMonth);
            $daysRemainingLabel = $daysToSixMonth > 0
                ? $daysToSixMonth.' days left'
                : ($daysToSixMonth === 0 ? 'Due today' : abs($daysToSixMonth).' days overdue');

            $statusTone = $isOverdueSix ? 'red' : ($withinWindow ? 'green' : 'orange');
            $statusLabel = $isOverdueSix ? 'Overdue' : ($withinWindow ? 'On Track' : 'Due Soon');
            if ($statusTone === 'orange' && $earlyEligible) {
                $statusLabel = 'Early eligible';
            }

            $candidates = collect([
                ['milestone' => '3-month', 'date' => $threeMonth],
                ['milestone' => '5-month', 'date' => $fiveMonth],
                ['milestone' => '6-month', 'date' => $sixMonth],
            ])->sortBy(fn ($x) => $x['date']->getTimestamp());

            $upcoming = $candidates->first(fn ($x) => $x['date']->greaterThanOrEqualTo($today));
            $picked = $upcoming ?? $candidates->last();
            $nextMilestone = $picked['milestone'];
            $nextMilestoneDate = $picked['date']->toDateString();

            $recommendedAction = match ($nextMilestone) {
                '6-month' => 'HR decision: confirm Regular or extended probation',
                '5-month' => 'Complete 5-month review; schedule 6-month HR decision',
                '3-month' => 'Early confirmation after 3 months (head recommendation + HR approval)',
                default => '—',
            };

            $recommendation = $recommendationsByUser->get($employee->id);
            $requiredActions = $this->statusService->getRequiredActions($employee);
            $actionsComplete = (bool) ($requiredActions['all_completed'] ?? false);
            $departmentName = $employee->departmentRelation?->name ?? $employee->department ?? 'Unassigned';
            $branchName = $employee->branch?->name ?? $employee->departmentRelation?->branch?->name ?? null;

            return [
                'id' => $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'profile_image_url' => $employee->profile_image_url,
                'employee_code' => $employee->employee_code,
                'position' => $employee->position,
                'employment_type' => $employee->employment_type,
                'employment_status' => $employee->employment_status,
                'department' => $departmentName,
                'branch' => $branchName,
                'hire_date' => $hireDate->toDateString(),
                'probation_end_date' => $sixMonth->toDateString(),
                'early_eligibility_date' => $threeMonth->toDateString(),
                'service_length_label' => $serviceLengthLabel,
                'months_since_hire' => round($monthsSinceHire, 1),
                'days_remaining' => $daysToSixMonth,
                'days_remaining_label' => $daysRemainingLabel,
                'next_milestone' => $nextMilestone,
                'next_milestone_date' => $nextMilestoneDate,
                'recommended_action' => $recommendedAction,
                'status_label' => $statusLabel,
                'indicator' => $statusTone,
                'indicator_label' => $statusLabel,
                // Backward-compatible fields for the existing AdminRegularization UI:
                'milestones' => [
                    'hire_date' => $hireDate->toDateString(),
                    'three_months' => $threeMonth->toDateString(),
                    'five_months' => $fiveMonth->toDateString(),
                    'six_months' => $sixMonth->toDateString(),
                ],
                'approaching_milestone' => $this->statusService->isApproachingMilestone($employee, $windowDays),
                'probation_review_phase' => $this->statusService->getProbationReviewPhase($employee, $today),
                'actions' => [
                    'can_recommend_early' => ! $isHrAdmin
                        && $earlyEligible
                        && $actionsComplete
                        && ! $isOverdueSix
                        && ! $withinWindow
                        && ($recommendation === null || $recommendation->status === RegularizationRecommendation::STATUS_REJECTED),
                ],
            ];
        })->filter()->values();

        /**
         * Contract / project-based queue (milestones from contract_end_date).
         *
         * Requirement: expired-today and already-expired contracts must remain visible for HR action.
         * We mirror the dashboard "Expiring Contracts" window: include recently-ended (past 30 days)
         * and upcoming (next N days).
         */
        $contractQuery = User::query()
            ->activeRoster()
            ->whereNotNull('contract_end_date')
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(employment_type, '')) IN ('contractual','project-based','project_based','project based')")
                    ->orWhereRaw("LOWER(COALESCE(employment_status, '')) IN ('contractual','project-based','project_based','project based')");
            })
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $contractQuery);

        $from = $today->copy()->subDays(30);
        $until = $today->copy()->addDays(max(1, $windowDays));
        $contractEmployees = $contractQuery
            ->whereDate('contract_end_date', '>=', $from->toDateString())
            ->whereDate('contract_end_date', '<=', $until->toDateString())
            ->get();

        $contractRows = $contractEmployees->map(function (User $employee) use ($today, $lockedByApproved) {
            if ($lockedByApproved->has($employee->id)) {
                return null;
            }

            $end = $employee->contract_end_date?->copy()->startOfDay();
            if (! $end) {
                return null;
            }
            $start = $employee->contract_start_date?->copy()->startOfDay();
            $daysRemaining = (int) $today->diffInDays($end, false);

            // Badge label parity with dashboard: end == today => "Expired today"
            $daysRemainingLabel = $daysRemaining < 0
                ? abs($daysRemaining).' days overdue'
                : ($daysRemaining === 0 ? 'Expired today' : $daysRemaining.' days left');

            $departmentName = $employee->departmentRelation?->name ?? $employee->department ?? 'Unassigned';
            $branchName = $employee->branch?->name ?? $employee->departmentRelation?->branch?->name ?? null;

            $statusLabel = $daysRemaining === 0 ? 'Expired today' : ($daysRemaining < 0 ? 'Overdue' : ($daysRemaining <= 30 ? 'Due Soon' : 'On Track'));
            $indicator = $daysRemaining <= 30 ? 'red' : ($daysRemaining <= 60 ? 'orange' : 'green');

            $statusCanonical = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);
            $contractType = $statusCanonical && in_array($statusCanonical, ['Contractual', 'Project-based'], true)
                ? $statusCanonical
                : ($employee->employment_type ? ucwords(str_replace(['_', '-'], ' ', (string) $employee->employment_type)) : 'Contractual');

            $recommendedAction = match (true) {
                $daysRemaining <= 0 => 'Prepare Final Pay',
                $daysRemaining <= 60 => 'Review Extension',
                default => 'Renew Contract',
            };

            return [
                'id' => $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'profile_image_url' => $employee->profile_image_url,
                'employee_code' => $employee->employee_code,
                'position' => $employee->position,
                'employment_type' => $employee->employment_type,
                'employment_status' => $employee->employment_status,
                'department' => $departmentName,
                'branch' => $branchName,
                // Contract metadata (used by submit modal defaults + display)
                'contract_start_date' => $start?->toDateString(),
                'contract_end_date' => $end->toDateString(),
                // Keep a consistent table shape with milestone rows:
                'hire_date' => $employee->hire_date?->toDateString(),
                'service_length_label' => null,
                'months_since_hire' => null,
                'days_remaining' => $daysRemaining,
                'days_remaining_label' => $daysRemainingLabel,
                'next_milestone' => 'Contract end',
                'next_milestone_date' => $end->toDateString(),
                'recommended_action' => $recommendedAction,
                'status_label' => $statusLabel,
                'indicator' => $indicator,
                'indicator_label' => $statusLabel,
                // Backward-compatible milestones bag (so UI helpers don't crash)
                'milestones' => [
                    'hire_date' => $employee->hire_date?->toDateString(),
                    'three_months' => null,
                    'five_months' => null,
                    'six_months' => null,
                ],
                'approaching_milestone' => null,
                'probation_review_phase' => null,
                // Preserve employment type label in table column (uses employment_typeLabel)
                'contract_type' => $contractType,
                'actions' => [
                    // Reuse existing "Submit Recommendation" CTA; actual allowed types are enforced server-side.
                    'can_recommend_early' => false,
                ],
            ];
        })->filter()->values();

        $merged = $employees->concat($contractRows)->values()->all();

        return response()->json(['employees' => $merged]);
    }

    /**
     * Probationary queue: 3-month early path, 5/6-month windows, or tenure at/after 5 months (PH regularization practice).
     */
    private function shouldIncludeInRegularizationQueue(
        float $monthsSinceHire,
        ?string $approaching,
        ?string $phase,
        int $daysAhead,
        ?int $threeMonthDays,
        ?int $fiveMonthDays,
        ?int $sixMonthDays
    ): bool {
        if ($monthsSinceHire >= 4.0) {
            return true;
        }

        if ($approaching === '3_months' || $approaching === '5_months' || $approaching === '6_months') {
            return true;
        }

        foreach ([$threeMonthDays, $fiveMonthDays, $sixMonthDays] as $days) {
            if ($days === null) {
                continue;
            }
            // include approaching (future within window) and overdue milestones (negative)
            if ($days <= $daysAhead) {
                return true;
            }
        }

        if (in_array($phase, ['approaching_five_month', 'five_month_review', 'six_month_decision'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Pick nearest upcoming milestone; if all passed, pick latest passed (usually 6-month).
     *
     * @return array{milestone: string|null, days: int|null}
     */
    private function resolveNextMilestone(?int $three, ?int $five, ?int $six): array
    {
        $items = collect([
            ['milestone' => '3_months', 'days' => $three],
            ['milestone' => '5_months', 'days' => $five],
            ['milestone' => '6_months', 'days' => $six],
        ])->filter(fn ($x) => $x['days'] !== null)->values();

        if ($items->isEmpty()) {
            return ['milestone' => null, 'days' => null];
        }

        $upcoming = $items->filter(fn ($x) => $x['days'] >= 0)->sortBy('days')->first();
        if ($upcoming) {
            return $upcoming;
        }

        return $items->sortByDesc('days')->first();
    }
}
