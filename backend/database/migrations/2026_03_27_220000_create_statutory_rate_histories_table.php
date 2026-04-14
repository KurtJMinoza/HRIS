<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_rate_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statutory_contribution_id')->nullable()->constrained('statutory_contributions')->nullOnDelete();
            $table->string('code', 32);
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->string('action', 32); // created | updated
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->json('changed_fields')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['code', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['effective_from', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rate_histories');
    }
};
