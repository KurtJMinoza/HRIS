<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('leave_credits')->default(7)->after('salary_effectivity_date');
            $table->unsignedSmallInteger('leave_credits_year')->nullable()->after('leave_credits');
        });

        Schema::create('leave_credit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('change_type', 32);
            $table->integer('delta');
            $table->unsignedInteger('balance_after');
            $table->text('reason')->nullable();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('leave_type_context', 50)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedInteger('leave_credits_charged')->nullable()->after('rejection_note');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('leave_credits_charged');
        });

        Schema::dropIfExists('leave_credit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['leave_credits', 'leave_credits_year']);
        });
    }
};
