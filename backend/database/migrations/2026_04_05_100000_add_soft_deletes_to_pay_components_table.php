<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_components', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (Schema::hasColumn('pay_components', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
