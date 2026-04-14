<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Unassign employees whose working_schedule_id points to a deleted schedule.
     */
    public function up(): void
    {
        $validIds = DB::table('working_schedules')->pluck('id');
        DB::table('users')
            ->whereNotNull('working_schedule_id')
            ->whereNotIn('working_schedule_id', $validIds)
            ->update([
                'schedule' => null,
                'working_schedule_id' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback - we cannot restore orphaned assignments.
    }
};
