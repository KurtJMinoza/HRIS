<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pay_components') && ! Schema::hasColumn('pay_components', 'default_term_months')) {
            Schema::table('pay_components', function (Blueprint $table) {
                if (Schema::hasColumn('pay_components', 'is_amortized')) {
                    $table->unsignedSmallInteger('default_term_months')->nullable()->after('is_amortized');
                } else {
                    $table->unsignedSmallInteger('default_term_months')->nullable();
                }
            });
        }

        if (Schema::hasTable('pay_loan_requests')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('pay_loan_requests', 'approved_by')) {
                    $column = $table->foreignId('approved_by')->nullable();
                    if (Schema::hasColumn('pay_loan_requests', 'reviewed_by')) {
                        $column->after('reviewed_by');
                    }
                    $column->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('pay_loan_requests', 'approved_at')) {
                    if (Schema::hasColumn('pay_loan_requests', 'approved_by')) {
                        $table->timestamp('approved_at')->nullable()->after('approved_by');
                    } else {
                        $table->timestamp('approved_at')->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('pay_loan_requests')) {
            DB::statement(
                'UPDATE pay_loan_requests SET approved_by = second_approver_id, approved_at = second_approved_at WHERE status = ? AND approved_by IS NULL AND second_approver_id IS NOT NULL',
                ['approved']
            );
        }
    }

    public function down(): void
    {
        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });

        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropColumn('default_term_months');
        });
    }
};
