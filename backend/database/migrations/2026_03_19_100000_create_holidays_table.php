<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holidays')) {
            return;
        }
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->string('type', 50); // regular, special, company
            $table->string('scope', 50)->default('nationwide'); // nationwide, company
            $table->timestamps();

            $table->unique(['date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
