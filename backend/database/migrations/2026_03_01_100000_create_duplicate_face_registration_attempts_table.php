<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_face_registration_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attempted_for_user_id');
            $table->unsignedBigInteger('existing_user_id');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('attempted_for_user_id', 'dup_face_attempted_for_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('existing_user_id', 'dup_face_existing_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->index(['attempted_for_user_id', 'created_at'], 'dup_face_attempted_created_idx');
            $table->index(['existing_user_id', 'created_at'], 'dup_face_    existing_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_face_registration_attempts');
    }
};
