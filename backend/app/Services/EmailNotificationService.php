<?php

namespace App\Services;

use App\Jobs\SendEmailNotificationJob;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\EmailTemplate;
use App\Support\AgcEmailTemplateBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailNotificationService
{
    private const SETTINGS_CACHE_KEY = 'email_notification_settings';

    private const TEMPLATE_CACHE_PREFIX = 'email_template:';

    private const CACHE_TTL = 600;

    public function getSettings(): Collection
    {
        return Cache::remember(self::SETTINGS_CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return EmailNotificationSetting::all();
        });
    }

    public function getSetting(string $notificationKey): ?EmailNotificationSetting
    {
        return $this->getSettings()->firstWhere('notification_key', $notificationKey);
    }

    public function isEnabled(string $notificationKey): bool
    {
        $setting = $this->getSetting($notificationKey);

        return $setting !== null && $setting->enabled;
    }

    public function getTemplate(string $templateKey): ?EmailTemplate
    {
        return Cache::remember(self::TEMPLATE_CACHE_PREFIX.$templateKey, self::CACHE_TTL, function () use ($templateKey): ?EmailTemplate {
            return EmailTemplate::query()
                ->where('template_key', $templateKey)
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Resolve recipient email based on setting recipient_type and context.
     *
     * @param  array{employee?: User, approver_id?: int, department_head_id?: int}  $context
     */
    public function resolveRecipientEmail(string $notificationKey, array $context): ?string
    {
        $setting = $this->getSetting($notificationKey);
        if (! $setting) {
            return null;
        }

        return match ($setting->recipient_type) {
            'employee' => ($context['employee'] ?? null)?->email,
            'current_approver' => $this->resolveUserEmail($context['approver_id'] ?? null),
            'hr_admin' => $this->resolveFirstAdminEmail('admin_hr'),
            'payroll_admin' => $this->resolveFirstAdminEmail('payroll_admin'),
            'department_head' => $this->resolveUserEmail($context['department_head_id'] ?? null),
            'custom' => $setting->custom_recipient_email,
            default => ($context['employee'] ?? null)?->email,
        };
    }

    /**
     * Render template: replace {{ variable }} placeholders in subject and body_html.
     *
     * @return array{subject: string, body_html: string}
     */
    public function renderTemplate(EmailTemplate $template, array $variables, bool $forPreview = false): array
    {
        $variables = array_merge($this->defaultTemplateVariables($forPreview), $variables);

        $subject = $template->subject;
        $bodyHtml = AgcEmailTemplateBuilder::normalizeLogoImgTag($template->body_html);

        foreach ($variables as $key => $value) {
            $placeholder = '{{ '.$key.' }}';
            $safeValue = $key === 'logo_url'
                ? (string) ($value ?? '')
                : e((string) ($value ?? ''));
            $subject = str_replace($placeholder, (string) ($value ?? ''), $subject);
            $bodyHtml = str_replace($placeholder, $safeValue, $bodyHtml);
        }

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
        ];
    }

    public function renderAdHocHtml(string $html, array $variables = [], bool $forPreview = false): string
    {
        $html = AgcEmailTemplateBuilder::normalizeLogoImgTag($html);
        $variables = array_merge($this->defaultTemplateVariables($forPreview), $variables);

        foreach ($variables as $key => $value) {
            $placeholder = '{{ '.$key.' }}';
            $safeValue = $key === 'logo_url'
                ? (string) ($value ?? '')
                : e((string) ($value ?? ''));
            $html = str_replace($placeholder, $safeValue, $html);
        }

        return $html;
    }

    /**
     * Queue an email notification — the main entry point.
     *
     * @param  array{employee?: User, approver_id?: int, department_head_id?: int}  $context
     */
    public function send(string $notificationKey, array $context, array $variables, ?Model $relatedModel = null): void
    {
        if (! $this->isEnabled($notificationKey)) {
            return;
        }

        $recipientEmail = $this->resolveRecipientEmail($notificationKey, $context);
        if ($recipientEmail === null || $recipientEmail === '') {
            Log::warning('email_notification: no recipient resolved', ['notification_key' => $notificationKey]);

            return;
        }

        $setting = $this->getSetting($notificationKey);
        $template = $this->getTemplate($notificationKey);
        if (! $template) {
            Log::warning('email_notification: template not found', ['notification_key' => $notificationKey]);

            return;
        }

        $rendered = $this->renderTemplate($template, $variables);

        $recipientUserId = ($context['employee'] ?? null)?->id;
        if ($setting->recipient_type === 'current_approver') {
            $recipientUserId = $context['approver_id'] ?? null;
        } elseif ($setting->recipient_type === 'department_head') {
            $recipientUserId = $context['department_head_id'] ?? null;
        }

        $emailLog = EmailLog::create([
            'recipient_email' => $recipientEmail,
            'recipient_user_id' => $recipientUserId,
            'notification_key' => $notificationKey,
            'subject' => $rendered['subject'],
            'status' => 'queued',
            'related_type' => $relatedModel ? $relatedModel->getMorphClass() : null,
            'related_id' => $relatedModel?->getKey(),
        ]);

        $job = new SendEmailNotificationJob(
            $emailLog->id,
            $recipientEmail,
            $rendered['subject'],
            $rendered['body_html'],
            $setting->retry_attempts ?? 3,
        );

        dispatch($job)->onQueue($setting->queue_name ?? 'emails');
    }

    public function clearCache(): void
    {
        Cache::forget(self::SETTINGS_CACHE_KEY);

        $settings = EmailNotificationSetting::all();
        foreach ($settings as $setting) {
            Cache::forget(self::TEMPLATE_CACHE_PREFIX.$setting->notification_key);
        }
    }

    /**
     * @return array<string, string>
     */
    public function defaultTemplateVariables(bool $forPreview = false): array
    {
        return [
            'company_name' => trim((string) config('mail.from.name')) ?: 'AGCTEK HRIS',
            'logo_url' => $forPreview
                ? AgcEmailTemplateBuilder::embeddedLogoDataUri()
                : AgcEmailTemplateBuilder::logoUrl(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sampleTemplateVariables(): array
    {
        return [
            'employee_name' => 'Juan Dela Cruz',
            'date' => now()->toDateString(),
            'time' => now()->format('h:i A'),
            'request_type' => 'Time correction',
            'status' => 'Pending approval',
            'approver_name' => 'Maria Santos',
            'action_url' => config('app.frontend_url', config('app.url')),
            'pay_period' => 'June 1–15, 2026',
            'leave_type' => 'Vacation Leave',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'hours' => '2',
            'scheduled_time' => '08:00 AM',
            'branch_name' => 'Main Office',
        ];
    }

    /**
     * @return array{subject: string, body_html: string}
     */
    public function renderTemplatePreview(EmailTemplate $template): array
    {
        return $this->renderTemplate($template, $this->sampleTemplateVariables(), forPreview: true);
    }

    private function resolveUserEmail(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return User::query()
            ->where('id', $userId)
            ->value('email');
    }

    private function resolveFirstAdminEmail(string $hrRole): ?string
    {
        return User::query()
            ->where('hr_role', $hrRole)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->value('email');
    }
}
