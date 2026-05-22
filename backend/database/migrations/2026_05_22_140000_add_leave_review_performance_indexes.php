<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_approval_audits')) {
            Schema::table('leave_approval_audits', function (Blueprint $table): void {
                $table->index(['leave_request_id', 'created_at'], 'leave_audit_request_created_idx');
                $table->index(['actor_id', 'action'], 'leave_audit_actor_action_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_approval_audits')) {
            return;
        }

        Schema::table('leave_approval_audits', function (Blueprint $table): void {
            $table->dropIndex('leave_audit_request_created_idx');
            $table->dropIndex('leave_audit_actor_action_idx');
        });
    }
};
