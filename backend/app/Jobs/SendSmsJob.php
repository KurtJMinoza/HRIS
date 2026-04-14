<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int  $userId  User ID (for logging and to resolve phone_number)
     * @param  string  $message  SMS body
     * @param  string|null  $context  Audit context (e.g. attendance_clock_in, leave_approved)
     */
    public function __construct(
        public int $userId,
        public string $message,
        public ?string $context = null,
    ) {}

    public function handle(SmsService $smsService): void
    {
        // Only process attendance QR time-in / time-out notifications.
        if ($this->context !== null && ! in_array($this->context, ['attendance_clock_in', 'attendance_clock_out'], true)) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            SmsLog::create([
                'user_id' => $this->userId,
                'to_number' => '',
                'message' => $this->message,
                'context' => $this->context,
                'status' => SmsLog::STATUS_FAILED,
                'error_message' => 'User not found.',
            ]);

            return;
        }

        $phone = SmsService::normalizePhone($user->phone_number ?? '');
        if ($phone === null || $phone === '') {
            SmsLog::create([
                'user_id' => $user->id,
                'to_number' => '',
                'message' => $this->message,
                'context' => $this->context,
                'status' => SmsLog::STATUS_FAILED,
                'error_message' => 'No valid phone number for user.',
            ]);

            return;
        }

        $result = $smsService->sendWithResult($phone, $this->message);

        SmsLog::create([
            'user_id' => $user->id,
            'to_number' => $result['to_number'],
            'message' => $this->message,
            'context' => $this->context,
            'status' => $result['success'] ? SmsLog::STATUS_SUCCESS : SmsLog::STATUS_FAILED,
            'http_status' => $result['http_status'],
            'response_body' => $result['response_body'],
            'error_message' => $result['error_message'],
        ]);
    }
}
