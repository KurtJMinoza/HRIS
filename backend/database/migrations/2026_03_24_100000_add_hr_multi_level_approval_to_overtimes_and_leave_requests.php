<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->string('approval_stage', 32)->nullable()->after('status');
            $table->boolean('pending_approval')->default(true)->after('approval_stage');
            $table->timestamp('filed_at')->nullable()->after('pending_approval');
            $table->foreignId('filed_by')->nullable()->after('filed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('first_approver_id')->nullable()->after('filed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable()->after('first_approver_id');
            $table->foreignId('second_approver_id')->nullable()->after('first_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable()->after('second_approver_id');
            $table->timestamp('rejected_at')->nullable()->after('second_approved_at');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable()->after('rejected_by');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('approval_stage', 32)->nullable()->after('status');
            $table->boolean('pending_approval')->default(true)->after('approval_stage');
            $table->timestamp('filed_at')->nullable()->after('pending_approval');
            $table->foreignId('filed_by')->nullable()->after('filed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('first_approver_id')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable()->after('first_approver_id');
            $table->foreignId('second_approver_id')->nullable()->after('first_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable()->after('second_approver_id');
            $table->timestamp('rejected_at')->nullable()->after('second_approved_at');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_note')->nullable()->after('rejected_by');
        });

        // Backfill overtime
        DB::table('overtimes')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $updates = [
                    'filed_at' => $row->created_at ?? now(),
                    'filed_by' => $row->created_by ?? $row->user_id,
                ];
                if ($row->status === 'approved') {
                    $updates['pending_approval'] = false;
                    $updates['approval_stage'] = 'approved';
                    $updates['second_approver_id'] = $row->approved_by;
                    $updates['second_approved_at'] = $row->approved_at;
                } elseif ($row->status === 'rejected') {
                    $updates['pending_approval'] = false;
                    $updates['approval_stage'] = 'rejected';
                    $updates['rejected_at'] = $row->approved_at ?? $row->updated_at;
                    $updates['rejected_by'] = $row->approved_by;
                    $updates['rejection_note'] = $row->remarks;
                } else {
                    $updates['pending_approval'] = true;
                    $updates['approval_stage'] = 'pending_first';
                }
                DB::table('overtimes')->where('id', $row->id)->update($updates);
            }
        });

        // Backfill leave
        DB::table('leave_requests')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $updates = [
                    'filed_at' => $row->created_at ?? now(),
                    'filed_by' => $row->user_id,
                ];
                if ($row->status === 'approved') {
                    $updates['pending_approval'] = false;
                    $updates['approval_stage'] = 'approved';
                    $updates['second_approver_id'] = $row->reviewed_by;
                    $updates['second_approved_at'] = $row->reviewed_at;
                } elseif ($row->status === 'rejected') {
                    $updates['pending_approval'] = false;
                    $updates['approval_stage'] = 'rejected';
                    $updates['rejected_at'] = $row->reviewed_at;
                    $updates['rejected_by'] = $row->reviewed_by;
                    $updates['rejection_note'] = $row->notes;
                } else {
                    $updates['pending_approval'] = true;
                    $updates['approval_stage'] = 'pending_first';
                }
                DB::table('leave_requests')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropForeign(['filed_by']);
            $table->dropForeign(['first_approver_id']);
            $table->dropForeign(['second_approver_id']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'approval_stage',
                'pending_approval',
                'filed_at',
                'filed_by',
                'first_approver_id',
                'first_approved_at',
                'second_approver_id',
                'second_approved_at',
                'rejected_at',
                'rejected_by',
                'rejection_note',
            ]);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['filed_by']);
            $table->dropForeign(['first_approver_id']);
            $table->dropForeign(['second_approver_id']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'approval_stage',
                'pending_approval',
                'filed_at',
                'filed_by',
                'first_approver_id',
                'first_approved_at',
                'second_approver_id',
                'second_approved_at',
                'rejected_at',
                'rejected_by',
                'rejection_note',
            ]);
        });
    }
};
