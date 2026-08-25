<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_auto_approve_overrides', function (Blueprint $table) {
            $table->decimal('max_hours_per_day', 5, 2)->default(2.00)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_auto_approve_overrides', function (Blueprint $table) {
            $table->dropColumn('max_hours_per_day');
        });
    }
};
