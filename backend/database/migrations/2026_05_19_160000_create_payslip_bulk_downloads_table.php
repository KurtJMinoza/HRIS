<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_bulk_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payroll_batch_run_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('processed_files')->default(0);
            $table->string('file_path', 512)->nullable();
            $table->string('file_format', 16)->default('zip');
            $table->json('selected_employee_ids')->nullable();
            $table->boolean('force_regenerate')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('payroll_batch_run_id', 'pbd_batch_run_idx');
            $table->index('requested_by_user_id', 'pbd_requested_by_idx');
            $table->index('status', 'pbd_status_idx');
            $table->index(['payroll_batch_run_id', 'status'], 'pbd_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_bulk_downloads');
    }
};
