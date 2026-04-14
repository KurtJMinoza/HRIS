<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('from_branch_id')->nullable();
            $table->unsignedBigInteger('to_branch_id');
            $table->unsignedBigInteger('from_company_id')->nullable();
            $table->unsignedBigInteger('to_company_id');
            $table->date('transfer_date');
            $table->text('reason')->nullable();
            $table->boolean('branch_manager_removed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_transfer_logs');
    }
};
