<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CooWelcomeLetterService;
use Illuminate\Console\Command;

class ResetCooWelcomeLetterCommand extends Command
{
    protected $signature = 'welcome:reset-coo-letter
        {--user=1842 : Employee user id (default: Rocelyn G. Cortez)}';

    protected $description = 'Reset the one-time COO welcome letter so it appears again on the user\'s next login';

    public function handle(CooWelcomeLetterService $service): int
    {
        $userId = (int) $this->option('user');
        if ($userId <= 0) {
            $this->error('Invalid --user id.');

            return self::FAILURE;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            $this->error("User id {$userId} not found.");

            return self::FAILURE;
        }

        $wasDismissed = $service->reset($user);
        if ($wasDismissed) {
            $this->info("COO welcome letter reset for {$user->display_name} (user id {$userId}).");
            $this->line('It will appear again the next time this person logs in (or refreshes after login).');
        } else {
            $this->warn("No dismissal record found for {$user->display_name} (user id {$userId}).");
            $this->line('The welcome letter is already set to appear on next login.');
        }

        return self::SUCCESS;
    }
}
