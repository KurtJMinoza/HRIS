<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure phone_number is globally unique across users.
     */
    public function up(): void
    {
        // Add a dedicated unique index for normalized phone numbers (+639XXXXXXXXX).
        if (! Schema::hasColumn('users', 'phone_number')) {
            return;
        }

        // If there are duplicate phone numbers in existing data, null them out (keeping the first user ID)
        // so the unique index can be applied safely.
        $dupes = DB::table('users')
            ->select('phone_number', DB::raw('COUNT(*) as c'))
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->groupBy('phone_number')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupes as $row) {
            $ids = DB::table('users')
                ->where('phone_number', $row->phone_number)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $idsToNull = array_slice($ids, 1);
            if (! empty($idsToNull)) {
                DB::table('users')
                    ->whereIn('id', $idsToNull)
                    ->update(['phone_number' => null]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            // MySQL supports SHOW INDEX; SQLite (tests) does not.
            if (DB::getDriverName() === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('users')");
                $exists = false;
                foreach ($indexes as $idx) {
                    $name = is_object($idx) ? ($idx->name ?? null) : ($idx['name'] ?? null);
                    if ($name === 'users_phone_number_unique') {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $table->unique('phone_number', 'users_phone_number_unique');
                }

                return;
            }

            $existing = DB::select("SHOW INDEX FROM `users` WHERE Key_name = 'users_phone_number_unique'");
            if (empty($existing)) {
                $table->unique('phone_number', 'users_phone_number_unique');
            }
        });
    }

    /**
     * Drop the unique constraint on phone_number.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone_number')) {
                return;
            }
            // Use the explicit index name we created in up().
            $table->dropUnique('users_phone_number_unique');
        });
    }
};
