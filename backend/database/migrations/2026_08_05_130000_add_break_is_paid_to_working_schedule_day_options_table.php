<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('working_schedule_day_options')
            && ! Schema::hasColumn('working_schedule_day_options', 'break_is_paid')) {
            Schema::table('working_schedule_day_options', function (Blueprint $table): void {
                $table->boolean('break_is_paid')->default(false)->after('break_end');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('working_schedule_day_options')
            && Schema::hasColumn('working_schedule_day_options', 'break_is_paid')) {
            Schema::table('working_schedule_day_options', function (Blueprint $table): void {
                $table->dropColumn('break_is_paid');
            });
        }
    }
};
