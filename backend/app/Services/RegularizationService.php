<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RegularizationService
{
    public function __construct(
        private readonly EmployeeStatusService $statusService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly DataScopeService $dataScopeService,
        private readonly TaxComputationService $taxComputationService,
        private readonly LeaveCreditService $leaveCreditService,
    ) {}

    public function assertAdminHr(User $user): void
    {
        abort_unless(
            $this->hrRoleResolver->isAdminHrAccount($user),
            403,
            'Only HR administrators may manage regularization recommendations.'
        );
    }

    /**
     * HR submits a regularization recommendation (pending). Prefer {@see submitHrRecommendation()}.
     */
    public function submitRecommendation(
        User $employee,
        User $hrUser,
        ?string $notes = null
    ): RegularizationRecommendation {
        $this->assertAdminHr($hrUser);
        $this->validateEmployeeEligibleForRecommendationType(
            $employee,
            RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR,
            null,
            null
        );

        return $this->createPendingRecommendation($employee, $hrUser, $notes);
    }

    /**
     * Org heads or HR submit a recommendation. Only {@see HrRoleResolver::isAdminHrAccount()} may auto-complete (one-step approve).
     */
    public function submitHrRecommendation(
        User $employee,
        User $hrUser,
        ?string $notes = null,
        bool $autoComplete = true,
        ?string $recommendationType = null,
        ?string $effectiveDate = null,
        ?string $expirationDate = null
    ): RegularizationRecommendation {
        if (! $this->hrRoleResolver->maySubmitRegularization($hrUser)) {
            throw new AuthorizationException('You are not authorized to submit regularization recommendations.');
        }

        $this->dataScopeService->ensureEmployeeAccessible($hrUser, $employee);
        $type = (string) ($recommendationType ?: RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR);
        $this->validateEmployeeEligibleForRecommendationType($employee, $type, $effectiveDate, $expirationDate);

        $autoComplete = $this->hrRoleResolver->isAdminHrAccount($hrUser) && $autoComplete;

        return DB::transaction(function () use ($employee, $hrUser, $notes, $autoComplete, $recommendationType, $effectiveDate, $expirationDate) {
            $pending = $this->createPendingRecommendation($employee, $hrUser, $notes, $recommendationType, $effectiveDate, $expirationDate);

            if ($autoComplete) {
                return $this->approveRecommendation($pending->fresh(), $hrUser, $notes);
            }

            return $pending;
        });
    }

    /**
     * HR approves a pending regularization recommendation.
     * If the employee has reached the 3-month hire anniversary → Regular immediately.
     * If not → set effective_date to the 3-month anniversary; automation applies Regular on that date.
     */
    public function approveRecommendation(
        RegularizationRecommendation $recommendation,
        User $hrUser,
        ?string $notes = null
    ): RegularizationRecommendation {
        if (! $this->hrRoleResolver->isAdminHrAccount($hrUser)) {
            throw ValidationException::withMessages([
                'hr' => ['Only HR administrators may approve regularization recommendations.'],
            ]);
        }

        if ($recommendation->status !== RegularizationRecommendation::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'recommendation' => ['This recommendation has already been reviewed.'],
            ]);
        }

        return DB::transaction(function () use ($recommendation, $hrUser, $notes) {
            $recommendation->refresh();
            $employee = $recommendation->user;
            if (! $employee) {
                throw ValidationException::withMessages([
                    'recommendation' => ['Employee record is missing.'],
                ]);
            }

            $tz = config('attendance.timezone', 'Asia/Manila');
            $asOf = Carbon::now($tz)->startOfDay();

            $type = (string) ($recommendation->recommendation_type ?? RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR);

            // Probation -> Regular (existing workflow)
            if ($type === RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR) {
                $hire = Carbon::parse($employee->hire_date)->startOfDay();
                $threeMonthAnniversary = $hire->copy()->addMonths(3)->startOfDay();

                $shouldRegularizeNow = $asOf->greaterThanOrEqualTo($threeMonthAnniversary)
                    && $this->statusService->canBeRegularized($employee);

                $plannedEffective = $recommendation->effective_date
                    ? Carbon::parse($recommendation->effective_date)->startOfDay()
                    : $threeMonthAnniversary;

                $effectiveStored = $shouldRegularizeNow
                    ? $asOf->toDateString()
                    : $plannedEffective->toDateString();

                $recommendation->update([
                    'status' => RegularizationRecommendation::STATUS_APPROVED,
                    'hr_reviewed_by' => $hrUser->id,
                    'hr_reviewed_at' => now(),
                    'hr_notes' => $notes,
                    'effective_date' => $effectiveStored,
                ]);

                $recommendation = $recommendation->fresh();
                $employee = $recommendation->user;

                if ($shouldRegularizeNow && $employee) {
                    $this->statusService->regularizeEmployee(
                        $employee,
                        'hr_approval',
                        $hrUser,
                        $notes ?? 'HR approved regularization recommendation.',
                        Carbon::now($tz)
                    );
                    $this->taxComputationService->flagTaxReviewAfterEmploymentChange(
                        $employee->id,
                        'regularization_probation_to_regular'
                    );
                }

                return $recommendation->fresh();
            }

            // Contractual / Project-based workflow (renewal / extension / completion)
            $effective = $recommendation->effective_date
                ? Carbon::parse($recommendation->effective_date, $tz)->startOfDay()
                : $asOf;
            $expiration = $recommendation->expiration_date
                ? Carbon::parse($recommendation->expiration_date, $tz)->startOfDay()
                : null;

            if (in_array($type, [
                RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
                RegularizationRecommendation::TYPE_CONTRACT_EXTENSION,
                RegularizationRecommendation::TYPE_PROJECT_EXTENSION,
            ], true)) {
                if (! $expiration) {
                    throw ValidationException::withMessages([
                        'expiration_date' => ['Expiration date is required for contract/project extensions.'],
                    ]);
                }

                $targetStatus = $type === RegularizationRecommendation::TYPE_PROJECT_EXTENSION
                    ? EmploymentStatus::ProjectBased
                    : EmploymentStatus::Contractual;

                // Ensure employment status reflects the workflow; also update contract dates.
                if (EmploymentStatus::tryFrom((string) $employee->employment_status) !== $targetStatus) {
                    $this->statusService->changeStatus(
                        $employee,
                        $targetStatus,
                        'hr_approval',
                        $hrUser,
                        $notes ?? 'HR approved contract/project extension.',
                        $effective
                    );
                }

                $employee->contract_start_date = $effective->toDateString();
                $employee->contract_end_date = $expiration->toDateString();
                $employee->employment_status_effective_date = $effective->toDateString();
                $employee->save();

                if ($type === RegularizationRecommendation::TYPE_CONTRACT_RENEWAL) {
                    $this->leaveCreditService->applyContractRenewalCreditPolicy($employee->fresh(), $hrUser);
                }

                $recommendation->update([
                    'status' => RegularizationRecommendation::STATUS_APPROVED,
                    'hr_reviewed_by' => $hrUser->id,
                    'hr_reviewed_at' => now(),
                    'hr_notes' => $notes,
                    'processed' => true,
                    'processed_at' => now(),
                ]);

                return $recommendation->fresh();
            }

            if ($type === RegularizationRecommendation::TYPE_PROJECT_COMPLETION) {
                // Completion ends project-based employment; set employee to Separated on effective date.
                $this->statusService->changeStatus(
                    $employee,
                    EmploymentStatus::Separated,
                    'hr_approval',
                    $hrUser,
                    $notes ?? 'HR approved project completion.',
                    $effective
                );
                $employee->contract_end_date = $effective->toDateString();
                $employee->save();

                $recommendation->update([
                    'status' => RegularizationRecommendation::STATUS_APPROVED,
                    'hr_reviewed_by' => $hrUser->id,
                    'hr_reviewed_at' => now(),
                    'hr_notes' => $notes,
                    'processed' => true,
                    'processed_at' => now(),
                ]);

                return $recommendation->fresh();
            }

            $recommendation->update([
                'status' => RegularizationRecommendation::STATUS_APPROVED,
                'hr_reviewed_by' => $hrUser->id,
                'hr_reviewed_at' => now(),
                'hr_notes' => $notes,
                'processed' => true,
                'processed_at' => now(),
            ]);

            return $recommendation->fresh();
        });
    }

    /**
     * HR rejects a regularization recommendation.
     */
    public function rejectRecommendation(
        RegularizationRecommendation $recommendation,
        User $hrUser,
        string $reason
    ): RegularizationRecommendation {
        if (! $this->hrRoleResolver->isAdminHrAccount($hrUser)) {
            throw ValidationException::withMessages([
                'hr' => ['Only HR administrators may reject regularization recommendations.'],
            ]);
        }

        if ($recommendation->status !== RegularizationRecommendation::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'recommendation' => ['This recommendation has already been reviewed.'],
            ]);
        }

        return DB::transaction(function () use ($recommendation, $hrUser, $reason) {
            $recommendation->update([
                'status' => RegularizationRecommendation::STATUS_REJECTED,
                'hr_reviewed_by' => $hrUser->id,
                'hr_reviewed_at' => now(),
                'hr_notes' => $reason,
            ]);

            return $recommendation->fresh();
        });
    }

    /**
     * Whether the recommender may submit for this employee (org scope + role).
     */
    public function canSubmitRecommendation(User $recommender, User $employee): bool
    {
        if (! $this->hrRoleResolver->maySubmitRegularization($recommender)) {
            return false;
        }
        try {
            $this->dataScopeService->ensureEmployeeAccessible($recommender, $employee);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function getPendingRecommendations()
    {
        return RegularizationRecommendation::query()
            ->with(['user', 'recommendedBy'])
            ->where('status', RegularizationRecommendation::STATUS_PENDING)
            ->orderBy('recommended_at')
            ->get();
    }

    public function getRecommendationForEmployee(User $employee): ?RegularizationRecommendation
    {
        return RegularizationRecommendation::query()
            ->where('user_id', $employee->id)
            ->whereIn('status', [
                RegularizationRecommendation::STATUS_PENDING,
                RegularizationRecommendation::STATUS_APPROVED,
            ])
            ->where('processed', false)
            ->orderByDesc('recommended_at')
            ->first();
    }

    private function createPendingRecommendation(
        User $employee,
        User $hrUser,
        ?string $notes,
        ?string $recommendationType = null,
        ?string $effectiveDate = null,
        ?string $expirationDate = null
    ): RegularizationRecommendation {
        $existing = RegularizationRecommendation::query()
            ->where('user_id', $employee->id)
            ->where('status', RegularizationRecommendation::STATUS_PENDING)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'employee' => ['A pending recommendation already exists for this employee.'],
            ]);
        }

        $asOf = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->startOfDay();
        $activeApproved = RegularizationRecommendation::query()
            ->where('user_id', $employee->id)
            ->activeApproved($asOf)
            ->orderByDesc('recommended_at')
            ->first();
        if ($activeApproved) {
            throw ValidationException::withMessages([
                'employee' => ['An approved recommendation is already active for this employee.'],
            ]);
        }

        $hire = Carbon::parse($employee->hire_date)->startOfDay();
        $defaultThreeMo = $hire->copy()->addMonths(3)->toDateString();
        $defaultSixMo = $hire->copy()->addMonths(6)->toDateString();

        $type = $recommendationType ?: RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR;
        $effective = $effectiveDate
            ? Carbon::parse($effectiveDate)->toDateString()
            : (in_array($type, [
                RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
                RegularizationRecommendation::TYPE_CONTRACT_EXTENSION,
                RegularizationRecommendation::TYPE_PROJECT_EXTENSION,
                RegularizationRecommendation::TYPE_END_CONTRACT,
                RegularizationRecommendation::TYPE_PROJECT_COMPLETION,
            ], true) ? ($employee->contract_end_date?->toDateString() ?: $defaultSixMo) : $defaultThreeMo);

        // Persist expiration_date only for contractual/project-based extension workflows.
        $expirationRelevant = in_array($type, [
            RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
            RegularizationRecommendation::TYPE_CONTRACT_EXTENSION,
            RegularizationRecommendation::TYPE_PROJECT_EXTENSION,
        ], true);
        $expiration = $expirationRelevant && $expirationDate
            ? Carbon::parse($expirationDate)->toDateString()
            : null;

        $payload = [
            'user_id' => $employee->id,
            'recommended_by' => $hrUser->id,
            'recommendation_type' => $type,
            'recommendation_notes' => $notes,
            'effective_date' => $effective,
            'expiration_date' => $expiration,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ];

        // Backward-compatible safety: if migration hasn't been run yet, do not insert the column.
        if (! Schema::hasColumn('regularization_recommendations', 'expiration_date')) {
            unset($payload['expiration_date']);
        }

        return RegularizationRecommendation::create($payload);
    }

    private function validateEmployeeEligibleForRecommendationType(
        User $employee,
        string $type,
        ?string $effectiveDate,
        ?string $expirationDate
    ): void {
        $type = (string) $type;

        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'employee' => ['Employee is inactive.'],
            ]);
        }

        $status = EmploymentStatus::tryFrom((string) $employee->employment_status);
        if ($status === EmploymentStatus::Separated) {
            throw ValidationException::withMessages([
                'employee' => ['Employee is separated.'],
            ]);
        }

        if ($type === RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR
            || $type === RegularizationRecommendation::TYPE_PERFORMANCE_BASED
        ) {
            $this->validateEmployeeEligibleForRegularizationRecommendation($employee);

            return;
        }

        if (in_array($type, [
            RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
            RegularizationRecommendation::TYPE_CONTRACT_EXTENSION,
        ], true)) {
            $this->validateContractRecommendation($employee, $effectiveDate, $expirationDate);

            return;
        }

        if ($type === RegularizationRecommendation::TYPE_PROJECT_EXTENSION) {
            $this->validateProjectRecommendation($employee, $effectiveDate, $expirationDate);

            return;
        }

        if ($type === RegularizationRecommendation::TYPE_END_CONTRACT) {
            // Ending a contract is allowed only for contractual employees.
            if ($status !== EmploymentStatus::Contractual) {
                throw ValidationException::withMessages([
                    'employee' => ['Employee is not eligible for end-contract action.'],
                ]);
            }

            return;
        }

        if ($type === RegularizationRecommendation::TYPE_PROJECT_COMPLETION) {
            if ($status !== EmploymentStatus::ProjectBased) {
                throw ValidationException::withMessages([
                    'employee' => ['Employee is not eligible for project completion.'],
                ]);
            }

            return;
        }
    }

    private function validateContractRecommendation(User $employee, ?string $effectiveDate, ?string $expirationDate): void
    {
        $status = EmploymentStatus::tryFrom((string) $employee->employment_status);
        if ($status !== EmploymentStatus::Contractual) {
            throw ValidationException::withMessages([
                'employee' => ['Employee is not eligible for contract renewal.'],
            ]);
        }

        if (! $effectiveDate || trim($effectiveDate) === '') {
            throw ValidationException::withMessages([
                'effective_date' => ['Effective date is required for contract renewal.'],
            ]);
        }

        if (! $expirationDate || trim($expirationDate) === '') {
            throw ValidationException::withMessages([
                'expiration_date' => ['Contract end date is required for contract renewal.'],
            ]);
        }

        $eff = Carbon::parse($effectiveDate)->startOfDay();
        $exp = Carbon::parse($expirationDate)->startOfDay();
        if ($exp->lessThan($eff)) {
            throw ValidationException::withMessages([
                'expiration_date' => ['Contract end date must be on or after effective date.'],
            ]);
        }
    }

    private function validateProjectRecommendation(User $employee, ?string $effectiveDate, ?string $expirationDate): void
    {
        $status = EmploymentStatus::tryFrom((string) $employee->employment_status);
        if ($status !== EmploymentStatus::ProjectBased) {
            throw ValidationException::withMessages([
                'employee' => ['Employee is not eligible for project extension.'],
            ]);
        }

        if (! $effectiveDate || trim($effectiveDate) === '') {
            throw ValidationException::withMessages([
                'effective_date' => ['Effective date is required for project extension.'],
            ]);
        }

        if (! $expirationDate || trim($expirationDate) === '') {
            throw ValidationException::withMessages([
                'expiration_date' => ['Project end date is required for project extension.'],
            ]);
        }

        $eff = Carbon::parse($effectiveDate)->startOfDay();
        $exp = Carbon::parse($expirationDate)->startOfDay();
        if ($exp->lessThan($eff)) {
            throw ValidationException::withMessages([
                'expiration_date' => ['Project end date must be on or after effective date.'],
            ]);
        }
    }

    private function validateEmployeeEligibleForRegularizationRecommendation(User $employee): void
    {
        if (! $employee->hire_date) {
            throw ValidationException::withMessages([
                'employee' => ['Employee must have a hire date to be recommended for regularization.'],
            ]);
        }

        if (! $this->statusService->canBeRegularized($employee)) {
            throw ValidationException::withMessages([
                'employee' => ['Employee is not eligible for regularization.'],
            ]);
        }
    }
}
