<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmailLogoController extends Controller
{
    public function show(): BinaryFileResponse
    {
        foreach ([
            public_path('logo/AGCTek.png'),
            public_path('logo/AGC_DARK.png'),
        ] as $path) {
            if (is_readable($path)) {
                return response()->file($path, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=604800, immutable',
                ]);
            }
        }

        abort(404);
    }
}
