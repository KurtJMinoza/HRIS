<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_multipliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->string('condition_key', 10); // ORD, RD, RH, RHRD, SH, SHRD, DH, DHRD
            $table->decimal('first8_multiplier', 6, 2)->default(1.00);
            $table->decimal('ot_multiplier', 6, 2)->default(1.25);
            $table->decimal('nd_addon_multiplier', 5, 2)->default(0.10);
            $table->timestamps();

            $table->unique(['policy_id', 'condition_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_multipliers');
    }
};
