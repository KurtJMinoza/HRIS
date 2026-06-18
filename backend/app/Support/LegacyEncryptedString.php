<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * @deprecated Compatibility shim for stale workers/deploys. Do not use in new code.
 */
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

    public static function isEncryptedPayload(string $value): bool
    {
        return str_starts_with($value, 'eyJpdiI6');
    }

    private static function decryptLaravelPayload(string $value): ?string
    {
        try {
            return trim(Crypt::decryptString($value));
        } catch (DecryptException) {
            try {
                $decrypted = Crypt::decrypt($value);

                return is_string($decrypted) ? trim($decrypted) : null;
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
