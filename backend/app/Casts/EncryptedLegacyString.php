<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Backward-compatible plain-text cast for stale workers/deploys that still reference this class.
 * Government IDs are stored and returned as plaintext — no encryption on write.
 */
class EncryptedLegacyString implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (! str_starts_with($string, 'eyJpdiI6')) {
            return $string;
        }

        try {
            return Crypt::decryptString($string);
        } catch (DecryptException) {
            return $string;
        }
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (str_starts_with($string, 'eyJpdiI6')) {
            try {
                return Crypt::decryptString($string);
            } catch (DecryptException) {
                return $string;
            }
        }

        return $string;
    }
}
