<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->dropForeign(['working_schedule_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE schedule_requests MODIFY working_schedule_id BIGINT UNSIGNED NULL');
        }

        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->foreign('working_schedule_id')->references('id')->on('working_schedules')->nullOnDelete();
        });

        Schema::table('schedule_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule_requests', 'request_kind')) {
                $table->string('request_kind', 16)->default('template')->after('user_id');
            }
            if (! Schema::hasColumn('schedule_requests', 'custom_schedule_payload')) {
                $table->json('custom_schedule_payload')->nullable()->after('working_schedule_id');
            }
        });

        DB::table('schedule_requests')->whereNull('request_kind')->update(['request_kind' => 'template']);
    }

    public function down(): void
    {
        Schema::table('schedule_requests', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_requests', 'custom_schedule_payload')) {
                $table->dropColumn('custom_schedule_payload');
            }
            if (Schema::hasColumn('schedule_requests', 'request_kind')) {
                $table->dropColumn('request_kind');
            }
        });

        DB::table('schedule_requests')->whereNull('working_schedule_id')->delete();

        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->dropForeign(['working_schedule_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE schedule_requests MODIFY working_schedule_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->foreign('working_schedule_id')->references('id')->on('working_schedules')->cascadeOnDelete();
        });
    }
};
