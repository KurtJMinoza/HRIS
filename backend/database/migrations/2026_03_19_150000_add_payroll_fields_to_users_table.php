<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'daily_rate')) {
                $table->decimal('daily_rate', 14, 2)->nullable()->after('position');
            }
            if (! Schema::hasColumn('users', 'monthly_rate')) {
                $table->decimal('monthly_rate', 14, 2)->nullable()->after('daily_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(['daily_rate', 'monthly_rate'], fn (string $c) => Schema::hasColumn('users', $c));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
