<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_schedules', 'breaks')) {
                $table->json('breaks')->nullable()->after('break_end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            if (Schema::hasColumn('working_schedules', 'breaks')) {
                $table->dropColumn('breaks');
            }
        });
    }
};
