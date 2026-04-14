<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('pending_working_schedule_id')
                ->nullable()
                ->after('working_schedule_id')
                ->constrained('working_schedules')
                ->nullOnDelete();
            $table->date('pending_schedule_effective_from')->nullable()->after('pending_working_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['pending_working_schedule_id']);
            $table->dropColumn(['pending_working_schedule_id', 'pending_schedule_effective_from']);
        });
    }
};
