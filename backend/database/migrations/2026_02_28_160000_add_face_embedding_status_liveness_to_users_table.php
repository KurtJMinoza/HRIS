<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->longText('face_embedding')->nullable()->after('face_registered_at');
            $table->string('face_status', 20)->default('not_registered')->after('face_embedding');
            $table->string('face_liveness_type', 20)->nullable()->after('face_status');
        });

        // Migrate existing face_descriptor data to face_embedding
        DB::table('users')
            ->whereNotNull('face_descriptor')
            ->where('face_descriptor', '!=', '')
            ->update([
                'face_embedding' => DB::raw('face_descriptor'),
                'face_status' => 'registered',
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['face_embedding', 'face_status', 'face_liveness_type']);
        });
    }
};
