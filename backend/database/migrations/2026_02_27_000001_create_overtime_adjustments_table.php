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
        Schema::create('overtime_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_id')->constrained('overtimes')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('original_minutes');
            $table->decimal('original_hours', 8, 2);
            $table->unsignedInteger('updated_minutes');
            $table->decimal('updated_hours', 8, 2);

            $table->string('reason', 2000);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_adjustments');
    }
};

