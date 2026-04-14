<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin() || ! $user->is_super_admin) {
            return response()->json(['message' => 'Forbidden. Super administrator access required.'], 403);
        }

        return $next($request);
    }
}
