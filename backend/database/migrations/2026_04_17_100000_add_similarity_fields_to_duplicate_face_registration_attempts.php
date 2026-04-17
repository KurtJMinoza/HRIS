<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duplicate_face_registration_attempts', function (Blueprint $table) {
            $table->float('similarity_score')->nullable()->after('existing_user_id');
            $table->string('detection_method', 50)->nullable()->after('similarity_score');
        });
    }

    public function down(): void
    {
        Schema::table('duplicate_face_registration_attempts', function (Blueprint $table) {
            $table->dropColumn(['similarity_score', 'detection_method']);
        });
    }
};
