<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            $table->foreignId('policy_id')->nullable()->after('date')->constrained()->nullOnDelete();
            $table->json('policy_snapshot')->nullable()->after('conditions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            $table->dropForeign(['policy_id']);
            $table->dropColumn(['policy_id', 'policy_snapshot']);
        });
    }
};
