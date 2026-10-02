<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class AttendanceFaceCaptureStorage
{
    private const DISK = 'local';

    private const ROOT = 'attendance-face-captures';

    public static function store(int $userId, int $attendanceLogId, string $imageBase64): ?string
    {
        $binary = self::decodeToBinary($imageBase64);
        if ($binary === null || $binary === '') {
            return null;
        }

        $path = self::ROOT.'/'.$userId.'/'.$attendanceLogId.'.jpg';
        if (! Storage::disk(self::DISK)->put($path, $binary)) {
            return null;
        }

        return $path;
    }

    public static function toDataUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $binary = Storage::disk(self::DISK)->get($path);
        if ($binary === '' || $binary === false) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($binary);
    }

    private static function decodeToBinary(string $imageBase64): ?string
    {
        $trimmed = trim($imageBase64);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'data:')) {
            $comma = strpos($trimmed, ',');
            if ($comma === false) {
                return null;
            }
            $trimmed = substr($trimmed, $comma + 1);
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
        if ($compact === '' || ! preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
            return null;
        }

        $decoded = base64_decode($compact, true);

        return $decoded === false ? null : $decoded;
    }
}
