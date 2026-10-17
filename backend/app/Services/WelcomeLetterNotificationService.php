<?php

namespace App\Services;

use App\Models\HrisNotification;
use App\Models\User;
use Illuminate\Support\Str;

class WelcomeLetterNotificationService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function ensureForUser(User|int $recipient): ?HrisNotification
    {
        $user = $recipient instanceof User ? $recipient : User::query()->find($recipient);
        if (! $user) {
            return null;
        }

        $created = null;
        foreach ((array) config('welcome_letters.letters', []) as $letterKey => $config) {
            if (! is_array($config) || ! $this->userMatches($user, (array) ($config['match'] ?? []))) {
                continue;
            }
            if ($this->alreadySent((int) $user->id, (string) $letterKey)) {
                continue;
            }

            $payload = (array) ($config['notification'] ?? []);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $data['letter_key'] = (string) $letterKey;

            $created = $this->notificationService->notifyUser($user, [
                ...$payload,
                'data' => $data,
                'action_url' => null,
            ]);
        }

        return $created;
    }

    /**
     * Undismissed welcome letter for auto-show on login (one-time until recipient closes the modal).
     *
     * @return array{letter_key: string, notification_id: string}|null
     */
    public function pendingForUser(User|int $recipient): ?array
    {
        $user = $recipient instanceof User ? $recipient : User::query()->find($recipient);
        if (! $user) {
            return null;
        }

        $notification = HrisNotification::query()
            ->where('recipient_user_id', (int) $user->id)
            ->where('type', 'welcome.coo_letter')
            ->whereNull('dismissed_at')
            ->latest('created_at')
            ->first();

        if (! $notification) {
            return null;
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $letterKey = trim((string) ($data['letter_key'] ?? ''));
        if ($letterKey === '') {
            return null;
        }

        return [
            'letter_key' => $letterKey,
            'notification_id' => (string) $notification->id,
        ];
    }

    private function userMatches(User $user, array $match): bool
    {
        if ($match === []) {
            return false;
        }

        $last = Str::lower(trim((string) $user->last_name));
        $first = Str::lower(trim((string) $user->first_name));
        $middle = Str::lower(trim((string) ($user->middle_name ?? '')));

        if (isset($match['last_name']) && $last !== Str::lower(trim((string) $match['last_name']))) {
            return false;
        }
        if (isset($match['first_name']) && $first !== Str::lower(trim((string) $match['first_name']))) {
            return false;
        }
        if (isset($match['middle_name']) && trim((string) $match['middle_name']) !== '') {
            $expectedMiddle = Str::lower(trim((string) $match['middle_name']));
            if ($middle === '' || (! Str::startsWith($middle, $expectedMiddle) && ! Str::startsWith($expectedMiddle, $middle))) {
                return false;
            }
        }

        return true;
    }

    private function alreadySent(int $userId, string $letterKey): bool
    {
        return HrisNotification::query()
            ->where('recipient_user_id', $userId)
            ->where('type', 'welcome.coo_letter')
            ->where('data->letter_key', $letterKey)
            ->exists();
    }
}
