<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('certification_name', 180);
            $table->string('issuing_organization', 180);
            $table->date('issue_date');
            $table->date('expiration_date')->nullable();
            $table->string('credential_id', 120)->nullable();
            $table->string('credential_url', 500)->nullable();
            $table->string('certificate_path', 500)->nullable();
            $table->string('certificate_mime', 120)->nullable();
            $table->unsignedBigInteger('certificate_size')->default(0);
            $table->string('verification_status', 20)->default('pending'); // pending|verified|rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_certifications');
    }
};
