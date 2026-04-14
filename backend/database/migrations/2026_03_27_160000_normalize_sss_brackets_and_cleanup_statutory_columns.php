<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statutory_contributions')) {
            Schema::table('statutory_contributions', function (Blueprint $table) {
                if (Schema::hasColumn('statutory_contributions', 'min_value')) {
                    $table->dropColumn('min_value');
                }
                if (Schema::hasColumn('statutory_contributions', 'max_value')) {
                    $table->dropColumn('max_value');
                }
            });
        }

        if (! Schema::hasTable('sss_brackets')) {
            return;
        }

        Schema::table('sss_brackets', function (Blueprint $table) {
            if (! Schema::hasColumn('sss_brackets', 'range_from')) {
                $table->decimal('range_from', 14, 2)->nullable()->after('range_label');
            }
            if (! Schema::hasColumn('sss_brackets', 'range_to')) {
                $table->decimal('range_to', 14, 2)->nullable()->after('range_from');
            }
            if (! Schema::hasColumn('sss_brackets', 'ee_share')) {
                $table->decimal('ee_share', 14, 2)->nullable()->after('msc');
            }
            if (! Schema::hasColumn('sss_brackets', 'er_share')) {
                $table->decimal('er_share', 14, 2)->nullable()->after('ee_share');
            }
            if (! Schema::hasColumn('sss_brackets', 'ec_amount')) {
                $table->decimal('ec_amount', 14, 2)->nullable()->after('er_share');
            }
            if (! Schema::hasColumn('sss_brackets', 'total')) {
                $table->decimal('total', 14, 2)->nullable()->after('ec_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sss_brackets')) {
            return;
        }

        Schema::table('sss_brackets', function (Blueprint $table) {
            foreach (['range_from', 'range_to', 'ee_share', 'er_share', 'ec_amount', 'total'] as $col) {
                if (Schema::hasColumn('sss_brackets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
