<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_corrections')) {
            return;
        }

        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_corrections', 'document_paths')) {
                $table->json('document_paths')->nullable()->after('manual_presence_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_corrections')) {
            return;
        }

        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_corrections', 'document_paths')) {
                $table->dropColumn('document_paths');
            }
        });
    }
};
