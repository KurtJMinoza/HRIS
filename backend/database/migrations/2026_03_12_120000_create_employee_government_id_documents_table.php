<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_government_id_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('id_type', 60);
            $table->string('id_number', 120);
            $table->string('issuing_agency', 180);
            $table->date('expiry_date')->nullable();
            $table->string('document_path', 500)->nullable();
            $table->string('document_mime', 120)->nullable();
            $table->unsignedBigInteger('document_size')->default(0);
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'id_number']);
            $table->index(['user_id', 'status']);
            $table->index(['id_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_government_id_documents');
    }
};
