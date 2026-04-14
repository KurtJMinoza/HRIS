<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data integrity: Company Heads must have company_id set to the company they head.
     * Backfill users who are company heads but have NULL or mismatched company_id.
     */
    public function up(): void
    {
        $companies = DB::table('companies')->whereNotNull('company_head_id')->get();
        foreach ($companies as $company) {
            DB::table('users')
                ->where('id', $company->company_head_id)
                ->where(function ($q) use ($company) {
                    $q->whereNull('company_id')->orWhere('company_id', '!=', $company->id);
                })
                ->update(['company_id' => $company->id]);
        }
    }

    /**
     * Reverse: we cannot safely revert (we don't know the previous company_id).
     */
    public function down(): void
    {
        // No-op — data fix cannot be reverted.
    }
};
