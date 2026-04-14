<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->text('filer_signature')->nullable()->after('filed_by');
            $table->timestamp('filer_signed_at')->nullable()->after('filer_signature');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn(['filer_signature', 'filer_signed_at']);
        });
    }
};
