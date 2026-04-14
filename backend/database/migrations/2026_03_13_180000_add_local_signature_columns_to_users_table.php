<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signature_image')->nullable()->after('profile_image');
            $table->timestamp('signature_signed_at')->nullable()->after('signature_image');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'signature_image',
                'signature_signed_at',
            ]);
        });
    }
};
