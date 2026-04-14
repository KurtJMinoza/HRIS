<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failed_face_attempts', function (Blueprint $table) {
            $table->boolean('is_spoof')->default(false)->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('failed_face_attempts', function (Blueprint $table) {
            $table->dropColumn('is_spoof');
        });
    }
};
