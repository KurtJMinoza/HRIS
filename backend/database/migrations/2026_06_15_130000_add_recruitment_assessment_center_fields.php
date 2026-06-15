<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_exam_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('recruitment_exam_templates', 'category')) {
                $table->string('category', 80)->nullable()->after('title')->index();
            }
            if (! Schema::hasColumn('recruitment_exam_templates', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('category')->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('recruitment_exam_templates', 'instructions')) {
                $table->text('instructions')->nullable()->after('passing_score');
            }
            if (! Schema::hasColumn('recruitment_exam_templates', 'settings')) {
                $table->json('settings')->nullable()->after('instructions');
            }
        });

        Schema::table('recruitment_exam_questions', function (Blueprint $table): void {
            if (! Schema::hasColumn('recruitment_exam_questions', 'difficulty')) {
                $table->string('difficulty', 20)->default('Medium')->after('points')->index();
            }
            if (! Schema::hasColumn('recruitment_exam_questions', 'category')) {
                $table->string('category', 80)->default('Custom')->after('difficulty')->index();
            }
        });

        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('recruitment_exam_assignments', 'expires_at')) {
                $table->dateTime('expires_at')->nullable()->after('exam_link_token')->index();
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'attempt_number')) {
                $table->unsignedInteger('attempt_number')->default(1)->after('expires_at');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'max_attempts')) {
                $table->unsignedInteger('max_attempts')->default(1)->after('attempt_number');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'one_time_access')) {
                $table->boolean('one_time_access')->default(true)->after('max_attempts');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'password')) {
                $table->string('password')->nullable()->after('one_time_access');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'require_login')) {
                $table->boolean('require_login')->default(false)->after('password');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'recruiter_notes')) {
                $table->text('recruiter_notes')->nullable()->after('status');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'remarks')) {
                $table->text('remarks')->nullable()->after('recruiter_notes');
            }
            if (! Schema::hasColumn('recruitment_exam_assignments', 'recommendation')) {
                $table->string('recommendation', 40)->nullable()->after('remarks')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            foreach (['recommendation', 'remarks', 'recruiter_notes', 'require_login', 'password', 'one_time_access', 'max_attempts', 'attempt_number', 'expires_at'] as $column) {
                if (Schema::hasColumn('recruitment_exam_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('recruitment_exam_questions', function (Blueprint $table): void {
            foreach (['category', 'difficulty'] as $column) {
                if (Schema::hasColumn('recruitment_exam_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('recruitment_exam_templates', function (Blueprint $table): void {
            foreach (['settings', 'instructions', 'department_id', 'category'] as $column) {
                if (Schema::hasColumn('recruitment_exam_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
