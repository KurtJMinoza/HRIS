<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Models\User;
use App\Services\AttendanceSummarySyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the attendance_daily_summaries read model by calling the existing
 * monitoring computation for batches of dates.
 *
 * Usage: php artisan attendance:sync-summaries --days=7
 */
class SyncAttendanceSummariesCommand extends Command
{
    protected $signature = 'attendance:sync-summaries
        {--days=7 : Number of past days to sync}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--batch=5 : Days per batch}';

    protected $description = 'Backfill attendance_daily_summaries read model from existing attendance computation.';

    public function handle(AttendanceSummarySyncService $syncService): int
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'), $tz)
            : Carbon::now($tz);
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'), $tz)
            : $to->copy()->subDays((int) $this->option('days'));

        $batchSize = max(1, (int) $this->option('batch'));

        $this->info("Syncing attendance summaries from {$from->toDateString()} to {$to->toDateString()} in {$batchSize}-day batches.");

        $admin = User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->first();

        if (! $admin) {
            $this->error('No active admin user found to run scoped queries.');
            return 1;
        }

        $controller = app(AttendanceMonitoringController::class);
        $cursor = $from->copy();
        $totalSynced = 0;

        while ($cursor->lessThanOrEqualTo($to)) {
            $batchEnd = $cursor->copy()->addDays($batchSize - 1)->min($to);

            $this->line("  Batch: {$cursor->toDateString()} – {$batchEnd->toDateString()}");

            $request = Request::create('/api/admin/attendance', 'GET', [
                'from_date' => $cursor->toDateString(),
                'to_date' => $batchEnd->toDateString(),
                'per_page' => 100,
                'page' => 1,
            ]);
            $request->setUserResolver(fn () => $admin);

            $response = $controller->index($request);
            $data = json_decode($response->getContent(), true);
            $rows = $data['rows'] ?? [];

            if ($rows !== []) {
                $syncService->syncBatch($rows);
                $totalSynced += count($rows);
                $this->line("    Synced " . count($rows) . " rows.");
            }

            $lastPage = $data['meta']['last_page'] ?? 1;
            for ($page = 2; $page <= $lastPage; $page++) {
                $request = Request::create('/api/admin/attendance', 'GET', [
                    'from_date' => $cursor->toDateString(),
                    'to_date' => $batchEnd->toDateString(),
                    'per_page' => 100,
                    'page' => $page,
                ]);
                $request->setUserResolver(fn () => $admin);

                $response = $controller->index($request);
                $data = json_decode($response->getContent(), true);
                $pageRows = $data['rows'] ?? [];
                if ($pageRows !== []) {
                    $syncService->syncBatch($pageRows);
                    $totalSynced += count($pageRows);
                }
            }

            $cursor->addDays($batchSize);
        }

        $this->info("Done. Synced {$totalSynced} total attendance summary rows.");
        Log::info('attendance:sync-summaries completed', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_synced' => $totalSynced,
        ]);

        return 0;
    }
}
