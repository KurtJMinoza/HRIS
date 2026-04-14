<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employer-level statutory remittance batches (R-3 style listings, PhilHealth RF1, Pag-IBIG MCRF, BIR alphalist support).
     * Status tracks workflow: draft → generated → filed → paid (per agency process).
     */
    public function up(): void
    {
        Schema::create('statutory_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('agency', 32); // SSS, PHILHEALTH, PAGIBIG, BIR
            $table->string('report_kind', 64); // r3, r5_listing, rf1, mcrf, bir_1601e_stub, etc.
            $table->string('status', 24)->default('pending'); // pending, generated, filed, paid, cancelled
            $table->string('file_name')->nullable();
            $table->json('payload')->nullable();
            $table->decimal('total_employee_amount', 16, 2)->nullable();
            $table->decimal('total_employer_amount', 16, 2)->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['period_year', 'period_month', 'agency']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_remittances');
    }
};
