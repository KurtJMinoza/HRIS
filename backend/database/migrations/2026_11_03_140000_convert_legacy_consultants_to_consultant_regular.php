<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plain "Consultant" is no longer assignable; existing holders move to Consultant – Regular
     * (Consultant Payroll module, Regular payroll/leave rules).
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'employment_status')) {
            return;
        }

        $legacy = DB::table('users')
            ->whereRaw("LOWER(TRIM(COALESCE(employment_status, ''))) IN ('consultant', 'consultancy')")
            ->get(['id', 'employment_status']);

        $now = now();
        foreach ($legacy as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'employment_status' => 'consultant_regular',
                'updated_at' => $now,
            ]);

            if (Schema::hasTable('employee_status_histories')) {
                DB::table('employee_status_histories')->insert([
                    'user_id' => $user->id,
                    'previous_status' => $user->employment_status,
                    'new_status' => 'consultant_regular',
                    'effective_date' => $now->toDateString(),
                    'trigger_type' => 'system_migration',
                    'actor_id' => null,
                    'remarks' => 'Legacy Consultant status retired; converted to Consultant – Regular.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_status_histories')) {
            return;
        }

        $converted = DB::table('employee_status_histories')
            ->where('trigger_type', 'system_migration')
            ->where('new_status', 'consultant_regular')
            ->get(['id', 'user_id', 'previous_status']);

        foreach ($converted as $row) {
            DB::table('users')
                ->where('id', $row->user_id)
                ->where('employment_status', 'consultant_regular')
                ->update(['employment_status' => $row->previous_status]);
            DB::table('employee_status_histories')->where('id', $row->id)->delete();
        }
    }
};
