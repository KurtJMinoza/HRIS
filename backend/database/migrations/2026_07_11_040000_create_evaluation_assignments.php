<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('evaluation_form_id')->constrained('evaluation_forms')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->json('reminder_days')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['end_date', 'status']);
        });

        Schema::table('evaluations', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluations', 'evaluation_assignment_id')) {
                $table->foreignId('evaluation_assignment_id')
                    ->nullable()
                    ->after('evaluation_form_id')
                    ->constrained('evaluation_assignments')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('evaluations', 'evaluator_role')) {
                $table->string('evaluator_role')->nullable()->after('evaluator_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (Schema::hasColumn('evaluations', 'evaluation_assignment_id')) {
                $table->dropConstrainedForeignId('evaluation_assignment_id');
            }
            if (Schema::hasColumn('evaluations', 'evaluator_role')) {
                $table->dropColumn('evaluator_role');
            }
        });

        Schema::dropIfExists('evaluation_assignments');
    }
};
