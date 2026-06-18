<?php

use App\Support\LegacyEncryptedString;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const GOVERNMENT_ID_FIELDS = [
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin_number',
    ];

    /**
     * One-time cleanup: legacy security tooling encrypted government IDs and face photos at rest.
     * HRIS stores these as plaintext; decrypt any rows that were affected.
     */
    public function up(): void
    {
        if (Schema::hasTable('employee_government_ids')) {
            DB::table('employee_government_ids')
                ->orderBy('id')
                ->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach (self::GOVERNMENT_ID_FIELDS as $field) {
                            $raw = $row->{$field} ?? null;
                            if (! is_string($raw) || $raw === '' || ! LegacyEncryptedString::isEncryptedPayload($raw)) {
                                continue;
                            }

                            $plain = LegacyEncryptedString::normalize($raw);
                            if ($plain !== null && $plain !== $raw) {
                                $changes[$field] = $plain;
                            }
                        }

                        if ($changes !== []) {
                            DB::table('employee_government_ids')->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'face_image')) {
            DB::table('users')
                ->whereNotNull('face_image')
                ->orderBy('id')
                ->chunkById(50, function ($rows): void {
                    foreach ($rows as $row) {
                        $raw = $row->face_image ?? null;
                        if (! is_string($raw) || $raw === '' || ! LegacyEncryptedString::isEncryptedPayload($raw)) {
                            continue;
                        }

                        $plain = LegacyEncryptedString::normalize($raw);
                        if ($plain !== null && $plain !== $raw) {
                            DB::table('users')->where('id', $row->id)->update(['face_image' => $plain]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Irreversible plaintext migration.
    }
};
