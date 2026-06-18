<?php

namespace App\Jobs;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\BrandedEmailSender;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(
        public int $emailLogId,
        public string $recipientEmail,
        public string $subject,
        public string $bodyHtml,
        int $retryAttempts = 3,
    ) {
        $this->tries = $retryAttempts;
    }

    public function handle(): void
    {
        try {
            BrandedEmailSender::send($this->recipientEmail, $this->subject, $this->bodyHtml);

            EmailLog::query()
                ->where('id', $this->emailLogId)
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::error('email_notification: send failed', [
                'email_log_id' => $this->emailLogId,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                EmailLog::query()
                    ->where('id', $this->emailLogId)
                    ->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        EmailLog::query()
            ->where('id', $this->emailLogId)
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }
}
