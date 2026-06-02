<?php

namespace App\Services;

use App\Events\DashboardCountsUpdated;
use App\Events\NotificationCreated;
use App\Models\HrisNotification;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    public const MODULES = [
        'dashboard',
        'leave',
        'overtime',
        'attendance_correction',
        'payroll',
        'payslip',
        'attendance',
        'reports',
    ];

    public function notifyUser(User|int $recipient, array $payload): ?HrisNotification
    {
        $user = $recipient instanceof User ? $recipient : User::query()->find($recipient);
        if (! $user) {
            return null;
        }

        $type = (string) ($payload['type'] ?? 'notification.created');
        $module = (string) ($payload['module'] ?? $this->moduleFromType($type));

        $notification = HrisNotification::query()->create([
            'id' => (string) Str::uuid(),
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => $type,
            'title' => (string) ($payload['title'] ?? 'Notification'),
            'message' => $payload['message'] ?? null,
            'module' => $module,
            'entity_id' => $payload['entity_id'] ?? null,
            'entity_type' => $payload['entity_type'] ?? null,
            'action_url' => $payload['action_url'] ?? null,
            'recipient_user_id' => $user->id,
            'recipient_role' => $payload['recipient_role'] ?? $this->recipientRole($user),
            'company_id' => $payload['company_id'] ?? $user->getEffectiveCompanyId(),
            'department_id' => $payload['department_id'] ?? $user->department_id,
            'priority' => $payload['priority'] ?? 'normal',
            'data' => $payload['data'] ?? [],
        ]);

        $this->clearCountCache($user->id);

        try {
            $unreadCount = $this->unreadCount($user);
            $moduleCounts = $this->moduleCounts($user);

            broadcast(new NotificationCreated(
                $notification,
                $unreadCount,
                $moduleCounts,
            ));
            broadcast(new DashboardCountsUpdated((int) $user->id, [
                'module' => $notification->module,
                'entity_id' => $notification->entity_id,
                'type' => $notification->type,
                'notification_counts' => $moduleCounts,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast failed', [
                'notification_id' => $notification->id,
                'recipient_user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }

    /**
     * @param  iterable<User|int>  $recipients
     * @return list<HrisNotification>
     */
    public function notifyUsers(iterable $recipients, array $payload): array
    {
        $created = [];
        foreach ($recipients as $recipient) {
            $notification = $this->notifyUser($recipient, $payload);
            if ($notification) {
                $created[] = $notification;
            }
        }

        return $created;
    }

    public function unreadCount(User|int $recipient): int
    {
        $userId = $recipient instanceof User ? (int) $recipient->id : (int) $recipient;

        return (int) Cache::remember($this->unreadCountKey($userId), 45, function () use ($userId) {
            return HrisNotification::query()
                ->where('recipient_user_id', $userId)
                ->visible()
                ->unread()
                ->count();
        });
    }

    public function moduleCounts(User|int $recipient): array
    {
        $userId = $recipient instanceof User ? (int) $recipient->id : (int) $recipient;

        return Cache::remember($this->moduleCountKey($userId), 45, function () use ($userId) {
            $counts = array_fill_keys(self::MODULES, 0);
            $rows = HrisNotification::query()
                ->selectRaw('module, COUNT(*) as total')
                ->where('recipient_user_id', $userId)
                ->visible()
                ->unread()
                ->groupBy('module')
                ->pluck('total', 'module');

            foreach ($rows as $module => $total) {
                $counts[(string) $module] = (int) $total;
            }

            return $counts;
        });
    }

    public function markRead(HrisNotification $notification): HrisNotification
    {
        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
            $this->clearCountCache((int) $notification->recipient_user_id);
        }

        return $notification;
    }

    public function markAllRead(User|int $recipient, ?string $module = null): int
    {
        $userId = $recipient instanceof User ? (int) $recipient->id : (int) $recipient;
        $query = HrisNotification::query()
            ->where('recipient_user_id', $userId)
            ->visible()
            ->unread();

        if ($module) {
            $query->where('module', $module);
        }

        $updated = $query->update(['read_at' => now(), 'updated_at' => now()]);
        $this->clearCountCache($userId);

        return $updated;
    }

    public function dismiss(HrisNotification $notification): HrisNotification
    {
        if (! $notification->dismissed_at) {
            $notification->forceFill([
                'read_at' => $notification->read_at ?? now(),
                'dismissed_at' => now(),
            ])->save();
            $this->clearCountCache((int) $notification->recipient_user_id);
        }

        return $notification;
    }

    public function markRelatedRead(
        int $recipientUserId,
        string $module,
        ?int $entityId = null,
        ?string $type = null,
    ): int {
        $query = HrisNotification::query()
            ->where('recipient_user_id', $recipientUserId)
            ->where('module', $module)
            ->visible()
            ->unread();

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        $updated = $query->update(['read_at' => now(), 'updated_at' => now()]);
        if ($updated > 0) {
            $this->clearCountCache($recipientUserId);
        }

        return $updated;
    }

    public function clearCountCache(int $userId): void
    {
        Cache::forget($this->unreadCountKey($userId));
        Cache::forget($this->moduleCountKey($userId));
        Cache::forget('pending_approvals:user:'.$userId);
    }

    public function notifyCurrentApprover(
        object $requestModel,
        string $workflowModule,
        string $notificationModule,
        string $type,
        string $title,
        ?string $message = null,
        ?string $actionUrl = null,
    ): ?HrisNotification {
        $pending = OrgApprovalRecord::query()
            ->where('module_type', $workflowModule)
            ->where('request_id', $requestModel->id)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->first();

        if (! $pending?->approver_id) {
            return null;
        }

        return $this->notifyApprovalRecord($pending, $requestModel, $notificationModule, $type, $title, $message, $actionUrl);
    }

    public function notifyApprovalRecord(
        OrgApprovalRecord $record,
        object $requestModel,
        string $notificationModule,
        string $type,
        string $title,
        ?string $message = null,
        ?string $actionUrl = null,
    ): ?HrisNotification {
        if (! $record->approver_id) {
            return null;
        }

        return $this->notifyUser((int) $record->approver_id, [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'module' => $notificationModule,
            'entity_id' => $requestModel->id,
            'entity_type' => $requestModel::class,
            'action_url' => $actionUrl,
            'recipient_role' => $record->approver_role,
            'company_id' => $requestModel->company_id ?? null,
            'department_id' => $requestModel->department_id ?? null,
            'data' => [
                'approval_record_id' => $record->id,
                'approval_label' => $record->approval_label,
                'approval_role' => $record->approver_role,
            ],
        ]);
    }

    public function notifyRequester(
        User|int|null $requester,
        object $requestModel,
        string $notificationModule,
        string $type,
        string $title,
        ?string $message = null,
        ?string $actionUrl = null,
        string $priority = 'normal',
    ): ?HrisNotification {
        if (! $requester) {
            return null;
        }

        return $this->notifyUser($requester, [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'module' => $notificationModule,
            'entity_id' => $requestModel->id,
            'entity_type' => $requestModel::class,
            'action_url' => $actionUrl,
            'company_id' => $requestModel->company_id ?? null,
            'department_id' => $requestModel->department_id ?? null,
            'priority' => $priority,
        ]);
    }

    private function unreadCountKey(int $userId): string
    {
        return 'notification_counts:user:'.$userId;
    }

    private function moduleCountKey(int $userId): string
    {
        return 'notification_module_counts:user:'.$userId;
    }

    private function moduleFromType(string $type): string
    {
        $prefix = Str::before($type, '.');

        return match ($prefix) {
            'attendance' => str_starts_with($type, 'attendance_correction.')
                ? 'attendance_correction'
                : 'attendance',
            'attendance_correction' => 'attendance_correction',
            'leave', 'overtime', 'payroll', 'payslip', 'dashboard' => $prefix,
            default => 'dashboard',
        };
    }

    private function recipientRole(User $user): string
    {
        return (string) ($user->hr_role ?? $user->role ?? 'employee');
    }
}
