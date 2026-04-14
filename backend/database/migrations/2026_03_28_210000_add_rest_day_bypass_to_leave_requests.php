<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('rest_day_bypass')->default(false)->after('leave_credits_charged');
            $table->text('rest_day_bypass_reason')->nullable()->after('rest_day_bypass');
            $table->foreignId('rest_day_bypass_by')->nullable()->after('rest_day_bypass_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rest_day_bypass_at')->nullable()->after('rest_day_bypass_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['rest_day_bypass_by']);
            $table->dropColumn([
                'rest_day_bypass',
                'rest_day_bypass_reason',
                'rest_day_bypass_by',
                'rest_day_bypass_at',
            ]);
        });
    }
};
