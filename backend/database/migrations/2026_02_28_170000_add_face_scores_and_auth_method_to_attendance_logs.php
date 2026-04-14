<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('similarity_score', 6, 4)->nullable()->after('longitude');
            $table->decimal('liveness_score', 6, 4)->nullable()->after('similarity_score');
            $table->string('authentication_method', 50)->nullable()->after('liveness_score');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['similarity_score', 'liveness_score', 'authentication_method']);
        });
    }
};
