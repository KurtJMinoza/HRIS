<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('approval_workflow_settings')) {
            DB::table('approval_workflow_settings')->where('request_type', 'evaluation')->delete();
        }

        if (Schema::hasTable('evaluations')) {
            DB::table('evaluations')
                ->whereIn('status', ['submitted', 'under_review'])
                ->update(['status' => 'completed']);

            Schema::table('evaluations', function (Blueprint $table) {
                if (Schema::hasColumn('evaluations', 'rejection_note')) {
                    $table->dropColumn('rejection_note');
                }
                if (Schema::hasColumn('evaluations', 'rejected_by')) {
                    $table->dropConstrainedForeignId('rejected_by');
                }
                if (Schema::hasColumn('evaluations', 'rejected_at')) {
                    $table->dropColumn('rejected_at');
                }
                if (Schema::hasColumn('evaluations', 'pending_approval')) {
                    $table->dropColumn('pending_approval');
                }
            });
        }

        if (Schema::hasTable('evaluation_workflow_settings')) {
            Schema::drop('evaluation_workflow_settings');
        }
    }

    public function down(): void
    {
        // Evaluation approval workflow removed intentionally.
    }
};
