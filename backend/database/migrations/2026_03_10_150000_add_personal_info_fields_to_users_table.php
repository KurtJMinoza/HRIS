<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('first_name')->nullable()->after('name');
            });
        }
        if (! Schema::hasColumn('users', 'middle_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('middle_name')->nullable()->after('first_name');
            });
        }
        if (! Schema::hasColumn('users', 'last_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('last_name')->nullable()->after('middle_name');
            });
        }
        if (! Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('date_of_birth')->nullable()->after('last_name');
            });
        }
        if (! Schema::hasColumn('users', 'gender')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('gender')->nullable()->after('date_of_birth');
            });
        }
        if (! Schema::hasColumn('users', 'civil_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('civil_status')->nullable()->after('gender');
            });
        }
        if (! Schema::hasColumn('users', 'nationality')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('nationality')->nullable()->after('civil_status');
            });
        }
        if (! Schema::hasColumn('users', 'home_address')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('home_address')->nullable()->after('nationality');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = [
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'gender',
            'civil_status',
            'nationality',
            'home_address',
        ];
        $existing = array_values(array_filter($columns, fn (string $col) => Schema::hasColumn('users', $col)));
        if ($existing !== []) {
            Schema::table('users', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }
};
