<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        try {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                $table->dropForeign(['pay_component_id']);
            });
        } catch (\Throwable) {
            //
        }

        Schema::table('employee_compensation_components', function (Blueprint $table) {
            $table->foreign('pay_component_id')
                ->references('id')
                ->on('pay_components')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        try {
            Schema::table('employee_compensation_components', function (Blueprint $table) {
                $table->dropForeign(['pay_component_id']);
            });
        } catch (\Throwable) {
            //
        }

        Schema::table('employee_compensation_components', function (Blueprint $table) {
            $table->foreign('pay_component_id')
                ->references('id')
                ->on('pay_components')
                ->nullOnDelete();
        });
    }
};
