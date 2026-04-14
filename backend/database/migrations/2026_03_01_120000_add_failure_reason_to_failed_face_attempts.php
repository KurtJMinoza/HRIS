<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failed_face_attempts', function (Blueprint $table) {
            $table->string('failure_reason', 80)->nullable()->after('is_spoof');
        });
    }

    public function down(): void
    {
        Schema::table('failed_face_attempts', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });
    }
};
