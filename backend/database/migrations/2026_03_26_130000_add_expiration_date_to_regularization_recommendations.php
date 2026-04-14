<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('regularization_recommendations')) {
            return;
        }

        Schema::table('regularization_recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('regularization_recommendations', 'expiration_date')) {
                $table->date('expiration_date')
                    ->nullable()
                    ->after('effective_date')
                    ->comment('Expiration date for contractual/project-based extensions. NULL for probationary regularizations.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('regularization_recommendations')) {
            return;
        }

        Schema::table('regularization_recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('regularization_recommendations', 'expiration_date')) {
                $table->dropColumn('expiration_date');
            }
        });
    }
};
