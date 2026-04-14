<?php

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
        Schema::dropIfExists('probation_milestone_notifications');
    }
};
