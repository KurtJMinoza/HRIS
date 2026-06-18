<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class LegacyEncryptedString
{
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! self::isEncryptedPayload($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public static function isEncryptedPayload(string $value): bool
    {
        if (! str_starts_with($value, 'eyJpdiI6')) {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
