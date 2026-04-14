<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attendance_corrections', 'issue_kind')) {
            return;
        }
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->string('issue_kind', 32)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('attendance_corrections', 'issue_kind')) {
            return;
        }
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropColumn('issue_kind');
        });
    }
};
