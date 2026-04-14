<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ESignatureService
{
    public function saveFromDataUrl(User $user, string $dataUrl): User
    {
        $dataUrl = trim($dataUrl);
        if ($dataUrl === '') {
            throw new RuntimeException('Signature is empty.');
        }

        if (! preg_match('/^data:image\/(png|jpeg);base64,(.+)$/', $dataUrl, $matches)) {
            throw new RuntimeException('Invalid signature format. Please draw your signature again.');
        }

        $mimeType = strtolower((string) $matches[1]);
        $base64 = (string) $matches[2];
        $binary = base64_decode($base64, true);
        if ($binary === false || strlen($binary) === 0) {
            throw new RuntimeException('Could not decode signature data.');
        }

        if (strlen($binary) > 2 * 1024 * 1024) {
            throw new RuntimeException('Signature image is too large.');
        }

        if ($user->signature_image) {
            Storage::disk('public')->delete($user->signature_image);
        }

        $extension = $mimeType === 'jpeg' ? 'jpg' : 'png';
        $path = sprintf('signatures/%d/%s.%s', $user->id, Str::uuid(), $extension);
        Storage::disk('public')->put($path, $binary);

        $user->signature_image = $path;
        $user->signature_signed_at = now();
        $user->save();

        return $user->fresh();
    }

    public function clear(User $user): User
    {
        if ($user->signature_image) {
            Storage::disk('public')->delete($user->signature_image);
        }

        $user->signature_image = null;
        $user->signature_signed_at = null;
        $user->save();

        return $user->fresh();
    }
}
