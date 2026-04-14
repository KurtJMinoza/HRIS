<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_components', 'apply_to_all')) {
                $table->boolean('apply_to_all')->default(false)->after('is_proratable');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_components') || ! Schema::hasColumn('pay_components', 'apply_to_all')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropColumn('apply_to_all');
        });
    }
};
