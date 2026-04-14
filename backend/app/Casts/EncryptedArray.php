<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts array values by JSON-encoding before encrypt and JSON-decoding after decrypt.
 * Laravel's 'encrypted' cast expects strings; this handles arrays for face_descriptor_samples.
 */
class EncryptedArray implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
            $decoded = json_decode($decrypted, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            // Backward compatibility: plain JSON from before encryption
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = is_array($value) ? json_encode($value) : (string) $value;

        return Crypt::encryptString($json);
    }
}
