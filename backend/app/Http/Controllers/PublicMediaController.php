<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class PublicMediaController extends Controller
{
    /**
     * Serve files from the public disk without requiring storage:link.
     */
    public function show(string $path)
    {
        $normalized = trim($path, '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($normalized)) {
            abort(404);
        }

        $absolutePath = $disk->path($normalized);
        $mimeType = $disk->mimeType($normalized) ?: 'application/octet-stream';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
