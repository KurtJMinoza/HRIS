<?php

namespace App\Jobs;

use App\Models\HrisNotification;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MarkNotificationsReadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $userId,
        private readonly ?string $module = null,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $now = now();
        HrisNotification::query()
            ->where('recipient_user_id', $this->userId)
            ->visible()
            ->unread()
            ->when($this->module !== null && $this->module !== '', fn ($q) => $q->where('module', $this->module))
            ->update(['read_at' => $now, 'updated_at' => $now]);

        $notificationService->clearCountCache($this->userId);
    }
}
