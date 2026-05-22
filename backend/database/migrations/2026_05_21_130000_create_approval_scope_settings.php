<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_scope_settings', function (Blueprint $table) {
            $table->id();
            $table->string('organization_type', 40);
            $table->unsignedBigInteger('organization_id');
            $table->string('requester_level', 40);
            $table->string('approver_level', 40);
            $table->string('request_type', 40)->default('all');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_type', 'organization_id', 'requester_level', 'approver_level', 'request_type'],
                'approval_scope_settings_unique',
            );
            $table->index(['organization_type', 'organization_id'], 'approval_scope_settings_org_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_scope_settings');
    }
};
