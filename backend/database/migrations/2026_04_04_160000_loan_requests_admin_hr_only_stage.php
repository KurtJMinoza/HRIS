<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loan requests no longer use a line-manager step; pending items go straight to Admin (HR).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pay_loan_requests')
            ->where('status', 'pending')
            ->where('approval_stage', 'pending_first')
            ->whereNull('first_approved_at')
            ->update(['approval_stage' => 'pending_second']);
    }

    public function down(): void
    {
        // Non-reversible: we cannot know which rows were originally pending_first vs intentionally pending_second.
    }
};
