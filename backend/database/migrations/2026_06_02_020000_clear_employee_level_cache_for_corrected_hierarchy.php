<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'employee_level')) {
            return;
        }

        DB::table('users')->update([
            'employee_level' => DB::raw("CASE employee_level
                WHEN 8 THEN 6
                WHEN 7 THEN 5
                WHEN 6 THEN 4
                WHEN 5 THEN 3
                WHEN 4 THEN 2
                WHEN 3 THEN 1
                WHEN 2 THEN 1
                WHEN 1 THEN 0
                WHEN 0 THEN 0
                ELSE NULL
            END"),
        ]);

        if (Schema::hasColumn('users', 'employee_level_label')) {
            DB::table('users')->update([
                'employee_level_label' => DB::raw("CASE employee_level
                    WHEN 6 THEN 'Level 6 - Admin'
                    WHEN 5 THEN 'Level 5 - Company Head / Executive'
                    WHEN 4 THEN 'Level 4 - Branch Head'
                    WHEN 3 THEN 'Level 3 - Division Head'
                    WHEN 2 THEN 'Level 2 - Department Head'
                    WHEN 1 THEN 'Level 1 - OIC / Team Leader / Unit/Section Head'
                    WHEN 0 THEN 'Level 0 - Staff / Employee'
                    ELSE NULL
                END"),
            ]);
        }

        $updates = [];
        if (Schema::hasColumn('users', 'employee_level_resolved_at')) {
            $updates['employee_level_resolved_at'] = now();
        }

        if ($updates !== []) {
            DB::table('users')->update($updates);
        }
    }

    public function down(): void
    {
        // No rollback: this migration only clears derived cache values.
    }
};
