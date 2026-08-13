<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_credit_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('reset_month')->default(1);
            $table->unsignedTinyInteger('reset_day')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('leave_credit_settings')->insert([
            'reset_month' => 1,
            'reset_day' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_credit_settings');
    }
};
