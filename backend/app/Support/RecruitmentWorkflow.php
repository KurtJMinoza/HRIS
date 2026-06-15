<?php

namespace App\Support;

use App\Models\RecruitmentApplicant;
use Illuminate\Support\Str;
use App\Models\RecruitmentExamAssignment;
use App\Models\RecruitmentInterview;

class RecruitmentWorkflow
{
    /** @var array<string, list<string>> */
    public const TAB_STATUS_FILTERS = [
        'applicants' => [],
        'screening' => ['New'],
        'initial_interview' => ['New', 'For Initial Interview'],
        'exams' => ['For Exam', 'Exam Passed'],
        'final_interview' => ['For Final Interview', 'Final Interview Passed'],
        'requirements' => ['For Requirements'],
        'hiring_approval' => ['For Hiring Approval'],
        'hired_applicants' => ['Hired'],
        'rejected' => ['Rejected'],
    ];

    public static function currentStage(string $status): string
    {
        return match ($status) {
            'New', 'For Initial Interview' => 'initial_interview',
            'For Exam', 'Exam Passed' => 'exam',
            'For Final Interview', 'Final Interview Passed' => 'final_interview',
            'For Requirements' => 'requirements',
            'For Hiring Approval' => 'hiring_approval',
            'Hired' => 'hired',
            'Rejected' => 'rejected',
            default => 'applicants',
        };
    }

    public static function recruitmentStatus(
        RecruitmentApplicant $applicant,
        ?RecruitmentInterview $initial = null,
        ?RecruitmentInterview $final = null,
        ?RecruitmentExamAssignment $exam = null,
    ): string {
        $status = $applicant->status;

        if ($status === 'Rejected') {
            return self::failedRecruitmentStatus($initial, $final, $exam);
        }

        if ($initial?->result === 'No Show') {
            return 'interview_no_show';
        }
        if ($initial?->result === 'Reschedule') {
            return 'interview_rescheduled';
        }
        if (in_array($initial?->result, ['Pending', 'Scheduled'], true)) {
            return 'interview_scheduled';
        }
        if ($status === 'For Initial Interview' || $status === 'New') {
            return 'for_initial_interview';
        }

        if ($exam) {
            if ($exam->result === 'Passed') {
                return 'exam_completed';
            }
            if ($exam->result === 'Failed') {
                return 'failed_exam';
            }
            if ($exam->status === 'In Progress') {
                return 'exam_in_progress';
            }
            if ($exam->status === 'Submitted' || $exam->status === 'Pending Review') {
                return 'exam_completed';
            }
            if ($exam->status === 'Assigned') {
                return 'exam_assigned';
            }
        }
        if ($status === 'For Exam') {
            return 'for_exam';
        }
        if ($status === 'Exam Passed') {
            return 'for_final_interview';
        }

        if ($final?->result === 'No Show') {
            return 'final_interview_no_show';
        }
        if ($final?->result === 'Reschedule') {
            return 'final_interview_rescheduled';
        }
        if (in_array($final?->result, ['Pending', 'Scheduled'], true)) {
            return 'final_interview_scheduled';
        }
        if ($status === 'For Final Interview') {
            return 'for_final_interview';
        }
        if ($status === 'Final Interview Passed') {
            return 'requirements_pending';
        }
        if ($status === 'For Requirements') {
            return 'requirements_pending';
        }
        if ($status === 'For Hiring Approval') {
            return 'for_hiring_approval';
        }
        if ($status === 'Hired') {
            return 'hired';
        }

        return Str::slug($status, '_');
    }

    private static function failedRecruitmentStatus(
        ?RecruitmentInterview $initial,
        ?RecruitmentInterview $final,
        ?RecruitmentExamAssignment $exam,
    ): string {
        if ($final?->result === 'Failed') {
            return 'failed_final_interview';
        }
        if ($exam?->result === 'Failed') {
            return 'failed_exam';
        }
        if ($initial?->result === 'Failed') {
            return 'failed_initial_interview';
        }

        return 'rejected';
    }

    /**
     * @return array<string, bool>
     */
    public static function actionFlags(
        RecruitmentApplicant $applicant,
        ?RecruitmentInterview $initial = null,
        ?RecruitmentInterview $final = null,
        ?RecruitmentExamAssignment $exam = null,
    ): array {
        $stage = self::currentStage($applicant->status);

        return match ($stage) {
            'initial_interview' => [
                'mark_done' => true,
                'passed' => true,
                'failed' => true,
                'no_show' => true,
                'reschedule' => true,
                'add_notes' => true,
            ],
            'exam' => [
                'assign_exam' => $exam === null || $exam->status === 'Checked',
                'start_exam' => $exam !== null && in_array($exam->status, ['Assigned', 'In Progress'], true),
                'mark_completed' => $exam !== null && in_array($exam->status, ['Submitted', 'Pending Review'], true),
                'view_result' => $exam !== null && $exam->result !== null,
                'passed' => $exam !== null && $exam->result === 'Passed',
                'failed' => $exam !== null && in_array($exam->result, ['Failed', 'Pending Review'], true),
                'reassign_exam' => $exam !== null,
            ],
            'final_interview' => [
                'mark_done' => true,
                'passed' => true,
                'failed' => true,
                'no_show' => true,
                'reschedule' => true,
                'add_notes' => true,
            ],
            'requirements' => [
                'upload_requirement' => true,
                'verify_requirement' => true,
                'reject_requirement' => true,
                'mark_complete' => true,
                'move_hiring_approval' => true,
            ],
            'hiring_approval' => [
                'approve_hiring' => true,
                'reject_hiring' => true,
                'convert_employee' => $applicant->created_employee_id === null,
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function tabsToInvalidate(string $action): array
    {
        return match ($action) {
            'initial_passed' => ['initial_interview', 'exams', 'applicants'],
            'initial_failed' => ['initial_interview', 'rejected', 'applicants'],
            'initial_no_show', 'initial_reschedule', 'initial_mark_done' => ['initial_interview', 'applicants'],
            'exam_assign', 'exam_complete', 'exam_reassign' => ['exams', 'applicants'],
            'exam_passed' => ['exams', 'final_interview', 'applicants'],
            'final_passed' => ['final_interview', 'requirements', 'applicants'],
            'exam_failed' => ['exams', 'rejected', 'applicants'],
            'final_failed' => ['final_interview', 'rejected', 'applicants'],
            'final_no_show', 'final_reschedule', 'final_mark_done' => ['final_interview', 'applicants'],
            'requirements_complete', 'move_hiring_approval' => ['requirements', 'hiring_approval', 'applicants'],
            'approve_hiring', 'convert_employee' => ['hiring_approval', 'hired_applicants', 'applicants'],
            'reject_hiring' => ['hiring_approval', 'rejected', 'applicants'],
            'create', 'update', 'delete' => ['applicants'],
            default => ['applicants'],
        };
    }
}
