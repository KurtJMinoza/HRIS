<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
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
     * Repair government IDs and face photos left as Laravel encrypted blobs or PHP serialized strings.
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
                            if (! is_string($raw) || $raw === '') {
                                continue;
                            }

                            $plain = self::repairPlaintextValue($raw);
                            if ($plain !== $raw) {
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
                        if (! is_string($raw) || $raw === '') {
                            continue;
                        }

                        $plain = self::repairPlaintextValue($raw);
                        if ($plain !== $raw) {
                            DB::table('users')->where('id', $row->id)->update(['face_image' => $plain]);
                        }
                    }
                });
        }
    }

    private static function repairPlaintextValue(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        for ($i = 0; $i < 5; $i++) {
            $before = $value;

            if (str_starts_with($value, 'eyJpdiI6')) {
                $value = self::decryptLaravelPayload($value) ?? $value;
            }

            if (preg_match('/^s:\d+:"/s', $value)) {
                $unserialized = @unserialize($value, ['allowed_classes' => false]);
                if (is_string($unserialized)) {
                    $value = trim($unserialized);
                }
            }

            if ($value === $before) {
                break;
            }
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function decryptLaravelPayload(string $value): ?string
    {
        try {
            return trim(Crypt::decryptString($value));
        } catch (\Throwable) {
            try {
                $decrypted = Crypt::decrypt($value);

                return is_string($decrypted) ? trim($decrypted) : null;
            } catch (\Throwable) {
                return null;
            }
        }
    }

    public function down(): void
    {
        // Irreversible plaintext repair.
    }
};
