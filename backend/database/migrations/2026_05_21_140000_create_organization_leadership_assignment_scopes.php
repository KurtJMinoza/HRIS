<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_leadership_assignment_scopes')) {
            return;
        }

        Schema::create('organization_leadership_assignment_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leadership_assignment_id')
                ->constrained('organization_position_assignments')
                ->cascadeOnDelete();
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('request_type', 40)->default('all');
            $table->string('requester_level', 40)->default('department_head');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['leadership_assignment_id', 'scope_type'], 'ol_assignment_scopes_assignment_idx');
            $table->index(['scope_type', 'scope_id'], 'ol_assignment_scopes_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_leadership_assignment_scopes');
    }
};
