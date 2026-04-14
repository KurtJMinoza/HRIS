<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            $table->boolean('is_loan')->default(false)->after('is_active');
            $table->boolean('is_amortized')->default(false)->after('is_loan');
        });

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->foreignId('pay_component_id')->nullable()->after('deduction_type_id')->constrained('pay_components')->nullOnDelete();
            $table->decimal('preferred_monthly_deduction', 12, 2)->nullable()->after('installment_amount');
            $table->unsignedSmallInteger('term_months')->nullable()->after('preferred_monthly_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->dropForeign(['pay_component_id']);
            $table->dropColumn(['pay_component_id', 'preferred_monthly_deduction', 'term_months']);
        });

        Schema::table('pay_components', function (Blueprint $table) {
            $table->dropColumn(['is_loan', 'is_amortized']);
        });
    }
};
