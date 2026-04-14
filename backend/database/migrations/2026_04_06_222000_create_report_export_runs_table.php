<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_export_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->json('filters')->nullable();
            $table->string('status', 32)->default('queued'); // queued, processing, completed, failed
            $table->string('file_path')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status'], 'rer_type_status_idx');
            $table->index(['requested_by_user_id', 'created_at'], 'rer_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_runs');
    }
};
