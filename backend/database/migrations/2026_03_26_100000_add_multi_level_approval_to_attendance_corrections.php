<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->string('approval_stage', 32)->nullable()->after('pending_approval');
            $table->foreignId('first_approver_id')->nullable()->after('approval_stage')->constrained('users')->nullOnDelete();
            $table->text('first_approver_signature')->nullable()->after('first_approver_id');
            $table->timestamp('first_approved_at')->nullable()->after('first_approver_signature');
            $table->foreignId('second_approver_id')->nullable()->after('first_approved_at')->constrained('users')->nullOnDelete();
            $table->text('second_approver_signature')->nullable()->after('second_approver_id');
            $table->timestamp('second_approved_at')->nullable()->after('second_approver_signature');
            $table->boolean('is_incomplete_record')->default(false)->after('second_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropForeign(['first_approver_id']);
            $table->dropForeign(['second_approver_id']);
            $table->dropColumn([
                'approval_stage',
                'first_approver_id',
                'first_approver_signature',
                'first_approved_at',
                'second_approver_id',
                'second_approver_signature',
                'second_approved_at',
                'is_incomplete_record',
            ]);
        });
    }
};
