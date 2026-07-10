<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            if (! Schema::hasColumn('evaluations', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('evaluator_id')->constrained('branches')->nullOnDelete();
            }
            if (! Schema::hasColumn('evaluations', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('branch_id')->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('evaluations', 'overall_score')) {
                $table->decimal('overall_score', 5, 2)->nullable()->after('department_id');
            }
            if (! Schema::hasColumn('evaluations', 'remarks')) {
                $table->text('remarks')->nullable()->after('scores');
            }
            if (! Schema::hasColumn('evaluations', 'evaluated_at')) {
                $table->timestamp('evaluated_at')->nullable()->after('submitted_at');
            }
        });

        // overall_rating holds a text label (e.g. "Outstanding"), not a numeric value.
        if (Schema::hasColumn('evaluations', 'overall_rating')) {
            DB::statement('ALTER TABLE evaluations MODIFY overall_rating VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            foreach (['branch_id', 'department_id'] as $fk) {
                if (Schema::hasColumn('evaluations', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['overall_score', 'remarks', 'evaluated_at'] as $col) {
                if (Schema::hasColumn('evaluations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
