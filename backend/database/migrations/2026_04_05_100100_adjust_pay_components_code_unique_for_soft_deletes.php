<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components') || ! Schema::hasColumn('pay_components', 'deleted_at')) {
            return;
        }

        try {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->dropUnique(['code']);
            });
        } catch (\Throwable) {
            //
        }

        try {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->unique(['code', 'deleted_at'], 'pay_components_code_deleted_at_unique');
            });
        } catch (\Throwable) {
            //
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        try {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->dropUnique('pay_components_code_deleted_at_unique');
            });
        } catch (\Throwable) {
            //
        }

        try {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->unique('code');
            });
        } catch (\Throwable) {
            //
        }
    }
};
