<?php

namespace App\Services;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentExamAssignment;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Support\RecruitmentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecruitmentStageActionService
{
    private const INTERVIEW_MODES = ['Onsite', 'Online', 'Phone'];

    private const INITIAL_RESULTS = ['Pending', 'Scheduled', 'Passed', 'Failed', 'No Show', 'Reschedule'];

    private const FINAL_RESULTS = ['Pending', 'Scheduled', 'Passed', 'Failed', 'No Show', 'Reschedule', 'Hold'];

    public function __construct(
        private readonly RecruitmentListCacheService $listCache,
    ) {}

    /**
     * @return array{applicant: RecruitmentApplicant, list_row: array<string, mixed>, affected_tabs: list<string>, invalidate_action: string}
     */
    public function handle(Request $request, RecruitmentApplicant $applicant): array
    {
        $validated = $request->validate([
            'stage' => ['required', Rule::in(['initial', 'exam', 'final', 'requirements', 'hiring'])],
            'action' => ['required', 'string', 'max:64'],
            'interviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'interview_date' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(self::INTERVIEW_MODES)],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'result' => ['nullable', 'string', 'max:32'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'evaluation' => ['nullable', 'array'],
            'exam_template_id' => ['nullable', 'integer', 'exists:recruitment_exam_templates,id'],
            'scheduled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'one_time_access' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'max:100'],
            'require_login' => ['nullable', 'boolean'],
            'interview_id' => ['nullable', 'integer'],
        ]);

        $this->validateHrInterviewer($validated['interviewer_id'] ?? null);

        $invalidateAction = $validated['stage'].'_'.$validated['action'];

        $result = match ($validated['stage']) {
            'initial' => $this->handleInitial($request, $applicant, $validated),
            'exam' => $this->handleExam($request, $applicant, $validated),
            'final' => $this->handleFinal($request, $applicant, $validated),
            'requirements' => $this->handleRequirements($applicant, $validated),
            'hiring' => $this->handleHiring($request, $applicant, $validated),
            default => throw ValidationException::withMessages(['stage' => ['Unsupported stage.']]),
        };

        $affectedTabs = RecruitmentWorkflow::tabsToInvalidate($result['invalidate_action'] ?? $invalidateAction);
        $this->listCache->bumpTabs($affectedTabs);

        $fresh = $result['applicant']->fresh([
            'appliedPosition:id,name',
            'department:id,name',
            'interviews.interviewer:id,name,first_name,middle_name,last_name,suffix',
            'examAssignments.template:id,title,passing_score,duration_minutes',
        ]);

        return [
            'applicant' => $fresh,
            'list_row' => $this->listRowFromApplicant($fresh),
            'affected_tabs' => $affectedTabs,
            'invalidate_action' => $result['invalidate_action'] ?? $invalidateAction,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{applicant: RecruitmentApplicant, invalidate_action: string}
     */
    private function handleInitial(Request $request, RecruitmentApplicant $applicant, array $validated): array
    {
        $action = $validated['action'];
        $result = $this->resolveInterviewResult($action, $validated['result'] ?? null, self::INITIAL_RESULTS);

        $interview = $this->upsertInterview($applicant, 'initial', $validated, $result, $validated['interview_id'] ?? null);
        $this->syncApplicantAfterInitialInterview($applicant, $interview, $result);

        return [
            'applicant' => $applicant->fresh(),
            'invalidate_action' => match ($result) {
                'Passed' => 'initial_passed',
                'Failed' => 'initial_failed',
                'No Show' => 'initial_no_show',
                'Reschedule' => 'initial_reschedule',
                default => 'initial_mark_done',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{applicant: RecruitmentApplicant, invalidate_action: string}
     */
    private function handleFinal(Request $request, RecruitmentApplicant $applicant, array $validated): array
    {
        $action = $validated['action'];
        $result = $this->resolveInterviewResult($action, $validated['result'] ?? null, self::FINAL_RESULTS);

        $interview = $this->upsertInterview($applicant, 'final', $validated, $result, $validated['interview_id'] ?? null);
        $this->syncApplicantAfterFinalInterview($applicant, $interview, $result);

        return [
            'applicant' => $applicant->fresh(),
            'invalidate_action' => match ($result) {
                'Passed' => 'final_passed',
                'Failed' => 'final_failed',
                'No Show' => 'final_no_show',
                'Reschedule' => 'final_reschedule',
                default => 'final_mark_done',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{applicant: RecruitmentApplicant, invalidate_action: string}
     */
    private function handleExam(Request $request, RecruitmentApplicant $applicant, array $validated): array
    {
        $action = $validated['action'];

        if ($action === 'assign' || $action === 'reassign') {
            if (empty($validated['exam_template_id'])) {
                throw ValidationException::withMessages(['exam_template_id' => ['Exam template is required.']]);
            }

            $assignment = $action === 'reassign'
                ? $applicant->examAssignments()->latest('id')->first()
                : null;

            $assignmentPayload = [
                'exam_template_id' => $validated['exam_template_id'],
                'assigned_by' => $request->user()?->id,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'max_attempts' => $validated['max_attempts'] ?? 1,
                'one_time_access' => $validated['one_time_access'] ?? true,
                'password' => $validated['password'] ?? null,
                'require_login' => $validated['require_login'] ?? false,
                'status' => 'Assigned',
                'result' => null,
            ];

            if ($assignment) {
                $assignment->update($assignmentPayload);
            } else {
                RecruitmentExamAssignment::create([
                    'applicant_id' => $applicant->id,
                    'exam_link_token' => 'AGC-EXM-'.Str::upper(Str::random(7)),
                    'attempt_number' => 1,
                    ...$assignmentPayload,
                ]);
            }
            $applicant->update(['status' => 'For Exam']);

            return [
                'applicant' => $applicant->fresh(),
                'invalidate_action' => $action === 'reassign' ? 'exam_reassign' : 'exam_assign',
            ];
        }

        $assignment = $applicant->examAssignments()->latest('id')->first();
        if (! $assignment) {
            throw ValidationException::withMessages(['exam' => ['No exam assignment found for this applicant.']]);
        }

        if ($action === 'start') {
            $assignment->update([
                'started_at' => $assignment->started_at ?? now(),
                'status' => 'In Progress',
            ]);
            $applicant->update(['status' => 'For Exam']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'exam_assign'];
        }

        if ($action === 'complete') {
            $assignment->update([
                'submitted_at' => $assignment->submitted_at ?? now(),
                'status' => 'Submitted',
            ]);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'exam_complete'];
        }

        if ($action === 'reschedule') {
            $assignment->update([
                'scheduled_at' => $validated['scheduled_at'] ?? $assignment->scheduled_at ?? now(),
                'expires_at' => $validated['expires_at'] ?? now()->addDays(7),
                'status' => 'Assigned',
            ]);
            $applicant->update(['status' => 'For Exam']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'exam_assign'];
        }

        if ($action === 'passed') {
            $assignment->update([
                'result' => 'Passed',
                'status' => 'Checked',
                'score' => $assignment->score ?? (float) ($validated['score'] ?? 100),
            ]);
            $applicant->update(['status' => 'For Final Interview']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'exam_passed'];
        }

        if ($action === 'failed') {
            $assignment->update([
                'result' => 'Failed',
                'status' => 'Checked',
            ]);
            $applicant->update(['status' => 'Rejected']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'exam_failed'];
        }

        throw ValidationException::withMessages(['action' => ['Unsupported exam action.']]);
    }

    private function validateHrInterviewer(null|int|string $interviewerId): void
    {
        if (empty($interviewerId)) {
            return;
        }

        $exists = User::query()
            ->visibleEmployees()
            ->active()
            ->whereKey($interviewerId)
            ->where(function ($query): void {
                $query
                    ->whereHas('departmentRelation', function ($department): void {
                        $this->filterHrDepartmentName($department);
                    })
                    ->orWhere(function ($user): void {
                        $this->filterHrDepartmentColumn($user);
                    });
            })
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'interviewer_id' => ['The interviewer must be assigned to the HR Department.'],
            ]);
        }
    }

    private function filterHrDepartmentName($query): void
    {
        $query
            ->whereIn(DB::raw('LOWER(name)'), ['hr', 'hr department', 'human resources'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%human resource%'])
            ->orWhereRaw('LOWER(name) LIKE ?', ['%hr department%']);
    }

    private function filterHrDepartmentColumn($query): void
    {
        $query
            ->whereIn(DB::raw('LOWER(department)'), ['hr', 'hr department', 'human resources'])
            ->orWhereRaw('LOWER(department) LIKE ?', ['%human resource%'])
            ->orWhereRaw('LOWER(department) LIKE ?', ['%hr department%']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{applicant: RecruitmentApplicant, invalidate_action: string}
     */
    private function handleRequirements(RecruitmentApplicant $applicant, array $validated): array
    {
        $action = $validated['action'];

        if ($action === 'move_hiring_approval' || $action === 'mark_complete') {
            $applicant->update(['status' => 'For Hiring Approval']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'move_hiring_approval'];
        }

        throw ValidationException::withMessages(['action' => ['Unsupported requirements action.']]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{applicant: RecruitmentApplicant, invalidate_action: string}
     */
    private function handleHiring(Request $request, RecruitmentApplicant $applicant, array $validated): array
    {
        if ($validated['action'] === 'approve') {
            $applicant->update(['status' => 'Hired']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'approve_hiring'];
        }

        if ($validated['action'] === 'reject') {
            $applicant->update(['status' => 'Rejected']);

            return ['applicant' => $applicant->fresh(), 'invalidate_action' => 'reject_hiring'];
        }

        throw ValidationException::withMessages(['action' => ['Unsupported hiring action. Use hiring-action endpoint for employee conversion.']]);
    }

    private function resolveInterviewResult(string $action, ?string $provided, array $allowed): string
    {
        if ($provided && in_array($provided, $allowed, true)) {
            return $provided;
        }

        return match ($action) {
            'passed' => 'Passed',
            'failed' => 'Failed',
            'no_show' => 'No Show',
            'reschedule' => 'Reschedule',
            'mark_done' => $provided ?: 'Pending',
            default => throw ValidationException::withMessages(['action' => ['Unsupported interview action.']]),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function upsertInterview(
        RecruitmentApplicant $applicant,
        string $type,
        array $validated,
        string $result,
        ?int $interviewId,
    ): RecruitmentInterview {
        $payload = array_filter([
            'interview_type' => $type,
            'interviewer_id' => $validated['interviewer_id'] ?? null,
            'interview_date' => $validated['interview_date'] ?? now(),
            'mode' => $validated['mode'] ?? 'Onsite',
            'score' => $validated['score'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'result' => $result,
            'next_step' => $validated['next_step'] ?? null,
            'evaluation' => $validated['evaluation'] ?? null,
        ], fn ($value) => $value !== null);

        if ($interviewId) {
            $interview = RecruitmentInterview::where('applicant_id', $applicant->id)->findOrFail($interviewId);
            $interview->update($payload);

            return $interview->fresh();
        }

        $existing = $applicant->interviews()->where('interview_type', $type)->latest('interview_date')->latest('id')->first();
        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return $applicant->interviews()->create($payload);
    }

    private function syncApplicantAfterInitialInterview(RecruitmentApplicant $applicant, RecruitmentInterview $interview, string $result): void
    {
        $status = match ($result) {
            'Passed' => 'For Exam',
            'Failed' => 'Rejected',
            'No Show', 'Reschedule', 'Pending', 'Scheduled' => 'For Initial Interview',
            default => $applicant->status,
        };
        $applicant->update(['status' => $status]);
    }

    private function syncApplicantAfterFinalInterview(RecruitmentApplicant $applicant, RecruitmentInterview $interview, string $result): void
    {
        $status = match ($result) {
            'Passed' => 'For Requirements',
            'Failed' => 'Rejected',
            'No Show', 'Reschedule', 'Pending', 'Scheduled', 'Hold' => 'For Final Interview',
            default => $applicant->status,
        };
        $applicant->update(['status' => $status]);
    }

    public function listRowFromApplicant(RecruitmentApplicant $applicant): array
    {
        $initial = $applicant->interviews?->firstWhere('interview_type', 'initial');
        $final = $applicant->interviews?->firstWhere('interview_type', 'final');
        $exam = $applicant->examAssignments?->sortByDesc('id')->first();

        return [
            'id' => $applicant->id,
            'applicant_id' => $applicant->id,
            'applicant_no' => $applicant->applicant_no,
            'applicant_name' => $applicant->full_name,
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'full_name' => $applicant->full_name,
            'position_applied' => $applicant->applied_position ?: $applicant->appliedPosition?->name,
            'applied_position' => $applicant->applied_position ?: $applicant->appliedPosition?->name,
            'company' => $applicant->department?->name,
            'branch' => null,
            'department_name' => $applicant->department?->name,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'source' => $applicant->source,
            'status' => $applicant->status,
            'current_stage' => RecruitmentWorkflow::currentStage($applicant->status),
            'recruitment_status' => RecruitmentWorkflow::recruitmentStatus($applicant, $initial, $final, $exam),
            'schedule_date' => $initial?->interview_date?->toIso8601String() ?? $final?->interview_date?->toIso8601String(),
            'result_status' => $initial?->result ?? $final?->result ?? $exam?->result ?? $exam?->status,
            'initial_interview_status' => $initial?->result,
            'initial_interview_date' => $initial?->interview_date?->toIso8601String(),
            'exam_status' => $exam?->result ?? $exam?->status,
            'final_interview_status' => $final?->result,
            'final_interview_date' => $final?->interview_date?->toIso8601String(),
            'requirements_status' => $this->requirementsStatusFromCounts($applicant),
            'last_activity' => $applicant->updated_at?->toIso8601String(),
            'date_applied' => $applicant->date_applied?->format('Y-m-d'),
            'action_flags' => RecruitmentWorkflow::actionFlags($applicant, $initial, $final, $exam),
        ];
    }

    private function requirementsStatusFromCounts(RecruitmentApplicant $applicant): string
    {
        $total = (int) ($applicant->documents_count ?? 0);
        if ($total === 0) {
            return 'Pending';
        }
        if ((int) ($applicant->rejected_documents_count ?? 0) > 0) {
            return 'Rejected';
        }
        if ((int) ($applicant->verified_documents_count ?? 0) === $total) {
            return 'Verified';
        }

        return 'Pending';
    }
}
