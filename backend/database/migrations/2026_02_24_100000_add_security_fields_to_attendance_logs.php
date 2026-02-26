<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('verified_at');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->decimal('latitude', 10, 8)->nullable()->after('user_agent');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'latitude', 'longitude']);
        });
    }
};
