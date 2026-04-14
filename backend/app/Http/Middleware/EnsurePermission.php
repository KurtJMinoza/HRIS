<?php

namespace App\Http\Middleware;

use App\Enums\HrRole;
use App\Services\HrRoleResolver;
use App\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require at least one permission from a pipe-separated list (OR).
 * Example: middleware('permission:employees.view|employees.edit')
 */
class EnsurePermission
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionList): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $slugs = array_filter(array_map('trim', explode('|', $permissionList)));
        if ($slugs === []) {
            return $next($request);
        }

        // Admin (HR) super-role: full access (matches RbacService::can for admin accounts).
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return $next($request);
        }

        if ($this->rbacService->canAny($user, $slugs)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden. Missing permission.',
            'required' => $slugs,
        ], 403);
    }
}
