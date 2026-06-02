<?php

namespace App\Events;

use App\Models\HrisNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public HrisNotification $notification,
        public int $unreadCount,
        public array $moduleCounts,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->notification->recipient_user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id' => $this->notification->id,
                'type' => $this->notification->type,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'module' => $this->notification->module,
                'entity_id' => $this->notification->entity_id,
                'entity_type' => $this->notification->entity_type,
                'action_url' => $this->notification->action_url,
                'recipient_user_id' => $this->notification->recipient_user_id,
                'recipient_role' => $this->notification->recipient_role,
                'company_id' => $this->notification->company_id,
                'department_id' => $this->notification->department_id,
                'priority' => $this->notification->priority,
                'read_at' => optional($this->notification->read_at)->toISOString(),
                'created_at' => optional($this->notification->created_at)->toISOString(),
                'data' => $this->notification->data ?? [],
            ],
            'unread_count' => $this->unreadCount,
            'module_counts' => $this->moduleCounts,
        ];
    }
}
