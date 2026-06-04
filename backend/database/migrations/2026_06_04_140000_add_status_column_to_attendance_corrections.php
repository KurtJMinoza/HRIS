<?php

use App\Enums\HrRole;
use App\Models\OrgApprovalRecord;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_corrections', 'status')) {
                $table->string('status', 32)->default('pending')->after('pending_approval');
                $table->index(['status', 'rejected_at'], 'correction_status_rejected_idx');
            }
            if (! Schema::hasColumn('attendance_corrections', 'final_approved_by')) {
                $table->foreignId('final_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
        });

        $module = OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION;
        $hrRole = HrRole::AdminHr->value;
        $approvedStage = AttendanceCorrectionApprovalService::STAGE_APPROVED;

        DB::table('attendance_corrections')->orderBy('id')->chunkById(500, function ($rows) use ($module, $hrRole, $approvedStage): void {
            foreach ($rows as $row) {
                $status = 'pending';
                if ($row->rejected_at !== null) {
                    $status = 'rejected';
                } elseif (
                    (bool) $row->approved
                    || $row->approval_stage === $approvedStage
                    || $row->second_approved_at !== null
                    || DB::table('org_approval_records')
                        ->where('module_type', $module)
                        ->where('request_id', $row->id)
                        ->where('approver_role', $hrRole)
                        ->where('approval_status', OrgApprovalRecord::STATUS_APPROVED)
                        ->exists()
                ) {
                    $status = 'approved';
                } elseif (! (bool) $row->pending_approval && ! (bool) $row->approved && $row->rejected_at === null) {
                    $status = 'cancelled';
                }

                DB::table('attendance_corrections')->where('id', $row->id)->update([
                    'status' => $status,
                    'final_approved_by' => $status === 'approved'
                        ? ($row->second_approver_id ?? $row->approved_by)
                        : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_corrections', 'final_approved_by')) {
                $table->dropForeign(['final_approved_by']);
                $table->dropColumn('final_approved_by');
            }
            if (Schema::hasColumn('attendance_corrections', 'status')) {
                $table->dropIndex('correction_status_rejected_idx');
                $table->dropColumn('status');
            }
        });
    }
};
