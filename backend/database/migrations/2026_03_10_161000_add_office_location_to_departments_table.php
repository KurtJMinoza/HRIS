<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'office_location')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->string('office_location')->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('departments', 'office_location')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('office_location');
            });
        }
    }
};
