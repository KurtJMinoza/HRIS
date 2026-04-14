<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_correction_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_correction_id')->constrained('attendance_corrections')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('level')->nullable(); // 1 = line approver, 2 = HR final
            $table->string('status', 32); // approved|rejected|remark|submitted
            $table->text('notes')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['attendance_correction_id', 'level'], 'aca_corr_level_idx');
            $table->index(['attendance_correction_id', 'status'], 'aca_corr_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_approvals');
    }
};
