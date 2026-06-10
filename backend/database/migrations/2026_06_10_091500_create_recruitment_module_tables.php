<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('applicant_no')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->foreignId('applied_position_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('applied_position')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('source')->nullable();
            $table->string('status', 64)->default('New')->index();
            $table->date('date_applied')->nullable()->index();
            $table->foreignId('created_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recruitment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();
            $table->string('document_type', 80)->index();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 32)->default('Pending')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recruitment_interviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();
            $table->string('interview_type', 16)->index();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('interview_date')->nullable();
            $table->string('mode', 20)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('result', 32)->nullable()->index();
            $table->string('next_step')->nullable();
            $table->json('evaluation')->nullable();
            $table->timestamps();
        });

        Schema::create('recruitment_exam_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('position_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->decimal('passing_score', 8, 2)->default(0);
            $table->string('status', 32)->default('Draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recruitment_exam_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_template_id')->constrained('recruitment_exam_templates')->cascadeOnDelete();
            $table->string('question_type', 32);
            $table->text('question');
            $table->json('choices')->nullable();
            $table->text('correct_answer')->nullable();
            $table->decimal('points', 8, 2)->default(1);
            $table->timestamps();
        });

        Schema::create('recruitment_exam_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('recruitment_applicants')->cascadeOnDelete();
            $table->foreignId('exam_template_id')->constrained('recruitment_exam_templates')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('exam_link_token', 80)->unique();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('result', 32)->nullable()->index();
            $table->string('status', 32)->default('Assigned')->index();
            $table->timestamps();
        });

        Schema::create('recruitment_exam_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_assignment_id')->constrained('recruitment_exam_assignments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('recruitment_exam_questions')->cascadeOnDelete();
            $table->longText('answer')->nullable();
            $table->string('file_path')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_exam_answers');
        Schema::dropIfExists('recruitment_exam_assignments');
        Schema::dropIfExists('recruitment_exam_questions');
        Schema::dropIfExists('recruitment_exam_templates');
        Schema::dropIfExists('recruitment_interviews');
        Schema::dropIfExists('recruitment_documents');
        Schema::dropIfExists('recruitment_applicants');
    }
};
