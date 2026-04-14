<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Employees now require `delivered_at` to see a payslip. Pre-existing rows that were already
     * published (finalized / emailed / etc.) are treated as delivered for continuity.
     */
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('payslips')) {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('payslips', 'delivered_at')) {
            return;
        }

        DB::table('payslips')
            ->whereNull('delivered_at')
            ->whereIn('status', ['finalized', 'generated', 'emailed', 'viewed'])
            ->update([
                'delivered_at' => DB::raw('COALESCE(emailed_at, finalized_at, updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        // Non-destructive: cannot know which rows were backfilled vs set by Send.
    }
};
