<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payslips must only appear in self-service (My Payslips / Payslips) after HR uses
 * "Send payslips" ({@see \App\Services\PayslipDeliveryService}), which sets {@see \App\Models\Payslip::$delivered_at}.
 *
 * The earlier backfill ({@see 2026_04_13_120000_backfill_delivered_at_on_existing_payslips}) set {@code delivered_at}
 * from finalized/emailed timestamps, which incorrectly showed payslips before an explicit Send.
 * This clears {@code delivered_at} for published rows so visibility is strictly gated by Send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslips') || ! Schema::hasColumn('payslips', 'delivered_at')) {
            return;
        }

        DB::table('payslips')
            ->whereIn('status', ['finalized', 'generated', 'emailed', 'viewed'])
            ->update(['delivered_at' => null]);
    }

    public function down(): void
    {
        // Intentionally empty: re-backfilling would restore the permissive visibility.
    }
};
