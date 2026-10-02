<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_logs', 'face_capture_path')) {
                $table->string('face_capture_path', 512)->nullable()->after('authentication_method');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_logs', 'face_capture_path')) {
                $table->dropColumn('face_capture_path');
            }
        });
    }
};
