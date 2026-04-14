<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->timestamp('attendance_logs_synced_at')->nullable()->after('second_approved_at');
            $table->foreignId('attendance_logs_synced_by')->nullable()->after('attendance_logs_synced_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropForeign(['attendance_logs_synced_by']);
            $table->dropColumn(['attendance_logs_synced_at', 'attendance_logs_synced_by']);
        });
    }
};
