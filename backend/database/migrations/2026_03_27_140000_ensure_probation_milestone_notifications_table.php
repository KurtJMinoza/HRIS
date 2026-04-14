<?php

/**
 * Recovery migration: if `probation_milestone_notifications` was missing (e.g. migrations table out of sync),
 * create it. Safe to run when the table already exists.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('probation_milestone_notifications')) {
            return;
        }

        Schema::create('probation_milestone_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('milestone', 32);
            $table->date('milestone_date');
            $table->timestamp('notified_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'milestone']);
            $table->index('milestone_date');
        });
    }

    public function down(): void
    {
        // Do not drop: may have been created by 2026_03_26_220000; avoid accidental data loss on rollback.
    }
};
