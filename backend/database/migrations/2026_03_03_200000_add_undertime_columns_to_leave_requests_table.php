<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->time('shift_end_time')->nullable()->after('undertime_time');
            $table->time('actual_clock_out_time')->nullable()->after('shift_end_time');
            $table->integer('undertime_minutes')->nullable()->after('actual_clock_out_time');
            $table->boolean('is_auto_generated')->default(false)->after('undertime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'shift_end_time',
                'actual_clock_out_time',
                'undertime_minutes',
                'is_auto_generated',
            ]);
        });
    }
};
