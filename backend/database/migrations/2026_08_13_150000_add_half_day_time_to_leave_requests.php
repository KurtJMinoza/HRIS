<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'half_day_time')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->string('half_day_time', 8)->nullable()->after('half_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_requests') && Schema::hasColumn('leave_requests', 'half_day_time')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('half_day_time');
            });
        }
    }
};
