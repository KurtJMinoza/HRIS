<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ui_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('dismissal_key', 64);
            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['user_id', 'dismissal_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ui_dismissals');
    }
};
