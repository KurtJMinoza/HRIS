<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone migration so environments that never ran the bundled employee-status
 * automation migration still get regularization_recommendations (fixes SQLSTATE 42S02).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regularization_recommendations')) {
            $this->addOptionalColumns();

            return;
        }

        Schema::create('regularization_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recommended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recommendation_type')->default('probation_to_regular');
            $table->text('recommendation_notes')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('hr_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_reviewed_at')->nullable();
            $table->text('hr_notes')->nullable();
            $table->dateTime('recommended_at');
            $table->date('effective_date')->nullable();
            $table->date('expiration_date')
                ->nullable()
                ->comment('Expiration date for contractual/project-based extensions. NULL for probationary regularizations.');
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('processed');
            $table->index('recommendation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regularization_recommendations');
    }

    private function addOptionalColumns(): void
    {
        Schema::table('regularization_recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('regularization_recommendations', 'recommendation_type')) {
                $table->string('recommendation_type')->default('probation_to_regular');
            }
            if (! Schema::hasColumn('regularization_recommendations', 'effective_date')) {
                $table->date('effective_date')->nullable();
            }
            if (! Schema::hasColumn('regularization_recommendations', 'expiration_date')) {
                $table->date('expiration_date')
                    ->nullable()
                    ->comment('Expiration date for contractual/project-based extensions. NULL for probationary regularizations.');
            }
        });
    }
};
