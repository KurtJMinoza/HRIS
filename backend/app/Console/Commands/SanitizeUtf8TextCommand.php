<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Support\TextSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SanitizeUtf8TextCommand extends Command
{
    protected $signature = 'text:sanitize-utf8 {--dry-run : Report changes without writing}';

    protected $description = 'Remove U+FFFD and repair mojibake in common text columns (holidays, companies, employees).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Holiday::query()->orderBy('id')->chunkById(100, function ($holidays) use ($dryRun, &$updated) {
            foreach ($holidays as $holiday) {
                $changes = [];
                foreach (['name', 'description'] as $field) {
                    $raw = $holiday->{$field};
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }
                    $clean = TextSanitizer::clean($raw);
                    if ($clean !== $raw) {
                        $changes[$field] = $clean;
                    }
                }
                if ($changes === []) {
                    continue;
                }
                $updated++;
                $this->line("Holiday #{$holiday->id}: ".json_encode(array_keys($changes)));
                if (! $dryRun) {
                    $holiday->forceFill($changes)->saveQuietly();
                }
            }
        });

        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($dryRun, &$updated) {
            foreach ($companies as $company) {
                if (! is_string($company->name) || $company->name === '') {
                    continue;
                }
                $clean = TextSanitizer::clean($company->name);
                if ($clean === $company->name) {
                    continue;
                }
                $updated++;
                $this->line("Company #{$company->id}: name");
                if (! $dryRun) {
                    $company->forceFill(['name' => $clean])->saveQuietly();
                }
            }
        });

        $userFields = ['name', 'first_name', 'middle_name', 'last_name', 'suffix', 'home_address', 'full_address'];
        User::query()->orderBy('id')->chunkById(100, function ($users) use ($dryRun, &$updated, $userFields) {
            foreach ($users as $user) {
                $changes = [];
                foreach ($userFields as $field) {
                    $raw = $user->{$field};
                    if (! is_string($raw) || $raw === '') {
                        continue;
                    }
                    $clean = TextSanitizer::clean($raw);
                    if ($clean !== $raw) {
                        $changes[$field] = $clean;
                    }
                }
                if ($changes === []) {
                    continue;
                }
                $updated++;
                $this->line("User #{$user->id}: ".json_encode(array_keys($changes)));
                if (! $dryRun) {
                    $user->forceFill($changes)->saveQuietly();
                }
            }
        });

        // Payslip snapshot JSON may embed corrupted labels.
        if (DB::getSchemaBuilder()->hasTable('payslips')) {
            DB::table('payslips')->orderBy('id')->chunkById(50, function ($rows) use ($dryRun, &$updated) {
                foreach ($rows as $row) {
                    $snapshot = $row->snapshot ?? null;
                    if (! is_string($snapshot) || $snapshot === '') {
                        continue;
                    }
                    if (! str_contains($snapshot, "\xEF\xBF\xBD") && ! str_contains($snapshot, 'ï¿½')) {
                        continue;
                    }
                    $clean = TextSanitizer::clean($snapshot);
                    if ($clean === $snapshot) {
                        continue;
                    }
                    $updated++;
                    $this->line("Payslip #{$row->id}: snapshot");
                    if (! $dryRun) {
                        DB::table('payslips')->where('id', $row->id)->update(['snapshot' => $clean]);
                    }
                }
            });
        }

        $this->info(($dryRun ? 'Would update' : 'Updated')." {$updated} record(s).");

        return self::SUCCESS;
    }
}
