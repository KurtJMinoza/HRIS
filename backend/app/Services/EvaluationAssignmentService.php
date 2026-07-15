<?php

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EvaluationAssignmentService
{
    public function __construct(
        private readonly EvaluationEvaluatorResolver $evaluatorResolver,
        private readonly EvaluationPrefillService $prefillService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $evaluatorRoles
     * @param  list<int>  $customEvaluatorIds
     * @return list<EvaluationAssignment>
     */
    public function createBulk(
        User $creator,
        EvaluationForm $form,
        array $employeeIds,
        array $evaluatorRoles,
        array $customEvaluatorIds,
        string $startDate,
        string $endDate,
        ?array $reminderDays = null,
    ): array {
        $assignments = [];

        DB::transaction(function () use (
            &$assignments,
            $creator,
            $form,
            $employeeIds,
            $evaluatorRoles,
            $customEvaluatorIds,
            $startDate,
            $endDate,
            $reminderDays,
        ) {
            foreach ($employeeIds as $employeeId) {
                $employee = User::query()->findOrFail((int) $employeeId);
                $evaluators = $this->evaluatorResolver->resolve($employee, $evaluatorRoles, $customEvaluatorIds);

                if ($evaluators === []) {
                    continue;
                }

                $companyId = (int) ($employee->company_id ?? $form->company_id);
                $orgAssignment = $employee->organizationAssignments()->first();

                $assignment = EvaluationAssignment::create([
                    'company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'evaluation_form_id' => $form->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reminder_days' => $reminderDays ?? [7, 3, 1],
                    'status' => EvaluationAssignment::STATUS_PENDING,
                    'created_by' => $creator->id,
                    'assigned_at' => now(),
                ]);

                foreach ($evaluators as $entry) {
                    $evaluator = $entry['user'];
                    $hrRole = $this->hrRoleResolver->resolve($evaluator)?->value;
                    $scores = $this->prefillService->mergePrefill(
                        [],
                        $form->survey_json,
                        $employee,
                        $evaluator,
                        $hrRole,
                    );

                    Evaluation::create([
                        'company_id' => $companyId,
                        'branch_id' => $orgAssignment?->branch_id,
                        'department_id' => $orgAssignment?->department_id,
                        'evaluation_form_id' => $form->id,
                        'evaluation_assignment_id' => $assignment->id,
                        'employee_id' => $employee->id,
                        'evaluator_id' => $evaluator->id,
                        'evaluator_role' => $entry['role'],
                        'scores' => $scores,
                        'status' => Evaluation::STATUS_DRAFT,
                    ]);

                    $this->notifyAssigned($assignment, $employee, $evaluator, $form);
                }

                $assignments[] = $assignment->fresh([
                    'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                    'evaluationForm:id,title',
                    'evaluations.evaluator:id,first_name,middle_name,last_name,suffix',
                ]);
            }
        });

        return $assignments;
    }

    public function syncAssignmentStatus(EvaluationAssignment $assignment): void
    {
        $assignment->load('evaluations:id,evaluation_assignment_id,status');

        $total = $assignment->evaluations->count();
        if ($total === 0) {
            return;
        }

        $completed = $assignment->evaluations
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->count();

        if ($completed === $total) {
            $assignment->update([
                'status' => EvaluationAssignment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return;
        }

        $anyStarted = $assignment->evaluations
            ->contains(fn (Evaluation $e) => in_array($e->status, [
                Evaluation::STATUS_DRAFT,
                Evaluation::STATUS_SUBMITTED,
                Evaluation::STATUS_UNDER_REVIEW,
                Evaluation::STATUS_COMPLETED,
            ], true));

        $assignment->update([
            'status' => $anyStarted
                ? EvaluationAssignment::STATUS_IN_PROGRESS
                : EvaluationAssignment::STATUS_PENDING,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  list<int>|null  $scopedEmployeeIds
     */
    public function buildAssignmentsQuery(?array $scopedEmployeeIds): \Illuminate\Database\Eloquent\Builder
    {
        $query = EvaluationAssignment::query()
            ->with([
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluationForm:id,title',
                'evaluations:id,evaluation_assignment_id,status,evaluator_id,evaluator_role',
                'evaluations.evaluator:id,first_name,middle_name,last_name,suffix',
            ])
            ->orderByDesc('created_at');

        if ($scopedEmployeeIds !== null) {
            $query->whereIn('employee_id', $scopedEmployeeIds);
        }

        return $query;
    }

    /**
     * Evaluations assigned to the current user as evaluator (for heads/employees).
     */
    public function pendingForEvaluator(User $user): Collection
    {
        return Evaluation::query()
            ->with([
                'evaluationForm:id,title,survey_json',
                'employee:id,first_name,middle_name,last_name,suffix,profile_image,position',
                'evaluationAssignment:id,start_date,end_date,status',
            ])
            ->where('evaluator_id', $user->id)
            ->where('status', Evaluation::STATUS_DRAFT)
            ->whereHas('evaluationAssignment', fn ($q) => $q
                ->whereNotIn('status', [EvaluationAssignment::STATUS_CANCELLED, EvaluationAssignment::STATUS_COMPLETED]))
            ->orderBy('created_at')
            ->get();
    }

    private function notifyAssigned(
        EvaluationAssignment $assignment,
        User $employee,
        User $evaluator,
        EvaluationForm $form,
    ): void {
        $employeeName = trim($employee->first_name . ' ' . $employee->last_name);
        $isSelfEvaluation = (int) $employee->id === (int) $evaluator->id;
        $message = $isSelfEvaluation
            ? "You have been assigned to complete your self evaluation using \"{$form->title}\"."
            : "You have been assigned to evaluate {$employeeName} using \"{$form->title}\".";

        $this->notificationService->notifyUser($evaluator, [
            'type' => 'evaluation_assigned',
            'module' => 'evaluations',
            'title' => $isSelfEvaluation ? 'Self Evaluation Assigned' : 'Performance Evaluation Assigned',
            'message' => $message,
            'entity_id' => $assignment->id,
            'entity_type' => EvaluationAssignment::class,
            'action_url' => '/employee/evaluations',
            'company_id' => $assignment->company_id,
            'data' => [
                'assignment_id' => $assignment->id,
                'employee_id' => $employee->id,
                'form_id' => $form->id,
            ],
        ]);
    }
}
