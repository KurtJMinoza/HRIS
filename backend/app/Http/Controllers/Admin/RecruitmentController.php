<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeDocument;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentDocument;
use App\Models\RecruitmentExamAnswer;
use App\Models\RecruitmentExamAssignment;
use App\Models\RecruitmentExamQuestion;
use App\Models\RecruitmentExamTemplate;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\RecruitmentListCacheService;
use App\Services\RecruitmentStageActionService;
use App\Support\RecruitmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecruitmentController extends Controller
{
    public function __construct(
        private readonly RecruitmentListCacheService $listCache,
        private readonly RecruitmentStageActionService $stageActions,
    ) {}

    private const DOCUMENT_DIR = 'recruitment-documents';

    private const EXAM_UPLOAD_DIR = 'recruitment-exam-answers';

    private const INTERVIEW_MODES = ['Onsite', 'Online', 'Phone'];

    private const INITIAL_RESULTS = ['Pending', 'Scheduled', 'Passed', 'Failed', 'No Show', 'Reschedule'];

    private const FINAL_RESULTS = ['Pending', 'Scheduled', 'Passed', 'Failed', 'No Show', 'Reschedule', 'Hold'];

    private const EXAM_RESULTS = ['Passed', 'Failed', 'Pending Review'];

    private const LIST_TTL_SECONDS = 120;

    private const EXAM_CATEGORIES = ['IQ Assessment', 'Technical Assessment', 'Accounting Assessment', 'HR Assessment', 'Sales Assessment', 'Management Assessment', 'Custom'];

    private const CORRECT_ANSWER_VISIBILITY = ['Never', 'Immediately After Submission', 'After Exam Closed', 'After Recruiter Approval'];

    public function meta(Request $request): JsonResponse
    {
        $data = [
            'statuses' => RecruitmentApplicant::STATUSES,
            'document_types' => RecruitmentDocument::TYPES,
            'document_statuses' => RecruitmentDocument::STATUSES,
            'interview_modes' => self::INTERVIEW_MODES,
            'initial_results' => self::INITIAL_RESULTS,
            'final_results' => self::FINAL_RESULTS,
            'exam_results' => self::EXAM_RESULTS,
            'exam_categories' => self::EXAM_CATEGORIES,
            'exam_correct_answer_visibility' => self::CORRECT_ANSWER_VISIBILITY,
            'question_types' => RecruitmentExamQuestion::TYPES,
            'question_difficulties' => RecruitmentExamQuestion::DIFFICULTIES,
            'question_categories' => RecruitmentExamQuestion::CATEGORIES,
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'name', 'branch_id', 'company_id'])
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'branch_id' => $department->branch_id,
                    'company_id' => $department->company_id,
                ])
                ->values(),
        ];

        if ($request->boolean('include_interviewers')) {
            $data['interviewers'] = User::query()
                ->visibleEmployees()
                ->active()
                ->where(function ($query): void {
                    $query
                        ->whereHas('departmentRelation', function ($department): void {
                            $this->filterHrDepartmentName($department);
                        })
                        ->orWhere(function ($user): void {
                            $this->filterHrDepartmentColumn($user);
                        });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(300)
                ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'email'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->display_name ?? $user->name,
                    'email' => $user->email,
                ])
                ->values();
        }

        return response()->json($data);
    }

    public function index(Request $request): JsonResponse
    {
        $tab = $this->normalizeListTab((string) $request->query('tab', 'applicants'));
        $lite = $request->boolean('lite') || $tab !== 'applicants';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(5, min(100, (int) $request->query('per_page', 15)));
        $filters = [
            'tab' => $tab,
            'lite' => $lite,
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'per_page' => $perPage,
        ];
        $filtersHash = $this->filtersHash($filters);
        $userId = (int) ($request->user()?->id ?? 0);
        $cacheKey = $this->listCache->cacheKey($tab, $userId, $page, $filtersHash);

        $payload = Cache::remember($cacheKey, self::LIST_TTL_SECONDS, function () use ($request, $tab, $lite, $perPage): array {
            return $this->listPayload($request, $tab, $lite, $perPage);
        });

        return response()->json($payload);
    }

    private function listPayload(Request $request, string $tab, bool $lite, int $perPage): array
    {
        $query = RecruitmentApplicant::query()
            ->with([
                'appliedPosition:id,name',
                'department:id,name',
            ]);

        if ($lite) {
            $query->with([
                'interviews' => fn ($interviews) => $interviews->orderByDesc('interview_date')->orderByDesc('id'),
                'examAssignments' => fn ($assignments) => $assignments->orderByDesc('id')->limit(1),
            ]);

            if ($tab === 'applicants') {
                $query->withCount([
                    'documents',
                    'documents as rejected_documents_count' => fn ($documents) => $documents->where('status', 'Rejected'),
                    'documents as verified_documents_count' => fn ($documents) => $documents->where('status', 'Verified'),
                ]);
            }
        }

        if (! $lite) {
            $query->withCount([
                'documents',
                'documents as rejected_documents_count' => fn ($documents) => $documents->where('status', 'Rejected'),
                'documents as verified_documents_count' => fn ($documents) => $documents->where('status', 'Verified'),
            ])
                ->addSelect([
                    'initial_interview_status_summary' => RecruitmentInterview::query()
                        ->select('result')
                        ->whereColumn('applicant_id', 'recruitment_applicants.id')
                        ->where('interview_type', 'initial')
                        ->latest('interview_date')
                        ->latest('id')
                        ->limit(1),
                    'initial_interview_date_summary' => RecruitmentInterview::query()
                        ->select('interview_date')
                        ->whereColumn('applicant_id', 'recruitment_applicants.id')
                        ->where('interview_type', 'initial')
                        ->latest('interview_date')
                        ->latest('id')
                        ->limit(1),
                    'final_interview_status_summary' => RecruitmentInterview::query()
                        ->select('result')
                        ->whereColumn('applicant_id', 'recruitment_applicants.id')
                        ->where('interview_type', 'final')
                        ->latest('interview_date')
                        ->latest('id')
                        ->limit(1),
                    'final_interview_date_summary' => RecruitmentInterview::query()
                        ->select('interview_date')
                        ->whereColumn('applicant_id', 'recruitment_applicants.id')
                        ->where('interview_type', 'final')
                        ->latest('interview_date')
                        ->latest('id')
                        ->limit(1),
                    'exam_status_summary' => RecruitmentExamAssignment::query()
                        ->selectRaw('COALESCE(result, status)')
                        ->whereColumn('applicant_id', 'recruitment_applicants.id')
                        ->latest('id')
                        ->limit(1),
                ]);
        }

        if (isset(RecruitmentWorkflow::TAB_STATUS_FILTERS[$tab]) && RecruitmentWorkflow::TAB_STATUS_FILTERS[$tab] !== []) {
            $query->whereIn('status', RecruitmentWorkflow::TAB_STATUS_FILTERS[$tab]);
        } elseif ($tab === 'applicants' && ! $request->filled('status')) {
            $query->whereNotIn('status', ['Hired', 'Rejected']);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('q')) {
            $like = '%'.trim((string) $request->query('q')).'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('applicant_no', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('applied_position', 'like', $like);
            });
        }

        $paginator = $query->latest('date_applied')->latest('id')->paginate($perPage);

        return [
            'applicants' => collect($paginator->items())->map(
                fn (RecruitmentApplicant $applicant) => $lite
                    ? $this->stageActions->listRowFromApplicant($applicant)
                    : $this->applicantResponse($applicant, lite: false),
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateApplicant($request);

        $applicant = RecruitmentApplicant::create([
            ...$validated,
            'applicant_no' => $this->nextApplicantNo(),
            'status' => $validated['status'] ?? 'New',
            'date_applied' => $validated['date_applied'] ?? now()->toDateString(),
        ]);
        $this->invalidateTabs(['applicants']);

        return response()->json([
            'message' => 'Applicant created.',
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations())),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $applicant = RecruitmentApplicant::with($this->applicantRelations())->findOrFail($id);

        return response()->json(['applicant' => $this->applicantResponse($applicant, true)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($id);
        $validated = $this->validateApplicant($request, $applicant);
        $applicant->update($validated);
        $this->invalidateTabs(['applicants']);

        return response()->json([
            'message' => 'Applicant updated.',
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations()), true),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $applicant = RecruitmentApplicant::with('documents')->findOrFail($id);
        foreach ($applicant->documents as $document) {
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
        }
        $applicant->delete();
        $this->invalidateTabs(['applicants']);

        return response()->json(['message' => 'Applicant deleted.']);
    }

    public function storeDocument(Request $request, int $applicantId): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($applicantId);
        $validated = $request->validate([
            'document_type' => ['required', Rule::in(RecruitmentDocument::TYPES)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store(self::DOCUMENT_DIR.'/'.$applicant->id, 'public');

        $document = RecruitmentDocument::create([
            'applicant_id' => $applicant->id,
            'document_type' => $validated['document_type'],
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_mime' => $file->getClientMimeType() ?: $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'status' => 'Pending',
            'remarks' => $validated['remarks'] ?? null,
            'uploaded_by' => $request->user()?->id,
        ]);
        $this->invalidateTabs(['requirements', 'applicants']);

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => $this->documentResponse($document->fresh('uploader')),
        ], 201);
    }

    public function updateDocument(Request $request, int $applicantId, int $documentId): JsonResponse
    {
        $document = RecruitmentDocument::where('applicant_id', $applicantId)->findOrFail($documentId);
        $validated = $request->validate([
            'document_type' => ['sometimes', 'required', Rule::in(RecruitmentDocument::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(RecruitmentDocument::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        if ($request->hasFile('file')) {
            if ($document->file_path) {
                Storage::disk('public')->delete($document->file_path);
            }
            $file = $request->file('file');
            $document->file_path = $file->store(self::DOCUMENT_DIR.'/'.$applicantId, 'public');
            $document->file_name = $file->getClientOriginalName();
            $document->file_mime = $file->getClientMimeType() ?: $file->getMimeType();
            $document->file_size = (int) $file->getSize();
            $document->status = 'Pending';
        }

        foreach (['document_type', 'status', 'remarks'] as $field) {
            if (array_key_exists($field, $validated)) {
                $document->{$field} = $validated[$field];
            }
        }
        $document->save();
        $this->invalidateTabs(['requirements', 'applicants']);

        return response()->json([
            'message' => 'Document updated.',
            'document' => $this->documentResponse($document->fresh('uploader')),
        ]);
    }

    public function downloadDocument(int $applicantId, int $documentId)
    {
        $document = RecruitmentDocument::where('applicant_id', $applicantId)->findOrFail($documentId);
        if (! $document->file_path || ! Storage::disk('public')->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return response()->download(Storage::disk('public')->path($document->file_path), $document->file_name);
    }

    public function storeInterview(Request $request, int $applicantId): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($applicantId);
        $validated = $this->validateInterview($request);
        $interview = $applicant->interviews()->create($validated);
        $this->syncApplicantAfterInterview($applicant, $interview);
        $this->invalidateTabs($interview->interview_type === 'final'
            ? ['final_interview', 'requirements', 'applicants']
            : ['initial_interview', 'exams', 'applicants']);

        return response()->json([
            'message' => 'Interview saved.',
            'interview' => $this->interviewResponse($interview->fresh('interviewer')),
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations()), true),
        ], 201);
    }

    public function updateInterview(Request $request, int $applicantId, int $interviewId): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($applicantId);
        $interview = RecruitmentInterview::where('applicant_id', $applicant->id)->findOrFail($interviewId);
        $interview->update($this->validateInterview($request));
        $this->syncApplicantAfterInterview($applicant, $interview);
        $this->invalidateTabs($interview->interview_type === 'final'
            ? ['final_interview', 'requirements', 'applicants']
            : ['initial_interview', 'exams', 'applicants']);

        return response()->json([
            'message' => 'Interview updated.',
            'interview' => $this->interviewResponse($interview->fresh('interviewer')),
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations()), true),
        ]);
    }

    public function examTemplates(): JsonResponse
    {
        $templates = RecruitmentExamTemplate::with(['position:id,name', 'department:id,name', 'questions'])
            ->withCount('questions')
            ->withCount('assignments')
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (RecruitmentExamTemplate $template) => $this->examTemplateResponse($template, true))
            ->values();

        return response()->json(['templates' => $templates]);
    }

    public function storeExamTemplate(Request $request): JsonResponse
    {
        $validated = $this->validateExamTemplate($request);
        $questions = $validated['questions'] ?? [];
        unset($validated['questions']);

        $template = DB::transaction(function () use ($request, $validated, $questions): RecruitmentExamTemplate {
            $template = RecruitmentExamTemplate::create([
                ...$validated,
                'created_by' => $request->user()?->id,
            ]);
            $this->replaceQuestions($template, $questions);

            return $template;
        });

        return response()->json([
            'message' => 'Exam template created.',
            'template' => $this->examTemplateResponse($template->fresh(['position:id,name', 'questions']), true),
        ], 201);
    }

    public function updateExamTemplate(Request $request, int $templateId): JsonResponse
    {
        $template = RecruitmentExamTemplate::findOrFail($templateId);
        $validated = $this->validateExamTemplate($request);
        $questions = $validated['questions'] ?? null;
        unset($validated['questions']);

        DB::transaction(function () use ($template, $validated, $questions): void {
            $template->update($validated);
            if (is_array($questions)) {
                $this->replaceQuestions($template, $questions);
            }
        });

        return response()->json([
            'message' => 'Exam template updated.',
            'template' => $this->examTemplateResponse($template->fresh(['position:id,name', 'questions']), true),
        ]);
    }

    public function destroyExamTemplate(int $templateId): JsonResponse
    {
        $template = RecruitmentExamTemplate::withCount('assignments')->findOrFail($templateId);
        $deletedAssignments = (int) $template->assignments_count;

        $template->delete();

        return response()->json([
            'message' => 'Exam template deleted.',
            'deleted_assignments' => $deletedAssignments,
        ]);
    }

    public function examAssignments(): JsonResponse
    {
        $assignments = RecruitmentExamAssignment::with([
            'applicant:id,applicant_no,first_name,last_name,email,status',
            'template' => fn ($template) => $template
                ->select('id', 'title', 'passing_score', 'duration_minutes')
                ->withCount('questions'),
            'answers.question',
        ])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn (RecruitmentExamAssignment $assignment) => $this->examAssignmentResponse($assignment, true))
            ->values();

        return response()->json(['assignments' => $assignments]);
    }

    public function assignExam(Request $request, int $applicantId): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($applicantId);
        $validated = $request->validate([
            'exam_template_id' => ['required', 'integer', 'exists:recruitment_exam_templates,id'],
            'scheduled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'one_time_access' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'max:100'],
            'require_login' => ['nullable', 'boolean'],
        ]);

        $assignment = RecruitmentExamAssignment::create([
            'applicant_id' => $applicant->id,
            'exam_template_id' => $validated['exam_template_id'],
            'assigned_by' => $request->user()?->id,
            'exam_link_token' => 'AGC-EXM-'.Str::upper(Str::random(7)),
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'attempt_number' => 1,
            'max_attempts' => $validated['max_attempts'] ?? 1,
            'one_time_access' => $validated['one_time_access'] ?? true,
            'password' => $validated['password'] ?? null,
            'require_login' => $validated['require_login'] ?? false,
            'status' => 'Assigned',
            'result' => null,
        ]);

        $applicant->update(['status' => 'For Exam']);
        $this->invalidateTabs(['exams', 'applicants']);

        return response()->json([
            'message' => 'Exam assigned.',
            'assignment' => $this->examAssignmentResponse($assignment->fresh(['template', 'applicant']), true),
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations()), true),
        ], 201);
    }

    public function updateExamAnswerScore(Request $request, int $assignmentId, int $answerId): JsonResponse
    {
        $answer = RecruitmentExamAnswer::where('exam_assignment_id', $assignmentId)->findOrFail($answerId);
        $validated = $request->validate([
            'score' => ['required', 'numeric', 'min:0'],
        ]);
        $answer->update([
            'score' => (float) $validated['score'],
            'checked_by' => $request->user()?->id,
        ]);

        $assignment = RecruitmentExamAssignment::with('answers.question', 'template', 'applicant')->findOrFail($assignmentId);
        $this->finalizeExamScore($assignment, force: false);
        $this->invalidateTabs(['exams', 'final_interview', 'applicants']);

        return response()->json([
            'message' => 'Answer score updated.',
            'assignment' => $this->examAssignmentResponse($assignment->fresh(['answers.question', 'template', 'applicant']), true),
        ]);
    }

    public function publicExam(string $token): JsonResponse
    {
        $assignment = RecruitmentExamAssignment::with(['applicant.department.branch', 'template.questions'])
            ->where('exam_link_token', $token)
            ->firstOrFail();

        if ($assignment->submitted_at) {
            return response()->json(['message' => 'This exam was already submitted.'], 409);
        }

        if ($assignment->expires_at && $assignment->expires_at->isPast()) {
            $assignment->update(['status' => 'Expired']);

            return response()->json(['message' => 'This assessment link has expired.'], 410);
        }

        if (! $assignment->started_at) {
            $assignment->update([
                'started_at' => now(),
                'status' => 'In Progress',
            ]);
        }

        return response()->json(['exam' => $this->publicExamResponse($assignment->fresh(['applicant', 'template.questions']))]);
    }

    public function submitPublicExam(Request $request, string $token): JsonResponse
    {
        $assignment = RecruitmentExamAssignment::with(['template.questions', 'applicant.department.branch'])
            ->where('exam_link_token', $token)
            ->firstOrFail();

        if ($assignment->submitted_at) {
            return response()->json(['message' => 'This exam was already submitted.'], 409);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'exists:recruitment_exam_questions,id'],
            'answers.*.answer' => ['nullable'],
            'answers.*.file' => ['nullable', 'file', 'max:10240'],
        ]);

        DB::transaction(function () use ($request, $assignment, $validated): void {
            foreach ($validated['answers'] as $index => $answerPayload) {
                $question = $assignment->template->questions->firstWhere('id', (int) $answerPayload['question_id']);
                if (! $question) {
                    continue;
                }
                $filePath = null;
                if ($request->hasFile("answers.$index.file")) {
                    $filePath = $request->file("answers.$index.file")->store(self::EXAM_UPLOAD_DIR.'/'.$assignment->id, 'public');
                }
                RecruitmentExamAnswer::create([
                    'exam_assignment_id' => $assignment->id,
                    'question_id' => $question->id,
                    'answer' => is_array($answerPayload['answer'] ?? null) ? json_encode($answerPayload['answer']) : ($answerPayload['answer'] ?? null),
                    'file_path' => $filePath,
                    'score' => $this->autoScoreQuestion($question, $answerPayload['answer'] ?? null),
                ]);
            }

            $assignment->update([
                'submitted_at' => now(),
                'status' => 'Submitted',
            ]);
        });

        $assignment = $assignment->fresh(['answers.question', 'template.questions', 'applicant']);
        $this->finalizeExamScore($assignment, force: false);
        $this->invalidateTabs(['exams', 'final_interview', 'applicants']);

        return response()->json([
            'message' => 'Exam submitted.',
            'assignment' => $this->examAssignmentResponse($assignment->fresh(['answers.question', 'template', 'applicant']), true),
        ]);
    }

    public function stageAction(Request $request, int $applicantId): JsonResponse
    {
        $applicant = RecruitmentApplicant::findOrFail($applicantId);
        $result = $this->stageActions->handle($request, $applicant);

        return response()->json([
            'message' => 'Stage action saved.',
            'applicant' => $this->applicantResponse($result['applicant'], true),
            'list_row' => $result['list_row'],
            'affected_tabs' => $result['affected_tabs'],
        ]);
    }

    public function hiringAction(Request $request, int $applicantId): JsonResponse
    {
        $applicant = RecruitmentApplicant::with(['documents', 'department', 'appliedPosition'])->findOrFail($applicantId);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['mark_hired', 'reject', 'move_requirements', 'create_employee'])],
        ]);

        $employee = null;
        if ($validated['action'] === 'mark_hired') {
            $applicant->update(['status' => 'Hired']);
        } elseif ($validated['action'] === 'reject') {
            $applicant->update(['status' => 'Rejected']);
        } elseif ($validated['action'] === 'move_requirements') {
            $applicant->update(['status' => 'For Requirements']);
        } elseif ($validated['action'] === 'create_employee') {
            if (! app(\App\Services\RbacService::class)->can($request->user(), 'recruitment.convert')) {
                abort(403, 'Missing permission to convert applicants to employees.');
            }
            $employee = $this->createEmployeeFromApplicant($request, $applicant);
            $applicant->update([
                'status' => 'Hired',
                'created_employee_id' => $employee->id,
            ]);
        }
        $this->invalidateTabs(['hiring_approval', 'hired_applicants', 'requirements', 'applicants']);

        return response()->json([
            'message' => 'Hiring decision saved.',
            'applicant' => $this->applicantResponse($applicant->fresh($this->applicantRelations()), true),
            'employee' => $employee ? [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->name,
                'username' => $employee->username,
                'temporary_password' => $employee->account_export_password,
            ] : null,
        ]);
    }

    private function validateApplicant(Request $request, ?RecruitmentApplicant $applicant = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'applied_position_id' => ['nullable', 'integer', 'exists:departments,id'],
            'applied_position' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(RecruitmentApplicant::STATUSES)],
            'date_applied' => ['nullable', 'date'],
        ]);
    }

    private function validateInterview(Request $request): array
    {
        $validated = $request->validate([
            'interview_type' => ['required', Rule::in(RecruitmentInterview::TYPES)],
            'interviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'interview_date' => ['nullable', 'date'],
            'mode' => ['nullable', Rule::in(self::INTERVIEW_MODES)],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'result' => ['nullable', 'string', 'max:32'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'evaluation' => ['nullable', 'array'],
        ]);

        $allowed = ($validated['interview_type'] ?? '') === 'final' ? self::FINAL_RESULTS : self::INITIAL_RESULTS;
        if (! empty($validated['result']) && ! in_array($validated['result'], $allowed, true)) {
            throw ValidationException::withMessages(['result' => ['Invalid interview result.']]);
        }

        $this->validateHrInterviewer($validated['interviewer_id'] ?? null);

        return $validated;
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

    private function validateExamTemplate(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(self::EXAM_CATEGORIES)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:departments,id'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'passing_score' => ['required', 'numeric', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'settings' => ['nullable', 'array'],
            'settings.show_correct_answers' => ['nullable', Rule::in(self::CORRECT_ANSWER_VISIBILITY)],
            'settings.randomize_questions' => ['nullable', 'boolean'],
            'settings.randomize_choices' => ['nullable', 'boolean'],
            'settings.allow_retake' => ['nullable', 'boolean'],
            'settings.maximum_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'settings.auto_grade' => ['nullable', 'boolean'],
            'settings.manual_review_required' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:32'],
            'questions' => ['nullable', 'array'],
            'questions.*.question_type' => ['required_with:questions', Rule::in(RecruitmentExamQuestion::TYPES)],
            'questions.*.question' => ['required_with:questions', 'string'],
            'questions.*.choices' => ['nullable', 'array'],
            'questions.*.correct_answer' => ['nullable'],
            'questions.*.points' => ['required_with:questions', 'numeric', 'min:0'],
            'questions.*.difficulty' => ['nullable', Rule::in(RecruitmentExamQuestion::DIFFICULTIES)],
            'questions.*.category' => ['nullable', Rule::in(RecruitmentExamQuestion::CATEGORIES)],
        ]);
    }

    private function replaceQuestions(RecruitmentExamTemplate $template, array $questions): void
    {
        $template->questions()->delete();
        foreach ($questions as $question) {
            $template->questions()->create([
                'question_type' => $question['question_type'],
                'question' => $question['question'],
                'choices' => $question['choices'] ?? null,
                'correct_answer' => is_array($question['correct_answer'] ?? null) ? json_encode($question['correct_answer']) : ($question['correct_answer'] ?? null),
                'points' => (float) ($question['points'] ?? 1),
                'difficulty' => $question['difficulty'] ?? 'Medium',
                'category' => $question['category'] ?? 'Custom',
            ]);
        }
    }

    private function syncApplicantAfterInterview(RecruitmentApplicant $applicant, RecruitmentInterview $interview): void
    {
        if ($interview->interview_type === 'initial') {
            if ($interview->result === 'Passed') {
                $applicant->update(['status' => 'For Exam']);
            } elseif ($interview->result === 'Failed') {
                $applicant->update(['status' => 'Rejected']);
            } elseif (in_array($interview->result, ['Pending', 'Scheduled', 'Reschedule', 'No Show'], true)) {
                $applicant->update(['status' => 'For Initial Interview']);
            }
        } elseif ($interview->interview_type === 'final') {
            if ($interview->result === 'Passed') {
                $applicant->update(['status' => 'For Requirements']);
            } elseif ($interview->result === 'Failed') {
                $applicant->update(['status' => 'Rejected']);
            } elseif (in_array($interview->result, ['Pending', 'Scheduled', 'Reschedule', 'No Show', 'Hold'], true)) {
                $applicant->update(['status' => 'For Final Interview']);
            }
        }
    }

    private function finalizeExamScore(RecruitmentExamAssignment $assignment, bool $force): void
    {
        $answers = $assignment->answers()->with('question')->get();
        $needsManualReview = $answers->contains(fn (RecruitmentExamAnswer $answer): bool => $answer->score === null);
        $earned = (float) $answers->sum(fn (RecruitmentExamAnswer $answer): float => (float) ($answer->score ?? 0));
        $possible = (float) $assignment->template?->questions()->sum('points');
        $score = $possible > 0 ? round(($earned / $possible) * 100, 2) : $earned;
        $result = $needsManualReview && ! $force
            ? 'Pending Review'
            : ($score >= (float) ($assignment->template?->passing_score ?? 0) ? 'Passed' : 'Failed');

        $assignment->update([
            'score' => $score,
            'result' => $result,
            'status' => $result === 'Pending Review' ? 'Pending Review' : 'Checked',
        ]);

        if ($assignment->applicant) {
            if ($result === 'Passed') {
                $assignment->applicant->update(['status' => 'For Final Interview']);
            } elseif ($result === 'Failed') {
                $assignment->applicant->update(['status' => 'Rejected']);
            }
        }
    }

    private function autoScoreQuestion(RecruitmentExamQuestion $question, mixed $answer): ?float
    {
        if (in_array($question->question_type, ['Essay', 'File Upload', 'Short Answer'], true)) {
            return null;
        }
        if ($question->question_type === 'Checkbox') {
            $expected = collect(json_decode((string) $question->correct_answer, true) ?: explode(',', (string) $question->correct_answer))
                ->map(fn ($value) => mb_strtolower(trim((string) $value)))
                ->filter()
                ->sort()
                ->values()
                ->all();
            $decodedAnswer = is_string($answer) ? json_decode($answer, true) : null;
            $actual = collect(is_array($answer) ? $answer : (is_array($decodedAnswer) ? $decodedAnswer : explode(',', (string) $answer)))
                ->map(fn ($value) => mb_strtolower(trim((string) $value)))
                ->filter()
                ->sort()
                ->values()
                ->all();

            return $expected === $actual ? (float) $question->points : 0.0;
        }

        $expected = mb_strtolower(trim((string) $question->correct_answer));
        $actual = mb_strtolower(trim(is_array($answer) ? json_encode($answer) : (string) $answer));
        if ($expected === '') {
            return null;
        }

        return $expected === $actual ? (float) $question->points : 0.0;
    }

    private function createEmployeeFromApplicant(Request $request, RecruitmentApplicant $applicant): User
    {
        if ($applicant->created_employee_id) {
            throw ValidationException::withMessages(['applicant' => ['This applicant already has an employee record.']]);
        }

        $department = $applicant->department ?: $applicant->appliedPosition;
        $resolvedBranchId = $department?->branch_id;
        $resolvedCompanyId = $department?->company_id ?? $department?->branch?->company_id;

        app(\App\Services\DataScopeService::class)->assertCanCreateEmployeeInOrg(
            $request->user(),
            $resolvedCompanyId !== null ? (int) $resolvedCompanyId : null,
            $resolvedBranchId !== null ? (int) $resolvedBranchId : null,
            $department?->id !== null ? (int) $department->id : null,
            $department?->division_id !== null ? (int) $department->division_id : null,
            null,
        );

        return DB::transaction(function () use ($request, $applicant, $department, $resolvedBranchId, $resolvedCompanyId): User {
            $username = $this->uniqueUsername($applicant);
            $password = 'Hris'.Str::random(8).'1!';
            $firstName = trim($applicant->first_name);
            $lastName = trim($applicant->last_name);

            $employee = User::create([
                'name' => User::formatEmployeeDisplayName($firstName, null, $lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $applicant->email,
                'username' => $username,
                'phone_number' => $applicant->phone,
                'password' => Hash::make($password),
                'account_export_password' => $password,
                'role' => User::ROLE_EMPLOYEE,
                'is_active' => true,
                'department' => $department?->name,
                'department_id' => $department?->id,
                'division_id' => $department?->division_id,
                'branch_id' => $resolvedBranchId,
                'company_id' => $resolvedCompanyId,
                'position' => $applicant->applied_position ?: $department?->name,
                'employment_status' => \App\Enums\EmploymentStatus::Probationary->value,
                'status_override' => false,
                'hire_date' => now()->toDateString(),
                'payroll_effective_date' => now()->toDateString(),
            ]);
            $employee->employee_code = sprintf('EMP-%06d', $employee->id);
            $employee->save();
            $employee = app(\App\Services\EmployeeStatusService::class)->syncAutomaticEmploymentStatus(
                $employee->fresh() ?? $employee,
                $request->user(),
                initializeLeaveCredits: true,
            );

            foreach ($applicant->documents as $document) {
                $targetPath = null;
                if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                    $targetPath = 'employee-documents/'.$employee->id.'/'.basename($document->file_path);
                    Storage::disk('public')->copy($document->file_path, $targetPath);
                }
                EmployeeDocument::create([
                    'user_id' => $employee->id,
                    'category' => $this->employeeDocumentCategory($document->document_type),
                    'document_name' => $document->document_type,
                    'status' => $document->status === 'Verified' ? 'active' : 'pending',
                    'review_note' => $document->remarks,
                    'uploaded_by' => $document->uploaded_by,
                    'file_path' => $targetPath,
                    'file_mime' => $document->file_mime,
                    'file_size' => $document->file_size,
                ]);
            }

            return $employee;
        });
    }

    private function employeeDocumentCategory(string $type): string
    {
        return match ($type) {
            'Government ID', 'NBI Clearance', 'Birth Certificate' => 'IDs',
            'Certificates', 'Diploma / TOR' => 'Certifications',
            'Medical' => 'Medical Documents',
            default => 'Contracts',
        };
    }

    private function uniqueUsername(RecruitmentApplicant $applicant): string
    {
        $base = Str::slug($applicant->first_name.'.'.$applicant->last_name, '.');
        $base = preg_replace('/[^A-Za-z0-9._]/', '', $base) ?: 'applicant';
        $username = $base;
        $suffix = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.$suffix;
            $suffix++;
        }

        return $username;
    }

    private function nextApplicantNo(): string
    {
        $prefix = 'APP-'.now()->format('Y');
        $next = RecruitmentApplicant::where('applicant_no', 'like', $prefix.'-%')->count() + 1;
        do {
            $candidate = $prefix.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (RecruitmentApplicant::where('applicant_no', $candidate)->exists());

        return $candidate;
    }

    private function normalizeListTab(string $tab): string
    {
        $tab = str_replace('-', '_', trim(strtolower($tab)));

        return match ($tab) {
            'initial', 'initial_interviews' => 'initial_interview',
            'final', 'final_interviews' => 'final_interview',
            'requirements' => 'requirements',
            'hiring', 'hiring_decision', 'hiring_approval' => 'hiring_approval',
            'hired', 'hired_applicants' => 'hired_applicants',
            'rejected', 'rejected_applicants' => 'rejected',
            'screening' => 'screening',
            'exams' => 'exams',
            'job_offer', 'job_offers' => 'job_offer',
            default => 'applicants',
        };
    }

    /**
     * @param  array<string, bool|int|string>  $filters
     */
    private function filtersHash(array $filters): string
    {
        ksort($filters);

        return substr(hash('xxh128', json_encode($filters, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  list<string>  $tabs
     */
    private function invalidateTabs(array $tabs): void
    {
        $this->listCache->bumpTabs($tabs);
    }

    private function applicantRelations(): array
    {
        return [
            'appliedPosition:id,name',
            'department:id,name,company_id,branch_id,division_id',
            'department.branch:id,name,company_id',
            'documents.uploader:id,name,first_name,middle_name,last_name,suffix',
            'interviews.interviewer:id,name,first_name,middle_name,last_name,suffix',
            'examAssignments.template:id,title,passing_score,duration_minutes',
            'createdEmployee:id,name,employee_code,username',
        ];
    }

    private function applicantResponse(RecruitmentApplicant $applicant, bool $detailed = false, bool $lite = false): array
    {
        if ($lite) {
            return [
                'id' => $applicant->id,
                'applicant_no' => $applicant->applicant_no,
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'full_name' => $applicant->full_name,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'applied_position' => $applicant->applied_position ?: $applicant->appliedPosition?->name,
                'department_name' => $applicant->department?->name,
                'source' => $applicant->source,
                'status' => $applicant->status,
                'date_applied' => $applicant->date_applied?->format('Y-m-d'),
            ];
        }

        $initial = $applicant->relationLoaded('interviews')
            ? $applicant->interviews->firstWhere('interview_type', 'initial')
            : null;
        $final = $applicant->relationLoaded('interviews')
            ? $applicant->interviews->firstWhere('interview_type', 'final')
            : null;
        $latestExam = $applicant->relationLoaded('examAssignments')
            ? $applicant->examAssignments->sortByDesc('id')->first()
            : null;
        $createdEmployee = $applicant->relationLoaded('createdEmployee')
            ? $applicant->createdEmployee
            : null;

        $data = [
            'id' => $applicant->id,
            'applicant_no' => $applicant->applicant_no,
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'full_name' => $applicant->full_name,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'applied_position_id' => $applicant->applied_position_id,
            'applied_position' => $applicant->applied_position ?: $applicant->appliedPosition?->name,
            'department_id' => $applicant->department_id,
            'department_name' => $applicant->department?->name,
            'source' => $applicant->source,
            'status' => $applicant->status,
            'initial_interview_status' => $applicant->getAttribute('initial_interview_status_summary') ?? $initial?->result,
            'initial_interview_date' => $applicant->getAttribute('initial_interview_date_summary') ?? $initial?->interview_date?->toIso8601String(),
            'initial_interview_mode' => $initial?->mode,
            'initial_interviewer' => $initial?->relationLoaded('interviewer') ? ($initial->interviewer?->display_name ?? $initial->interviewer?->name) : null,
            'exam_status' => $applicant->getAttribute('exam_status_summary') ?? $latestExam?->result ?? $latestExam?->status,
            'final_interview_status' => $applicant->getAttribute('final_interview_status_summary') ?? $final?->result,
            'final_interview_date' => $applicant->getAttribute('final_interview_date_summary') ?? $final?->interview_date?->toIso8601String(),
            'final_interview_mode' => $final?->mode,
            'final_interviewer' => $final?->relationLoaded('interviewer') ? ($final->interviewer?->display_name ?? $final->interviewer?->name) : null,
            'requirements_status' => $this->requirementsStatus($applicant),
            'date_applied' => $applicant->date_applied?->format('Y-m-d'),
            'created_employee_id' => $applicant->created_employee_id,
            'created_employee' => $createdEmployee ? [
                'id' => $createdEmployee->id,
                'name' => $createdEmployee->name,
                'employee_code' => $createdEmployee->employee_code,
                'username' => $createdEmployee->username,
            ] : null,
            'created_at' => $applicant->created_at?->toIso8601String(),
            'updated_at' => $applicant->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['documents'] = $applicant->documents?->map(fn (RecruitmentDocument $document) => $this->documentResponse($document))->values() ?? [];
            $data['interviews'] = $applicant->interviews?->map(fn (RecruitmentInterview $interview) => $this->interviewResponse($interview))->values() ?? [];
            $data['exam_assignments'] = $applicant->examAssignments?->map(fn (RecruitmentExamAssignment $assignment) => $this->examAssignmentResponse($assignment))->values() ?? [];
        }

        return $data;
    }

    private function requirementsStatus(RecruitmentApplicant $applicant): string
    {
        if (! $applicant->relationLoaded('documents')) {
            $total = (int) ($applicant->getAttribute('documents_count') ?? 0);
            if ($total === 0) {
                return 'Pending';
            }
            if ((int) ($applicant->getAttribute('rejected_documents_count') ?? 0) > 0) {
                return 'Rejected';
            }
            if ((int) ($applicant->getAttribute('verified_documents_count') ?? 0) === $total) {
                return 'Verified';
            }

            return 'Pending';
        }

        $documents = $applicant->documents;
        if ($documents->isEmpty()) {
            return 'Pending';
        }
        if ($documents->contains(fn (RecruitmentDocument $document): bool => $document->status === 'Rejected')) {
            return 'Rejected';
        }
        if ($documents->every(fn (RecruitmentDocument $document): bool => $document->status === 'Verified')) {
            return 'Verified';
        }

        return 'Pending';
    }

    private function documentResponse(RecruitmentDocument $document): array
    {
        return [
            'id' => $document->id,
            'applicant_id' => $document->applicant_id,
            'document_type' => $document->document_type,
            'file_name' => $document->file_name,
            'file_path' => $document->file_path,
            'file_url' => $document->file_path ? url('/api/media/public/'.$document->file_path) : null,
            'download_url' => url('/api/admin/recruitment/applicants/'.$document->applicant_id.'/documents/'.$document->id.'/download'),
            'file_mime' => $document->file_mime,
            'file_size' => $document->file_size,
            'uploaded_by' => $document->uploader?->display_name ?? $document->uploader?->name,
            'uploaded_by_id' => $document->uploaded_by,
            'uploaded_date' => $document->created_at?->toIso8601String(),
            'status' => $document->status,
            'remarks' => $document->remarks,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    private function interviewResponse(RecruitmentInterview $interview): array
    {
        return [
            'id' => $interview->id,
            'applicant_id' => $interview->applicant_id,
            'interview_type' => $interview->interview_type,
            'interviewer_id' => $interview->interviewer_id,
            'interviewer_name' => $interview->interviewer?->display_name ?? $interview->interviewer?->name,
            'interview_date' => $interview->interview_date?->toIso8601String(),
            'mode' => $interview->mode,
            'score' => $interview->score,
            'notes' => $interview->notes,
            'result' => $interview->result,
            'next_step' => $interview->next_step,
            'evaluation' => $interview->evaluation ?? [],
            'created_at' => $interview->created_at?->toIso8601String(),
            'updated_at' => $interview->updated_at?->toIso8601String(),
        ];
    }

    private function examTemplateResponse(RecruitmentExamTemplate $template, bool $includeQuestions = false): array
    {
        $data = [
            'id' => $template->id,
            'title' => $template->title,
            'category' => $template->category ?? 'Custom',
            'department_id' => $template->department_id,
            'department_name' => $template->department?->name,
            'position_id' => $template->position_id,
            'position_name' => $template->position?->name,
            'duration_minutes' => $template->duration_minutes,
            'passing_score' => $template->passing_score,
            'instructions' => $template->instructions,
            'settings' => $this->examSettings($template->settings ?? []),
            'status' => $template->status,
            'questions_count' => (int) ($template->getAttribute('questions_count') ?? $template->questions?->count() ?? 0),
            'assigned_applicants_count' => (int) ($template->getAttribute('assignments_count') ?? 0),
            'created_by' => $template->created_by,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
        if ($includeQuestions) {
            $data['questions'] = $template->questions?->map(fn (RecruitmentExamQuestion $question) => [
                'id' => $question->id,
                'question_type' => $question->question_type,
                'question' => $question->question,
                'choices' => $question->choices ?? [],
                'correct_answer' => $question->correct_answer,
                'points' => $question->points,
                'difficulty' => $question->difficulty ?? 'Medium',
                'category' => $question->category ?? 'Custom',
            ])->values() ?? [];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function examSettings(array $settings): array
    {
        return [
            'show_correct_answers' => $settings['show_correct_answers'] ?? 'Never',
            'randomize_questions' => (bool) ($settings['randomize_questions'] ?? false),
            'randomize_choices' => (bool) ($settings['randomize_choices'] ?? false),
            'allow_retake' => (bool) ($settings['allow_retake'] ?? false),
            'maximum_attempts' => (int) ($settings['maximum_attempts'] ?? 1),
            'auto_grade' => (bool) ($settings['auto_grade'] ?? true),
            'manual_review_required' => (bool) ($settings['manual_review_required'] ?? false),
        ];
    }

    private function examAssignmentResponse(RecruitmentExamAssignment $assignment, bool $includeAnswers = false): array
    {
        $data = [
            'id' => $assignment->id,
            'applicant_id' => $assignment->applicant_id,
            'applicant_name' => $assignment->applicant?->full_name,
            'applicant_no' => $assignment->applicant?->applicant_no,
            'exam_template_id' => $assignment->exam_template_id,
            'exam_title' => $assignment->template?->title,
            'duration_minutes' => $assignment->template?->duration_minutes,
            'passing_score' => $assignment->template?->passing_score,
            'questions_count' => (int) ($assignment->template?->getAttribute('questions_count') ?? 0),
            'assigned_by' => $assignment->assigned_by,
            'exam_link_token' => $assignment->exam_link_token,
            'exam_url' => url('/recruitment/exam/'.$assignment->exam_link_token),
            'scheduled_at' => $assignment->scheduled_at?->toIso8601String(),
            'expires_at' => $assignment->expires_at?->toIso8601String(),
            'attempt_number' => $assignment->attempt_number ?? 1,
            'max_attempts' => $assignment->max_attempts ?? 1,
            'one_time_access' => (bool) $assignment->one_time_access,
            'require_login' => (bool) $assignment->require_login,
            'started_at' => $assignment->started_at?->toIso8601String(),
            'submitted_at' => $assignment->submitted_at?->toIso8601String(),
            'score' => $assignment->score,
            'result' => $assignment->result,
            'status' => $assignment->status,
            'recruiter_notes' => $assignment->recruiter_notes,
            'remarks' => $assignment->remarks,
            'recommendation' => $assignment->recommendation,
            'created_at' => $assignment->created_at?->toIso8601String(),
        ];
        if ($includeAnswers) {
            $data['answers'] = $assignment->answers?->map(fn (RecruitmentExamAnswer $answer) => [
                'id' => $answer->id,
                'question_id' => $answer->question_id,
                'question' => $answer->question?->question,
                'question_type' => $answer->question?->question_type,
                'answer' => $answer->answer,
                'correct_answer' => $answer->question?->correct_answer,
                'file_path' => $answer->file_path,
                'score' => $answer->score,
                'points' => $answer->question?->points,
                'checked_by' => $answer->checked_by,
            ])->values() ?? [];
        }

        return $data;
    }

    private function publicExamResponse(RecruitmentExamAssignment $assignment): array
    {
        $settings = $this->examSettings($assignment->template?->settings ?? []);

        return [
            'assignment_id' => $assignment->id,
            'applicant_name' => $assignment->applicant?->full_name,
            'position_applied' => $assignment->applicant?->applied_position ?: $assignment->applicant?->appliedPosition?->name,
            'company' => $assignment->applicant?->department?->name,
            'branch' => $assignment->applicant?->department?->branch?->name,
            'title' => $assignment->template?->title,
            'category' => $assignment->template?->category ?? 'Custom',
            'instructions' => $assignment->template?->instructions,
            'settings' => $settings,
            'duration_minutes' => $assignment->template?->duration_minutes,
            'passing_score' => $assignment->template?->passing_score,
            'status' => $assignment->status,
            'result' => $assignment->result,
            'score' => $assignment->score,
            'attempt_number' => $assignment->attempt_number ?? 1,
            'max_attempts' => $assignment->max_attempts ?? 1,
            'expires_at' => $assignment->expires_at?->toIso8601String(),
            'started_at' => $assignment->started_at?->toIso8601String(),
            'questions' => $assignment->template?->questions?->map(fn (RecruitmentExamQuestion $question) => [
                'id' => $question->id,
                'question_type' => $question->question_type,
                'question' => $question->question,
                'choices' => $question->choices ?? [],
                'points' => $question->points,
                'difficulty' => $question->difficulty ?? 'Medium',
                'category' => $question->category ?? 'Custom',
            ])->values() ?? [],
        ];
    }
}
