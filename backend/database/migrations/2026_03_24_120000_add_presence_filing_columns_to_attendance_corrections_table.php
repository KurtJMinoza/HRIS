<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->boolean('pending_approval')->default(false)->after('approved_at');
            $table->string('reason_code', 64)->nullable()->after('pending_approval');
            $table->text('manual_presence_reason')->nullable()->after('reason_code');
            $table->timestamp('filed_at')->nullable()->after('manual_presence_reason');
            $table->foreignId('filed_by')->nullable()->after('filed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('filed_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropForeign(['filed_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'pending_approval',
                'reason_code',
                'manual_presence_reason',
                'filed_at',
                'filed_by',
                'rejected_at',
                'rejected_by',
                'rejection_note',
            ]);
        });
    }
};
