<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentStatusSetting;
use App\Models\RegularizationRecommendation;
use App\Models\RegularizationRequirement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeStatusService
{
    public function __construct(
        private readonly LeaveCreditService $leaveCreditService,
    ) {}

    /**
     * Probationary employees who have completed 4 months of service (reference for regularization queue).
     * Does not imply automatic status change — 6-month regularization is an HR decision, not automated.
     */
    public function isEligibleForSixMonthRegularization(User $employee, ?Carbon $asOfDate = null): bool
    {
        $asOfDate = $asOfDate ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));

        if (! $this->canBeRegularized($employee)) {
            return false;
        }

        if (! $employee->hire_date) {
            return false;
        }

        $months = $this->getAutomationSettings()['auto_regularization_months'];
        $sixMonthsDate = Carbon::parse($employee->hire_date)->addMonths($months);

        return $asOfDate->greaterThanOrEqualTo($sixMonthsDate) && $this->hasCompletedRequiredActions($employee);
    }

    /**
     * Evaluate if employee is eligible for 3-month early regularization.
     * Requires approved recommendation from immediate head.
     */
    public function isEligibleForThreeMonthRegularization(User $employee, ?Carbon $asOfDate = null): bool
    {
        $asOfDate = $asOfDate ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));

        if (! $this->canBeRegularized($employee)) {
            return false;
        }

        if (! $employee->hire_date) {
            return false;
        }

        $months = $this->getAutomationSettings()['early_regularization_months'];
        $threeMonthsDate = Carbon::parse($employee->hire_date)->addMonths($months);
        if ($asOfDate->lessThan($threeMonthsDate)) {
            return false;
        }

        // Must have approved recommendation and completed required actions.
        return $this->hasApprovedRecommendation($employee) && $this->hasCompletedRequiredActions($employee);
    }

    /**
     * Check if employee has an approved regularization recommendation.
     */
    public function hasApprovedRecommendation(User $employee): bool
    {
        return RegularizationRecommendation::query()
            ->where('user_id', $employee->id)
            ->where('status', RegularizationRecommendation::STATUS_APPROVED)
            ->where('processed', false)
            ->exists();
    }

    /**
     * Check if employee can be regularized (probationary, active, not separated).
     */
    public function canBeRegularized(User $employee): bool
    {
        if (! $employee->is_active) {
            return false;
        }

        $status = $this->parseStatus($employee->employment_status);
        if (! $status || ! $status->canBeRegularized()) {
            return false;
        }

        return true;
    }

    /**
     * Regularize an employee (change status to Regular).
     */
    public function regularizeEmployee(
        User $employee,
        string $triggerType,
        ?User $actor = null,
        ?string $remarks = null,
        ?Carbon $effectiveDate = null
    ): EmployeeStatusHistory {
        $effectiveDate = $effectiveDate ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));
        if (! $this->hasCompletedRequiredActions($employee)) {
            throw ValidationException::withMessages([
                'required_actions' => ['Performance review and checklist must be completed before regularization.'],
            ]);
        }

        return DB::transaction(function () use ($employee, $triggerType, $actor, $remarks, $effectiveDate) {
            $previousStatus = $employee->employment_status;

            // Update employee status
            $employee->employment_status = EmploymentStatus::Regular->value;
            $employee->employment_status_effective_date = $effectiveDate;
            $employee->save();

            // Create history record
            $history = EmployeeStatusHistory::create([
                'user_id' => $employee->id,
                'previous_status' => $previousStatus,
                'new_status' => EmploymentStatus::Regular->value,
                'effective_date' => $effectiveDate,
                'trigger_type' => $triggerType,
                'actor_id' => $actor?->id,
                'remarks' => $remarks,
            ]);

            // Mark recommendation as processed if exists
            if (in_array($triggerType, ['head_recommendation', 'system_automation', 'hr_approval'], true)) {
                RegularizationRecommendation::query()
                    ->where('user_id', $employee->id)
                    ->where('status', RegularizationRecommendation::STATUS_APPROVED)
                    ->where('processed', false)
                    ->update([
                        'processed' => true,
                        'processed_at' => now(),
                    ]);
            }

            Log::info('Employee regularized', [
                'user_id' => $employee->id,
                'trigger_type' => $triggerType,
                'actor_id' => $actor?->id,
                'effective_date' => $effectiveDate->toDateString(),
            ]);

            $this->leaveCreditService->grantAnnualAllocationOnRegularizationIfEligible($employee->fresh(), $actor);
            $this->leaveCreditService->forgetSummaryCacheForUser((int) $employee->id);

            return $history;
        });
    }

    /**
     * Change employee status with audit trail.
     */
    public function changeStatus(
        User $employee,
        EmploymentStatus $newStatus,
        string $triggerType,
        ?User $actor = null,
        ?string $remarks = null,
        ?Carbon $effectiveDate = null
    ): EmployeeStatusHistory {
        $effectiveDate = $effectiveDate ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));

        return DB::transaction(function () use ($employee, $newStatus, $triggerType, $actor, $remarks, $effectiveDate) {
            $previousStatus = $employee->employment_status;

            $employee->employment_status = $newStatus->value;
            $employee->employment_status_effective_date = $effectiveDate;
            $employee->save();

            $history = EmployeeStatusHistory::create([
                'user_id' => $employee->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus->value,
                'effective_date' => $effectiveDate,
                'trigger_type' => $triggerType,
                'actor_id' => $actor?->id,
                'remarks' => $remarks,
            ]);

            Log::info('Employee status changed', [
                'user_id' => $employee->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus->value,
                'trigger_type' => $triggerType,
            ]);
            $this->leaveCreditService->forgetSummaryCacheForUser((int) $employee->id);

            return $history;
        });
    }

    /**
     * Get months since hire date.
     */
    public function getMonthsSinceHire(User $employee, ?Carbon $asOfDate = null): ?float
    {
        if (! $employee->hire_date) {
            return null;
        }

        $asOfDate = $asOfDate ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));
        $hireDate = Carbon::parse($employee->hire_date);

        return $hireDate->floatDiffInMonths($asOfDate);
    }

    /**
     * Get milestone dates for probationary employee.
     */
    public function getMilestoneDates(User $employee): ?array
    {
        if (! $employee->hire_date) {
            return null;
        }

        $hireDate = Carbon::parse($employee->hire_date);

        $settings = $this->getAutomationSettings();
        $earlyMonths = $settings['early_regularization_months'];
        $autoMonths = $settings['auto_regularization_months'];
        $reviewMonths = max(0, $autoMonths - 1);

        return [
            'hire_date' => $hireDate->toDateString(),
            'three_months' => $hireDate->copy()->addMonths($earlyMonths)->toDateString(),
            'five_months' => $hireDate->copy()->addMonths($reviewMonths)->toDateString(),
            'six_months' => $hireDate->copy()->addMonths($autoMonths)->toDateString(),
        ];
    }

    /**
     * Human-readable phase for probation regularization (PH practice: 5-month review alert, 6-month HR decision).
     */
    public function getProbationReviewPhase(User $employee, ?Carbon $asOf = null): ?string
    {
        if (! $this->canBeRegularized($employee) || ! $employee->hire_date) {
            return null;
        }

        $asOf = $asOf ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'));
        $months = $this->getMonthsSinceHire($employee, $asOf);
        if ($months === null) {
            return null;
        }

        if ($months < 4) {
            return 'before_four_months';
        }
        if ($months < 5) {
            return 'approaching_five_month';
        }
        if ($months < 6) {
            return 'five_month_review';
        }

        return 'six_month_decision';
    }

    /**
     * Check if employee is approaching a probation milestone (for notifications / upcoming lists).
     */
    public function isApproachingMilestone(User $employee, int $daysBeforeMilestone = 30): ?string
    {
        if (! $employee->hire_date || ! $this->canBeRegularized($employee)) {
            return null;
        }

        $now = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->startOfDay();
        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        $settings = $this->getAutomationSettings();
        $threeMonths = $hireDate->copy()->addMonths($settings['early_regularization_months']);
        $fiveMonths = $hireDate->copy()->addMonths(max(0, $settings['auto_regularization_months'] - 1));
        $sixMonths = $hireDate->copy()->addMonths($settings['auto_regularization_months']);

        $daysToThree = $now->diffInDays($threeMonths, false);
        $daysToFive = $now->diffInDays($fiveMonths, false);
        $daysToSix = $now->diffInDays($sixMonths, false);

        if ($daysToThree >= 0 && $daysToThree <= $daysBeforeMilestone) {
            return '3_months';
        }

        if ($daysToFive >= 0 && $daysToFive <= $daysBeforeMilestone) {
            return '5_months';
        }

        if ($daysToSix >= 0 && $daysToSix <= $daysBeforeMilestone) {
            return '6_months';
        }

        return null;
    }

    private function parseStatus(?string $status): ?EmploymentStatus
    {
        if (! $status) {
            return null;
        }

        return EmploymentStatus::tryFrom($status);
    }

    /**
     * Required actions gate for probation confirmation.
     */
    public function hasCompletedRequiredActions(User $employee): bool
    {
        if (! Schema::hasTable('regularization_requirements')) {
            return false;
        }

        $req = RegularizationRequirement::query()->where('user_id', $employee->id)->first();
        if (! $req) {
            return false;
        }

        // Lightweight checklist (4 hardcoded items for now).
        return (bool) $req->performance_review_completed
            && (bool) $req->checklist_completed
            && (bool) ($req->training_completed ?? false)
            && (bool) ($req->documents_submitted ?? false)
            && (bool) ($req->manager_recommendation_received ?? false);
    }

    /**
     * Return persisted required-action state; defaults to pending if not yet tracked.
     */
    public function getRequiredActions(User $employee): array
    {
        if (! Schema::hasTable('regularization_requirements')) {
            return [
                'performance_review_completed' => false,
                'checklist_completed' => false,
                'training_completed' => false,
                'documents_submitted' => false,
                'manager_recommendation_received' => false,
                'all_completed' => false,
            ];
        }

        $req = RegularizationRequirement::query()->where('user_id', $employee->id)->first();
        if (! $req) {
            return [
                'performance_review_completed' => false,
                'checklist_completed' => false,
                'training_completed' => false,
                'documents_submitted' => false,
                'manager_recommendation_received' => false,
                'all_completed' => false,
            ];
        }

        $allCompleted = (bool) $req->performance_review_completed
            && (bool) $req->checklist_completed
            && (bool) ($req->training_completed ?? false)
            && (bool) ($req->documents_submitted ?? false)
            && (bool) ($req->manager_recommendation_received ?? false);

        return [
            'performance_review_completed' => (bool) $req->performance_review_completed,
            'performance_review_notes' => $req->performance_review_notes,
            'performance_review_completed_at' => $req->performance_review_completed_at?->toIso8601String(),
            'performance_review_completed_by' => $req->performance_review_completed_by ?? null,
            'checklist_completed' => (bool) $req->checklist_completed,
            'checklist_notes' => $req->checklist_notes,
            'checklist_completed_at' => $req->checklist_completed_at?->toIso8601String(),
            'checklist_completed_by' => $req->checklist_completed_by ?? null,
            'training_completed' => (bool) ($req->training_completed ?? false),
            'training_completed_at' => $req->training_completed_at?->toIso8601String(),
            'training_completed_by' => $req->training_completed_by ?? null,
            'documents_submitted' => (bool) ($req->documents_submitted ?? false),
            'documents_submitted_at' => $req->documents_submitted_at?->toIso8601String(),
            'documents_submitted_by' => $req->documents_submitted_by ?? null,
            'manager_recommendation_received' => (bool) ($req->manager_recommendation_received ?? false),
            'manager_recommendation_received_at' => $req->manager_recommendation_received_at?->toIso8601String(),
            'manager_recommendation_received_by' => $req->manager_recommendation_received_by ?? null,
            'all_completed' => $allCompleted,
        ];
    }

    /**
     * Admin-configurable thresholds and status options.
     */
    public function getAutomationSettings(): array
    {
        $configAuto = (int) config('employment.regularization.auto_months', 6);
        $configEarly = (int) config('employment.regularization.early_months', 3);

        $dbAuto = EmploymentStatusSetting::getValue('auto_regularization_months');
        $dbEarly = EmploymentStatusSetting::getValue('early_regularization_months');

        $auto = is_numeric($dbAuto) ? (int) $dbAuto : $configAuto;
        $early = is_numeric($dbEarly) ? (int) $dbEarly : $configEarly;

        return [
            'auto_regularization_months' => max(1, $auto),
            'early_regularization_months' => max(1, min(max(1, $auto), $early)),
            'status_options' => $this->getConfiguredStatuses(),
        ];
    }

    /**
     * Status options with DB override (CSV in employment_status_settings.value).
     *
     * @return array<int, string>
     */
    public function getConfiguredStatuses(): array
    {
        $default = config('employment.statuses', [
            EmploymentStatus::Probationary->value,
            EmploymentStatus::Regular->value,
            EmploymentStatus::Contractual->value,
            EmploymentStatus::ProjectBased->value,
            EmploymentStatus::Separated->value,
        ]);
        $db = EmploymentStatusSetting::getValue('status_options');
        if (! is_string($db) || trim($db) === '') {
            return array_values(array_unique($default));
        }
        $parsed = collect(explode(',', $db))
            ->map(fn ($v) => strtolower(trim((string) $v)))
            ->filter()
            ->values()
            ->all();
        if ($parsed === []) {
            return array_values(array_unique($default));
        }

        return array_values(array_unique($parsed));
    }
}
