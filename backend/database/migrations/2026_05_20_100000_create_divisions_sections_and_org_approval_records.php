<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'department_id']);
        });

        Schema::create('sections_or_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_unit_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'department_id', 'division_id'], 'sections_units_org_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('section_unit_id')->nullable()->after('division_id')->constrained('sections_or_units')->nullOnDelete();
            $table->index(['division_id', 'section_unit_id'], 'users_division_section_unit_index');
        });

        Schema::create('org_approval_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('module_type', 80);
            $table->string('approval_level', 80)->nullable();
            $table->string('approver_role', 80);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name')->nullable();
            $table->string('approval_status', 20)->default('pending')->index();
            $table->text('remarks')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedSmallInteger('sequence_order');
            $table->timestamps();

            $table->unique(['module_type', 'request_id', 'sequence_order'], 'org_approval_request_sequence_unique');
            $table->index(['module_type', 'request_id', 'approval_status'], 'org_approval_request_status_index');
            $table->index(['approver_id', 'approval_status'], 'org_approval_approver_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_approval_records');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['section_unit_id']);
            $table->dropForeign(['division_id']);
            $table->dropIndex('users_division_section_unit_index');
            $table->dropColumn(['section_unit_id', 'division_id']);
        });

        Schema::dropIfExists('sections_or_units');
        Schema::dropIfExists('divisions');
    }
};
