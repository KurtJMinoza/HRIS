<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailNotificationJob;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\EmailTemplate;
use App\Services\EmailNotificationService;
use App\Support\AgcEmailTemplateBuilder as B;
use App\Support\BrandedEmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailNotificationController extends Controller
{
    public function __construct(
        private readonly EmailNotificationService $emailService,
    ) {}

    public function index(): JsonResponse
    {
        $settings = EmailNotificationSetting::query()
            ->with('template:id,template_key,subject,is_active')
            ->orderBy('notification_key')
            ->get()
            ->map(fn (EmailNotificationSetting $s): array => [
                'id' => (int) $s->id,
                'notification_key' => $s->notification_key,
                'label' => $s->label,
                'description' => $s->description,
                'enabled' => $s->enabled,
                'recipient_type' => $s->recipient_type,
                'custom_recipient_email' => $s->custom_recipient_email,
                'template_id' => $s->template_id,
                'template_key' => $s->template?->template_key,
                'template_subject' => $s->template?->subject,
                'queue_name' => $s->queue_name,
                'retry_attempts' => (int) $s->retry_attempts,
                'updated_at' => $s->updated_at?->toIso8601String(),
            ]);

        return response()->json(['settings' => $settings]);
    }

    public function updateSetting(Request $request, int $id): JsonResponse
    {
        $setting = EmailNotificationSetting::findOrFail($id);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'recipient_type' => ['sometimes', 'string', 'in:employee,current_approver,hr_admin,payroll_admin,department_head,custom'],
            'custom_recipient_email' => ['nullable', 'email', 'max:255'],
            'queue_name' => ['sometimes', 'string', 'max:100'],
            'retry_attempts' => ['sometimes', 'integer', 'min:0', 'max:10'],
        ]);

        $setting->update($validated);
        $this->emailService->clearCache();

        return response()->json([
            'message' => 'Email notification setting updated.',
            'setting' => $setting->fresh(['template:id,template_key,subject,is_active']),
        ]);
    }

    public function templates(): JsonResponse
    {
        $templates = EmailTemplate::query()
            ->orderBy('template_key')
            ->get()
            ->map(fn (EmailTemplate $t): array => [
                'id' => (int) $t->id,
                'template_key' => $t->template_key,
                'subject' => $t->subject,
                'body_html' => $t->body_html,
                'body_text' => $t->body_text,
                'is_active' => $t->is_active,
                'updated_by' => $t->updated_by,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]);

        return response()->json(['templates' => $templates]);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);

        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'max:500'],
            'body_html' => ['sometimes', 'string', 'max:65000'],
            'body_text' => ['nullable', 'string', 'max:65000'],
        ]);

        $validated['updated_by'] = $request->user()?->id;
        $template->update($validated);
        $this->emailService->clearCache();

        return response()->json([
            'message' => 'Email template updated.',
            'template' => $template->fresh(),
        ]);
    }

    public function previewTemplate(int $id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);
        $rendered = $this->emailService->renderTemplatePreview($template);

        return response()->json([
            'subject' => $rendered['subject'],
            'body_html' => $rendered['body_html'],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $query = EmailLog::query()
            ->select([
                'id', 'recipient_email', 'recipient_user_id', 'notification_key',
                'subject', 'status', 'sent_at', 'failed_at', 'error_message',
                'related_type', 'related_id', 'created_at',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('notification_key')) {
            $query->where('notification_key', (string) $request->input('notification_key'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $logs = $query->paginate(
            min((int) $request->input('per_page', 25), 100)
        );

        return response()->json($logs);
    }

    public function retryLog(int $id): JsonResponse
    {
        $log = EmailLog::findOrFail($id);

        if ($log->status !== 'failed') {
            return response()->json(['message' => 'Only failed emails can be retried.'], 422);
        }

        $setting = $this->emailService->getSetting($log->notification_key);
        $template = $this->emailService->getTemplate($log->notification_key);

        $log->update([
            'status' => 'queued',
            'error_message' => null,
            'failed_at' => null,
        ]);

        $bodyHtml = $template?->body_html ?? '<p>Retried email — original template unavailable.</p>';
        $retries = $setting->retry_attempts ?? 3;

        SendEmailNotificationJob::dispatch(
            $log->id,
            $log->recipient_email,
            $log->subject,
            $bodyHtml,
            $retries,
        )->onQueue($setting->queue_name ?? 'emails');

        return response()->json(['message' => 'Email queued for retry.']);
    }

    public function testEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'notification_key' => ['nullable', 'string', 'max:255'],
        ]);

        $recipientEmail = $validated['email'];
        $notificationKey = $validated['notification_key'] ?? null;
        $subject = 'AGCTEK HRIS — Email Delivery Test';
        $sampleVariables = [
            'employee_name' => 'Test Employee',
            'date' => now()->toDateString(),
            'time' => now()->format('h:i A'),
            'request_type' => 'Test',
            'status' => 'approved',
            'approver_name' => 'Test Approver',
            'action_url' => config('app.frontend_url', config('app.url')),
            'pay_period' => 'June 1-15, 2026',
            'leave_type' => 'Vacation Leave',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'hours' => '2',
            'scheduled_time' => '08:00 AM',
        ];
        $bodyHtml = $this->emailService->renderAdHocHtml(
            B::layout(
                B::title('Email Delivery Test')
                .B::greeting('Test Recipient')
                .B::paragraph('This message confirms that the AGCTEK HRIS email notification service is configured correctly.')
                .B::paragraph('If you received this email, SMTP delivery and the standard notification template are working as expected.')
                .B::cta('Open HRIS')
                .B::closing(),
                'AGCTEK HRIS email delivery test'
            ),
            array_merge($sampleVariables, ['employee_name' => 'Test Recipient'])
        );

        if ($notificationKey) {
            $template = $this->emailService->getTemplate($notificationKey);
            if ($template) {
                $rendered = $this->emailService->renderTemplate($template, $sampleVariables);
                $subject = $rendered['subject'];
                $bodyHtml = $rendered['body_html'];
            }
        }

        $emailLog = EmailLog::create([
            'recipient_email' => $recipientEmail,
            'notification_key' => $notificationKey ?? 'test',
            'subject' => $subject,
            'status' => 'queued',
        ]);

        try {
            BrandedEmailSender::send($recipientEmail, $subject, $bodyHtml);

            $emailLog->update([
                'status' => 'sent',
                'sent_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $emailLog->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return response()->json([
                'message' => 'Test email failed to send.',
                'email_log_id' => (int) $emailLog->id,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['message' => 'Test email sent.', 'email_log_id' => (int) $emailLog->id]);
    }

    public function clearCache(): JsonResponse
    {
        $this->emailService->clearCache();

        return response()->json(['message' => 'Email notification cache cleared.']);
    }
}
