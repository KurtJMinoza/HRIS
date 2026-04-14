<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('status');
            $table->foreignId('finalized_by_user_id')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropForeign(['finalized_by_user_id']);
            $table->dropColumn(['finalized_at', 'finalized_by_user_id']);
        });
    }
};
