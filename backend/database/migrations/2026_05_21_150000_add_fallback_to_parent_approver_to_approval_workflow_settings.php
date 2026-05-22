<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('approval_workflow_settings', 'fallback_to_parent_approver')) {
                $table->boolean('fallback_to_parent_approver')->default(false)->after('fallback_to_hr');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        Schema::table('approval_workflow_settings', function (Blueprint $table) {
            if (Schema::hasColumn('approval_workflow_settings', 'fallback_to_parent_approver')) {
                $table->dropColumn('fallback_to_parent_approver');
            }
        });
    }
};
