<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key')->unique();
            $table->string('subject');
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('email_notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('notification_key')->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('recipient_type')->default('employee');
            $table->string('custom_recipient_email')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('queue_name')->default('emails');
            $table->tinyInteger('retry_attempts')->unsigned()->default(3);
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on('email_templates')
                ->nullOnDelete();
        });

        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_email')->index();
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->string('notification_key')->index();
            $table->string('subject');
            $table->string('status')->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();

            $table->index(['notification_key', 'status', 'created_at']);
            $table->index(['recipient_user_id', 'notification_key']);
        });

        Schema::create('attendance_email_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id')->index();
            $table->date('date');
            $table->string('reminder_type');
            $table->timestamp('sent_at');

            $table->unique(['employee_id', 'date', 'reminder_type']);
        });
    }
};
