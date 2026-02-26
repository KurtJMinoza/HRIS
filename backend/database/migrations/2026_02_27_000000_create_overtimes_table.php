<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('overtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('schedule_end')->nullable();
            $table->time('time_out')->nullable();
            // Minutes of overtime after the 1-hour grace period.
            $table->unsignedInteger('computed_minutes')->default(0);
            // Convenience decimal representation of overtime in hours.
            $table->decimal('computed_hours', 8, 2)->default(0);
            $table->string('ot_type', 50)->default('regular');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('remarks')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Only one overtime record per employee per day.
            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtimes');
    }
};

