<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'description')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->text('description')->nullable()->after('office_location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'description')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
