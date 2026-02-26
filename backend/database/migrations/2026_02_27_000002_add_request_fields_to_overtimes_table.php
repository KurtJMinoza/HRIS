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
        Schema::table('overtimes', function (Blueprint $table) {
            // Expected end time from employee request (manual OT request).
            $table->time('expected_end_time')->nullable()->after('time_out');

            // Optional reason / justification for the overtime request.
            $table->text('reason')->nullable()->after('ot_type');

            // Optional attachment (e.g. client email, task documentation).
            $table->string('attachment_path')->nullable()->after('reason');

            // Explicit approval metadata for manual requests.
            $table->foreignId('approved_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropColumn([
                'expected_end_time',
                'reason',
                'attachment_path',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};

