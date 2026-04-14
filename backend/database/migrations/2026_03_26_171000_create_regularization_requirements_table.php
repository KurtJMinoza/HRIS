<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regularization_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('performance_review_completed')->default(false);
            $table->text('performance_review_notes')->nullable();
            $table->timestamp('performance_review_completed_at')->nullable();
            $table->boolean('checklist_completed')->default(false);
            $table->text('checklist_notes')->nullable();
            $table->timestamp('checklist_completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regularization_requirements');
    }
};
