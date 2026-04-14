<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->string('ph_ot_rule', 10)->nullable()->after('computed_hours');
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropColumn('ph_ot_rule');
        });
    }
};
