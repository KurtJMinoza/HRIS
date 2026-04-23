<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteImportedEmployeesCommand extends Command
{
    protected $signature = 'hr:delete-imported-employees
                            {--batch= : UUID stored in users.employee_import_batch_id}
                            {--since= : Delete employees with created_at >= this datetime (Y-m-d or Y-m-d H:i:s)}
                            {--until= : Only when using --since: created_at <= this datetime (default: now)}
                            {--except= : Comma-separated user IDs to never delete}
                            {--dry-run : Show counts and sample IDs only}';

    protected $description = 'Delete employee users from a bulk import (by import batch UUID or created_at window).';

    public function handle(): int
    {
        $batch = $this->option('batch');
        $since = $this->option('since');
        $exceptRaw = (string) ($this->option('except') ?? '');
        $exceptIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $exceptRaw)))));

        if ($batch !== null && $batch !== '') {
            $q = User::query()
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('employee_import_batch_id', (string) $batch);
        } elseif ($since !== null && $since !== '') {
            $until = $this->option('until') ?: now()->toDateTimeString();
            $q = User::query()
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('created_at', '>=', $since)
                ->where('created_at', '<=', $until);
        } else {
            $this->error('Provide --batch=UUID or --since=datetime (optionally with --until=).');

            return self::FAILURE;
        }

        if ($exceptIds !== []) {
            $q->whereNotIn('id', $exceptIds);
        }

        $ids = $q->orderBy('id')->pluck('id')->all();
        $n = count($ids);
        $this->info("Matched {$n} employee user(s).");
        if ($n > 0) {
            $this->line('Sample IDs: '.implode(', ', array_slice($ids, 0, 20)).($n > 20 ? ' …' : ''));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        if ($n === 0) {
            return self::SUCCESS;
        }

        if (! $this->confirm("Permanently delete {$n} employee user(s)?", false)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $deleted = 0;
        DB::transaction(function () use ($ids, &$deleted): void {
            foreach (array_chunk($ids, 100) as $chunk) {
                foreach ($chunk as $id) {
                    $u = User::query()->whereKey($id)->where('role', User::ROLE_EMPLOYEE)->first();
                    if ($u) {
                        $u->delete();
                        $deleted++;
                    }
                }
            }
        });

        $this->info("Deleted {$deleted} employee user(s).");

        return self::SUCCESS;
    }
}
