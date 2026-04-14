<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Clear orphaned schedule JSON for employees whose working_schedule_id is null
     * (e.g. schedule was deleted with nullOnDelete). Makes them show as "Available".
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('working_schedule_id')
            ->whereNotNull('schedule')
            ->where('role', 'employee')
            ->update(['schedule' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback.
    }
};
