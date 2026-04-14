<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // ORD, RD, RH, RHRD, SH, SHRD
            $table->string('condition', 100);      // Human-readable: Normal, Rest Day, etc.
            $table->decimal('first8_multiplier', 6, 2)->default(1.00);
            $table->decimal('ot_multiplier', 6, 2)->default(1.25);
            $table->decimal('nd_base_multiplier', 6, 2)->default(1.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rules');
    }
};
