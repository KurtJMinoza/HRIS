<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('notifiable_type')->nullable()->index();
            $table->unsignedBigInteger('notifiable_id')->nullable()->index();
            $table->string('type')->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('module', 80)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('entity_type')->nullable();
            $table->string('action_url')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->index();
            $table->string('recipient_role')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['recipient_user_id', 'read_at']);
            $table->index(['recipient_user_id', 'module', 'read_at']);
            $table->index(['module', 'entity_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_notifications');
    }
};
