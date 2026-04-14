<?php

namespace App\Http\Middleware;

use App\Services\HrRoleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHrPanelAccess
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $hr = $this->hrRoleResolver->resolve($user);
        if (! $hr->canAccessHrPanel()) {
            return response()->json(['message' => 'Forbidden. HR panel access required.'], 403);
        }

        return $next($request);
    }
}
