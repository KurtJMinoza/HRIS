<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('phone', 64)->nullable()->after('logo');
            $table->string('email', 255)->nullable()->after('phone');
            $table->string('tin', 64)->nullable()->after('email');
            $table->text('address')->nullable()->after('tin');
            $table->date('founded_at')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'tin', 'address', 'founded_at']);
        });
    }
};
