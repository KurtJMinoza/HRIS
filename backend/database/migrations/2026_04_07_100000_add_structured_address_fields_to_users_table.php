<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'full_address')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('full_address')->nullable()->after('home_address');
            });
        }
        if (! Schema::hasColumn('users', 'street_address')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('street_address')->nullable()->after('full_address');
            });
        }
        if (! Schema::hasColumn('users', 'barangay')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('barangay')->nullable()->after('street_address');
            });
        }
        if (! Schema::hasColumn('users', 'city')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('city')->nullable()->after('barangay');
            });
        }
        if (! Schema::hasColumn('users', 'province')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('province')->nullable()->after('city');
            });
        }
        if (! Schema::hasColumn('users', 'postal_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('postal_code', 32)->nullable()->after('province');
            });
        }
    }

    public function down(): void
    {
        $columns = ['full_address', 'street_address', 'barangay', 'city', 'province', 'postal_code'];
        $existing = array_values(array_filter($columns, fn (string $col) => Schema::hasColumn('users', $col)));

        if ($existing !== []) {
            Schema::table('users', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }
};
