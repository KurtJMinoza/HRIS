<?php

namespace App\Casts;

use App\Support\LegacyEncryptedString;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Decrypts legacy Laravel-encrypted values on read; stores plaintext on write.
 */
class EncryptedLegacyString implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        return LegacyEncryptedString::normalize($value);
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

        if (LegacyEncryptedString::isEncryptedPayload($string)) {
            return LegacyEncryptedString::normalize($string);
        }

        return $string;
    }
}
