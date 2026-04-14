<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_correction_audits', function (Blueprint $table) {
            $table->string('action', 32)->nullable()->after('reason');
            $table->text('e_signature')->nullable()->after('action');
            $table->string('approver_role', 64)->nullable()->after('e_signature');
        });

        if (Schema::hasColumn('attendance_corrections', 'approval_stage')) {
            DB::table('attendance_corrections')
                ->whereNull('approval_stage')
                ->where('pending_approval', true)
                ->update(['approval_stage' => 'pending_first']);

            DB::table('attendance_corrections')
                ->whereNull('approval_stage')
                ->where('approved', true)
                ->update(['approval_stage' => 'approved']);
        }
    }

    public function down(): void
    {
        Schema::table('attendance_correction_audits', function (Blueprint $table) {
            $table->dropColumn(['action', 'e_signature', 'approver_role']);
        });
    }
};
