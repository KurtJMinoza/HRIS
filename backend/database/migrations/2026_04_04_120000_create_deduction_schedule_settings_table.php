<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deduction_schedule_settings', function (Blueprint $table) {
            $table->id();
            /** Null = global default for all companies; otherwise scoped to company. */
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            /**
             * government:SSS | government:PHILHEALTH | government:PAGIBIG | government:WITHHOLDING | pay_component:{id}
             */
            $table->string('deduction_key', 64)->index();
            /** 15th | 30th | both (50/50 split across the two semi-monthly runs) */
            $table->string('schedule_type', 16);
            $table->timestamps();

            $table->index(['company_id', 'deduction_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_schedule_settings');
    }
};
