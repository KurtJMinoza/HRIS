<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'signature_image')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('signature_image')->nullable()->after('profile_image');
            });
        }
        if (! Schema::hasColumn('users', 'signature_signed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('signature_signed_at')->nullable()->after('signature_image');
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['signature_image', 'signature_signed_at'],
            fn (string $col) => Schema::hasColumn('users', $col),
        ));
        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
