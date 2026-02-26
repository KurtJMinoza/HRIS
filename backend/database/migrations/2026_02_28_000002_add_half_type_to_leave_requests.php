<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // half_type: 'am' (work morning, leave afternoon) or 'pm' (leave morning, work afternoon)
            $table->string('half_type', 10)->nullable()->after('undertime_time');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'half_type')) {
                $table->dropColumn('half_type');
            }
        });
    }
};

