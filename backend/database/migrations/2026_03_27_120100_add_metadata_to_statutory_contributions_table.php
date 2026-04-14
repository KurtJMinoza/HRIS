<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('statutory_contributions')) {
            return;
        }

        Schema::table('statutory_contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('statutory_contributions', 'metadata')) {
                $table->json('metadata')->nullable()->after('brackets');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('statutory_contributions')) {
            return;
        }

        Schema::table('statutory_contributions', function (Blueprint $table) {
            if (Schema::hasColumn('statutory_contributions', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
