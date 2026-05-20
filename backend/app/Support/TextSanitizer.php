<?php

namespace App\Support;

/**
 * Normalize text to valid UTF-8. Repairs common mojibake and removes only U+FFFD.
 * Does not strip emoji, currency symbols, or valid punctuation.
 */
final class TextSanitizer
{
    /** @var array<string, string> */
    private const MOJIBAKE_MAP = [
        "\xE2\x80\x94" => '—',
        "\xE2\x80\x93" => '–',
        "\xE2\x86\x92" => '→',
        "\xC2\xB7" => '·',
        "\xE2\x80\xA6" => '…',
        'ï¿½' => '',
        "\xEF\xBF\xBD" => '',
    ];

    public static function clean(?string $value, ?string $fallback = null): ?string
    {
        if ($value === null) {
            return $fallback;
        }

        if (! is_string($value)) {
            $value = (string) $value;
        }

        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        foreach (self::MOJIBAKE_MAP as $from => $to) {
            $value = str_replace($from, $to, $value);
        }

        // Only remove the Unicode replacement character (U+FFFD).
        $value = preg_replace('/\x{FFFD}/u', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function cleanStringFields(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_string($row[$key])) {
                $row[$key] = self::clean($row[$key]);
            }
        }

        return $row;
    }
}
