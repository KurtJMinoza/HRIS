<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedule_days', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_schedule_days', 'expected_paid_minutes')) {
                $table->unsignedInteger('expected_paid_minutes')->nullable()->after('break_minutes');
            }
            if (! Schema::hasColumn('working_schedule_days', 'early_timein_minutes')) {
                $table->unsignedSmallInteger('early_timein_minutes')->nullable()->after('grace_period_minutes');
            }
            if (! Schema::hasColumn('working_schedule_days', 'overtime_buffer_minutes')) {
                $table->unsignedSmallInteger('overtime_buffer_minutes')->nullable()->after('early_timein_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_schedule_days', function (Blueprint $table): void {
            $cols = array_values(array_filter([
                Schema::hasColumn('working_schedule_days', 'expected_paid_minutes') ? 'expected_paid_minutes' : null,
                Schema::hasColumn('working_schedule_days', 'early_timein_minutes') ? 'early_timein_minutes' : null,
                Schema::hasColumn('working_schedule_days', 'overtime_buffer_minutes') ? 'overtime_buffer_minutes' : null,
            ]));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
