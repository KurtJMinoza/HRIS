<?php

namespace App\Support;

final class FaceImageDataUrl
{
    public static function toDataUrl(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $img = trim($stored);
        if ($img === '') {
            return null;
        }

        if (str_starts_with($img, 'data:')) {
            return $img;
        }

        if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $img)) {
            return 'data:image/jpeg;base64,'.preg_replace('/\s+/', '', $img);
        }

        return null;
    }
}
