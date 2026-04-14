<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            if (! Schema::hasColumn('holidays', 'scope')) {
                $table->string('scope', 50)->default('nationwide')->after('type');
            }
            if (! Schema::hasColumn('holidays', 'description')) {
                $table->text('description')->nullable()->after('scope');
            }
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            if (Schema::hasColumn('holidays', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('holidays', 'scope')) {
                $table->dropColumn('scope');
            }
        });
    }
};
