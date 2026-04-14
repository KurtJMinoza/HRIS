<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            if (! Schema::hasColumn('holidays', 'regions')) {
                $table->json('regions')->nullable()->after('description');
            }
            if (! Schema::hasColumn('holidays', 'is_recurring')) {
                $table->boolean('is_recurring')->default(false)->after('regions');
            }
            if (! Schema::hasColumn('holidays', 'status')) {
                $table->string('status', 20)->default('active')->after('is_recurring');
            }
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            if (Schema::hasColumn('holidays', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('holidays', 'is_recurring')) {
                $table->dropColumn('is_recurring');
            }
            if (Schema::hasColumn('holidays', 'regions')) {
                $table->dropColumn('regions');
            }
        });
    }
};
