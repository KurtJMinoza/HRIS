<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('category', 60);
            $table->string('document_name', 255);
            $table->string('version', 30)->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('status', 20)->default('pending'); // pending|active|archived|rejected
            $table->string('review_note', 800)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('file_path', 500);
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'category', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['category']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
