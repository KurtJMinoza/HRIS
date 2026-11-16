<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserUiDismissal;
use Illuminate\Support\Carbon;

class CooWelcomeLetterService
{
    public const TARGET_USER_ID = 1842;

    public const DISMISSAL_KEY = 'coo_welcome_letter';

    public function isTargetUser(User|int|null $user): bool
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        return $userId === self::TARGET_USER_ID;
    }

    public function shouldShow(User $user): bool
    {
        if (! $this->isTargetUser($user)) {
            return false;
        }

        return ! UserUiDismissal::query()
            ->where('user_id', self::TARGET_USER_ID)
            ->where('dismissal_key', self::DISMISSAL_KEY)
            ->exists();
    }

    public function dismiss(User $user): void
    {
        if (! $this->isTargetUser($user)) {
            return;
        }

        UserUiDismissal::query()->updateOrCreate(
            [
                'user_id' => self::TARGET_USER_ID,
                'dismissal_key' => self::DISMISSAL_KEY,
            ],
            [
                'dismissed_at' => Carbon::now(),
            ]
        );
    }

    public function reset(User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        if ($userId <= 0) {
            return false;
        }

        return UserUiDismissal::query()
            ->where('user_id', $userId)
            ->where('dismissal_key', self::DISMISSAL_KEY)
            ->delete() > 0;
    }
}
