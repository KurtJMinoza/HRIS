<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        if (! Schema::hasColumn('payroll_periods', 'user_id')
            || ! Schema::hasColumn('payroll_periods', 'from_date')
            || ! Schema::hasColumn('payroll_periods', 'to_date')) {
            return;
        }

        $duplicateGroups = DB::table('payroll_periods')
            ->select('user_id', 'from_date', 'to_date', DB::raw('COUNT(*) as row_count'))
            ->groupBy('user_id', 'from_date', 'to_date')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicateGroups as $group) {
            $ids = DB::table('payroll_periods')
                ->where('user_id', $group->user_id)
                ->whereDate('from_date', $group->from_date)
                ->whereDate('to_date', $group->to_date)
                ->orderByDesc('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $keepId = array_shift($ids);
            if ($keepId === null) {
                continue;
            }

            foreach ($ids as $removeId) {
                if (Schema::hasTable('payroll_breakdowns')) {
                    DB::table('payroll_breakdowns')->where('payroll_period_id', $removeId)->delete();
                }
                DB::table('payroll_periods')->where('id', $removeId)->delete();
            }
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            if (! $this->indexExists('payroll_periods', 'payroll_periods_user_window_unique')) {
                $table->unique(['user_id', 'from_date', 'to_date'], 'payroll_periods_user_window_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            if ($this->indexExists('payroll_periods', 'payroll_periods_user_window_unique')) {
                $table->dropUnique('payroll_periods_user_window_unique');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($row) => ($row->name ?? '') === $indexName);
        }

        $dbName = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$dbName, $table, $indexName]
        );

        return ((int) ($rows[0]->c ?? 0)) > 0;
    }
};
