<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentExamAssignment;
use App\Models\RecruitmentExamTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecruitmentModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_hr_user_cannot_access_recruitment_admin_routes(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);

        $this->actingAs($employee)
            ->getJson('/api/admin/recruitment/applicants')
            ->assertForbidden();
    }

    public function test_admin_can_create_applicant_upload_document_and_save_interview(): void
    {
        Storage::fake('public');
        $admin = $this->adminUser();
        $department = Department::query()->create(['name' => 'Accounting']);

        $create = $this->actingAs($admin)->postJson('/api/admin/recruitment/applicants', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.test',
            'phone' => '09123456789',
            'applied_position_id' => $department->id,
            'department_id' => $department->id,
            'source' => 'Referral',
            'date_applied' => '2026-06-12',
        ]);

        $create->assertCreated()
            ->assertJsonPath('applicant.full_name', 'Maria Santos')
            ->assertJsonPath('applicant.status', 'New');

        $applicantId = (int) $create->json('applicant.id');

        $upload = $this->actingAs($admin)->post(
            "/api/admin/recruitment/applicants/{$applicantId}/documents",
            [
                'document_type' => 'Resume',
                'remarks' => 'Initial resume.',
                'file' => UploadedFile::fake()->create('resume.pdf', 48, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );

        $upload->assertCreated()
            ->assertJsonPath('document.document_type', 'Resume')
            ->assertJsonPath('document.status', 'Pending');

        $filePath = (string) $upload->json('document.file_path');
        $this->assertNotSame('', $filePath);
        $this->assertTrue(Storage::disk('public')->exists($filePath));

        $interview = $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicantId}/interviews", [
            'interview_type' => 'initial',
            'interviewer_id' => $admin->id,
            'interview_date' => '2026-06-13 09:00:00',
            'mode' => 'Onsite',
            'score' => 88,
            'result' => 'Passed',
            'evaluation' => [
                'Communication' => 9,
                'Confidence' => 8,
            ],
        ]);

        $interview->assertCreated()
            ->assertJsonPath('interview.result', 'Passed')
            ->assertJsonPath('applicant.status', 'For Exam');

        $liteList = $this->actingAs($admin)->getJson('/api/admin/recruitment/applicants?lite=1');
        $liteList->assertOk()
            ->assertJsonPath('applicants.0.full_name', 'Maria Santos')
            ->assertJsonMissingPath('applicants.0.initial_interview_status');
    }

    public function test_admin_can_save_pending_initial_interview_from_workflow_ui(): void
    {
        $admin = $this->adminUser();
        $department = Department::query()->create(['name' => 'Web Development']);
        $applicant = RecruitmentApplicant::query()->create([
            'applicant_no' => 'APP-2026-000010',
            'first_name' => 'Kurt',
            'last_name' => 'Minoza',
            'email' => 'kurt.minoza@example.test',
            'phone' => '09123456781',
            'applied_position' => 'Junior Web Developer',
            'department_id' => $department->id,
            'status' => 'New',
            'date_applied' => '2026-06-10',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicant->id}/interviews", [
            'interview_type' => 'initial',
            'interviewer_id' => $admin->id,
            'interview_date' => '2026-06-10 10:00:00',
            'mode' => 'Onsite',
            'result' => 'Pending',
            'next_step' => 'Schedule Exam',
            'notes' => 'Waiting for HR recommendation.',
            'evaluation' => [
                'Communication' => 4,
                'Work Experience' => 3,
                'Overall Recommendation' => 'For Consideration',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('interview.result', 'Pending')
            ->assertJsonPath('interview.next_step', 'Schedule Exam')
            ->assertJsonPath('applicant.status', 'For Initial Interview');
    }

    public function test_exam_assignment_public_submission_and_employee_conversion_flow(): void
    {
        $admin = $this->adminUser();
        $department = Department::query()->create(['name' => 'Programming']);
        $applicant = RecruitmentApplicant::query()->create([
            'applicant_no' => 'APP-2026-000001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.delacruz@example.test',
            'phone' => '09123456780',
            'applied_position_id' => $department->id,
            'department_id' => $department->id,
            'status' => 'For Exam',
            'date_applied' => '2026-06-12',
        ]);

        $templateResponse = $this->actingAs($admin)->postJson('/api/admin/recruitment/exam-templates', [
            'title' => 'Programming Basics',
            'position_id' => $department->id,
            'duration_minutes' => 30,
            'passing_score' => 1,
            'status' => 'Active',
            'questions' => [
                [
                    'question_type' => 'Multiple Choice',
                    'question' => 'Which option is correct?',
                    'choices' => ['A', 'B', 'C'],
                    'correct_answer' => 'A',
                    'points' => 1,
                ],
            ],
        ]);

        $templateResponse->assertCreated();

        $assignResponse = $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicant->id}/exam-assignments", [
            'exam_template_id' => $templateResponse->json('template.id'),
        ]);

        $assignResponse->assertCreated()
            ->assertJsonPath('assignment.status', 'Assigned')
            ->assertJsonPath('applicant.status', 'For Exam');

        $token = $assignResponse->json('assignment.exam_link_token');

        $this->getJson("/api/recruitment/exam/{$token}")
            ->assertOk()
            ->assertJsonPath('exam.title', 'Programming Basics');

        $this->postJson("/api/recruitment/exam/{$token}", [
            'answers' => [
                [
                    'question_id' => RecruitmentExamTemplate::query()->find($templateResponse->json('template.id'))->questions()->first()->id,
                    'answer' => 'A',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('assignment.result', 'Passed');

        $assignment = RecruitmentExamAssignment::query()->where('exam_link_token', $token)->firstOrFail();
        $this->assertSame('Passed', $assignment->fresh()->result);
        $this->assertSame('For Final Interview', $applicant->fresh()->status);

        $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicant->id}/hiring-action", [
            'action' => 'create_employee',
        ])
            ->assertOk()
            ->assertJsonPath('applicant.status', 'Hired')
            ->assertJsonPath('employee.name', 'Dela Cruz, Juan');

        $this->assertDatabaseHas('users', [
            'email' => 'juan.delacruz@example.test',
            'role' => User::ROLE_EMPLOYEE,
            'department_id' => $department->id,
        ]);
    }

    public function test_stage_action_moves_initial_interview_passed_applicant_to_exam_tab_status(): void
    {
        $admin = $this->adminUser();
        $department = Department::query()->create(['name' => 'Operations']);
        $applicant = RecruitmentApplicant::query()->create([
            'applicant_no' => 'APP-2026-000020',
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'email' => 'alex.rivera@example.test',
            'department_id' => $department->id,
            'status' => 'For Initial Interview',
            'date_applied' => '2026-06-12',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicant->id}/stage-action", [
            'stage' => 'initial',
            'action' => 'passed',
            'interviewer_id' => $admin->id,
            'interview_date' => '2026-06-13 09:00:00',
            'mode' => 'Onsite',
            'score' => 90,
            'notes' => 'Strong fit.',
        ])
            ->assertOk()
            ->assertJsonPath('list_row.status', 'For Exam')
            ->assertJsonPath('list_row.current_stage', 'exam')
            ->assertJsonPath('applicant.status', 'For Exam');

        $initialList = $this->actingAs($admin)->getJson('/api/admin/recruitment/applicants?tab=initial_interview&lite=1');
        $initialList->assertOk();
        $this->assertNotContains(
            $applicant->id,
            collect($initialList->json('applicants'))->pluck('id')->all(),
        );

        $this->actingAs($admin)->getJson('/api/admin/recruitment/applicants?tab=exams&lite=1')
            ->assertOk()
            ->assertJsonPath('applicants.0.full_name', 'Alex Rivera');
    }

    public function test_stage_action_marks_initial_interview_no_show_and_keeps_applicant_in_initial_tab(): void
    {
        $admin = $this->adminUser();
        $department = Department::query()->create(['name' => 'Support']);
        $applicant = RecruitmentApplicant::query()->create([
            'applicant_no' => 'APP-2026-000021',
            'first_name' => 'Jamie',
            'last_name' => 'Lo',
            'email' => 'jamie.lo@example.test',
            'department_id' => $department->id,
            'status' => 'For Initial Interview',
            'date_applied' => '2026-06-12',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/recruitment/applicants/{$applicant->id}/stage-action", [
            'stage' => 'initial',
            'action' => 'no_show',
            'interview_date' => '2026-06-13 09:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('list_row.recruitment_status', 'interview_no_show')
            ->assertJsonPath('applicant.status', 'For Initial Interview');

        $this->actingAs($admin)->getJson('/api/admin/recruitment/applicants?tab=initial_interview&lite=1')
            ->assertOk()
            ->assertJsonPath('applicants.0.full_name', 'Jamie Lo');
    }

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }
}
