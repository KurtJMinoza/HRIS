<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('remarks');
        });

        DB::table('schedule_requests')
            ->whereNull('effective_from')
            ->update(['effective_from' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('schedule_requests', function (Blueprint $table) {
            $table->dropColumn('effective_from');
        });
    }
};
