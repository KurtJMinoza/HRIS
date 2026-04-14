<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        try {
            if (DB::getDriverName() !== 'mysql') {
                return false;
            }
            $db = (string) DB::connection()->getDatabaseName();
            $rows = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1',
                [$db, $table, $constraintName, 'FOREIGN KEY']
            );

            return ! empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('regularization_requirements')) {
            return;
        }

        Schema::table('regularization_requirements', function (Blueprint $table) {
            // Track who completed the original two required actions as well.
            if (! Schema::hasColumn('regularization_requirements', 'performance_review_completed_by')) {
                $table->unsignedBigInteger('performance_review_completed_by')->nullable()->after('performance_review_completed_at');
            }
            if (! Schema::hasColumn('regularization_requirements', 'checklist_completed_by')) {
                $table->unsignedBigInteger('checklist_completed_by')->nullable()->after('checklist_completed_at');
            }

            // 2. Training / Orientation Checklist Completed
            if (! Schema::hasColumn('regularization_requirements', 'training_completed')) {
                $table->boolean('training_completed')->default(false)->after('checklist_completed_at');
            }
            if (! Schema::hasColumn('regularization_requirements', 'training_completed_at')) {
                $table->timestamp('training_completed_at')->nullable()->after('training_completed');
            }
            if (! Schema::hasColumn('regularization_requirements', 'training_completed_by')) {
                $table->unsignedBigInteger('training_completed_by')->nullable()->after('training_completed_at');
            }

            // 3. Documents Submitted (ID, clearances, etc.)
            if (! Schema::hasColumn('regularization_requirements', 'documents_submitted')) {
                $table->boolean('documents_submitted')->default(false)->after('training_completed_by');
            }
            if (! Schema::hasColumn('regularization_requirements', 'documents_submitted_at')) {
                $table->timestamp('documents_submitted_at')->nullable()->after('documents_submitted');
            }
            if (! Schema::hasColumn('regularization_requirements', 'documents_submitted_by')) {
                $table->unsignedBigInteger('documents_submitted_by')->nullable()->after('documents_submitted_at');
            }

            // 4. Manager Recommendation Received
            if (! Schema::hasColumn('regularization_requirements', 'manager_recommendation_received')) {
                $table->boolean('manager_recommendation_received')->default(false)->after('documents_submitted_by');
            }
            if (! Schema::hasColumn('regularization_requirements', 'manager_recommendation_received_at')) {
                $table->timestamp('manager_recommendation_received_at')->nullable()->after('manager_recommendation_received');
            }
            if (! Schema::hasColumn('regularization_requirements', 'manager_recommendation_received_by')) {
                $table->unsignedBigInteger('manager_recommendation_received_by')->nullable()->after('manager_recommendation_received_at');
            }
        });

        // Add FKs with short names (MySQL identifier limit is 64 chars).
        Schema::table('regularization_requirements', function (Blueprint $table) {
            if (Schema::hasColumn('regularization_requirements', 'performance_review_completed_by')
                && ! $this->foreignKeyExists('regularization_requirements', 'rr_pr_cb_fk')
            ) {
                $table->foreign('performance_review_completed_by', 'rr_pr_cb_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (Schema::hasColumn('regularization_requirements', 'checklist_completed_by')
                && ! $this->foreignKeyExists('regularization_requirements', 'rr_cl_cb_fk')
            ) {
                $table->foreign('checklist_completed_by', 'rr_cl_cb_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (Schema::hasColumn('regularization_requirements', 'training_completed_by')
                && ! $this->foreignKeyExists('regularization_requirements', 'rr_tr_cb_fk')
            ) {
                $table->foreign('training_completed_by', 'rr_tr_cb_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (Schema::hasColumn('regularization_requirements', 'documents_submitted_by')
                && ! $this->foreignKeyExists('regularization_requirements', 'rr_doc_cb_fk')
            ) {
                $table->foreign('documents_submitted_by', 'rr_doc_cb_fk')->references('id')->on('users')->nullOnDelete();
            }
            if (Schema::hasColumn('regularization_requirements', 'manager_recommendation_received_by')
                && ! $this->foreignKeyExists('regularization_requirements', 'rr_mgr_cb_fk')
            ) {
                $table->foreign('manager_recommendation_received_by', 'rr_mgr_cb_fk')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Keep lightweight + safe: do not drop columns automatically to avoid data loss on rollback.
    }
};
