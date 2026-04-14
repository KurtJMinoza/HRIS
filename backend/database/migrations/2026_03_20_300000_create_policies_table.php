<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_date');
            $table->string('status', 20)->default('active'); // active, archived
            $table->integer('version')->default(1);
            $table->string('version_label', 50)->nullable();
            $table->json('priority_order_json')->nullable(); // UI/preview only
            $table->timestamps();

            $table->index(['company_id', 'status', 'effective_date']);
            $table->index(['branch_id', 'status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
