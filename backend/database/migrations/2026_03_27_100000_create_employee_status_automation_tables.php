<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add employment_status to users table
        if (! Schema::hasColumn('users', 'employment_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('employment_status')->default('probationary')->after('employment_type');
                $table->index('employment_status');
            });
        }

        // Create employee_status_histories table
        Schema::create('employee_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->date('effective_date');
            $table->string('trigger_type'); // system_automation, head_recommendation, hr_approval, manual_admin
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'effective_date']);
            $table->index('trigger_type');
        });

        // Create regularization_recommendations table (skipped if created by 2026_03_26_120000_create_regularization_recommendations_if_missing)
        if (! Schema::hasTable('regularization_recommendations')) {
            Schema::create('regularization_recommendations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recommended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('recommendation_type')->default('probation_to_regular');
                $table->text('recommendation_notes')->nullable();
                $table->string('status')->default('pending'); // pending, approved, rejected
                $table->foreignId('hr_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('hr_reviewed_at')->nullable();
                $table->text('hr_notes')->nullable();
                $table->dateTime('recommended_at');
                $table->date('effective_date')->nullable();
                $table->boolean('processed')->default(false);
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index('status');
                $table->index('processed');
                $table->index('recommendation_type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('regularization_recommendations');
        Schema::dropIfExists('employee_status_histories');

        if (Schema::hasColumn('users', 'employment_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['employment_status']);
                $table->dropColumn('employment_status');
            });
        }
    }
};
