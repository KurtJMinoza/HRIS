<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_applicants', function (Blueprint $table): void {
            $table->index(['status', 'date_applied', 'id'], 'recruitment_applicants_status_date_id_index');
            $table->index(['date_applied', 'id'], 'recruitment_applicants_date_id_index');
        });

        Schema::table('recruitment_documents', function (Blueprint $table): void {
            $table->index(['applicant_id', 'status'], 'recruitment_documents_applicant_status_index');
        });

        Schema::table('recruitment_interviews', function (Blueprint $table): void {
            $table->index(
                ['applicant_id', 'interview_type', 'interview_date', 'id'],
                'recruitment_interviews_applicant_type_date_id_index'
            );
        });

        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            $table->index(['applicant_id', 'id'], 'recruitment_exam_assignments_applicant_id_index');
        });

        Schema::table('recruitment_exam_answers', function (Blueprint $table): void {
            $table->index(['exam_assignment_id', 'id'], 'recruitment_exam_answers_assignment_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_exam_answers', function (Blueprint $table): void {
            $table->dropIndex('recruitment_exam_answers_assignment_id_index');
        });

        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            $table->dropIndex('recruitment_exam_assignments_applicant_id_index');
        });

        Schema::table('recruitment_interviews', function (Blueprint $table): void {
            $table->dropIndex('recruitment_interviews_applicant_type_date_id_index');
        });

        Schema::table('recruitment_documents', function (Blueprint $table): void {
            $table->dropIndex('recruitment_documents_applicant_status_index');
        });

        Schema::table('recruitment_applicants', function (Blueprint $table): void {
            $table->dropIndex('recruitment_applicants_status_date_id_index');
            $table->dropIndex('recruitment_applicants_date_id_index');
        });
    }
};
