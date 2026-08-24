<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 32)->unique();

            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();

            /** underpayment | overpayment | payroll_adjustment */
            $table->string('direction', 32)->default('underpayment')->index();

            /** attendance | overtime | holiday | leave | schedule | payroll_computation | other */
            $table->string('category', 32)->index();

            /** RefundRequests::REASON_* slugs (missing_time_out, missing_overtime, ...) */
            $table->string('reason', 64)->index();

            $table->date('affected_date');
            $table->date('affected_date_to')->nullable();

            /**
             * Payroll window the original (possibly finalized) run covered.
             * Used for finalization-lock detection and "next eligible payroll" targeting.
             */
            $table->date('cutoff_start_date')->nullable();
            $table->date('cutoff_end_date')->nullable();
            $table->unsignedBigInteger('original_payroll_batch_run_id')->nullable()->index();

            /** Corrected inputs supplied by the admin (time_in, time_out, ot context, notes...). */
            $table->json('correction_payload')->nullable();

            /** Immutable engine snapshot: as_paid day result, corrected day result, component diffs. */
            $table->json('calculation')->nullable();

            $table->decimal('original_amount', 12, 2)->default(0);
            $table->decimal('corrected_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);

            $table->text('reason_notes')->nullable();

            /** draft|submitted|payroll_review|approved|rejected|queued_for_payroll|processed|cancelled|voided */
            $table->string('status', 32)->default('draft')->index();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedBigInteger('queued_by')->nullable();
            /** Next eligible payroll batch that absorbed this adjustment. */
            $table->unsignedBigInteger('processed_batch_run_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'affected_date']);
        });

        Schema::create('refund_request_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('remarks')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('refund_request_id')
                ->references('id')->on('refund_requests')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_request_audits');
        Schema::dropIfExists('refund_requests');
    }
};
