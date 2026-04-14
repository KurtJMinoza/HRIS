<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA stateful middleware (cookies + CSRF for first-party frontend)
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'hr.panel' => \App\Http\Middleware\EnsureHrPanelAccess::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Add request/route context for fatal timeout diagnostics.
        $exceptions->report(function (\Throwable $e): void {
            $message = (string) $e->getMessage();
            if (! str_contains($message, 'Maximum execution time')) {
                return;
            }

            try {
                $request = request();
                $route = $request?->route();
                $action = $route?->getActionName();
                $routeName = $route?->getName();

                Log::error('Fatal timeout request context', [
                    'method' => $request?->method(),
                    'path' => $request?->path(),
                    'full_url' => $request?->fullUrl(),
                    'route_name' => $routeName,
                    'route_uri' => $route?->uri(),
                    'controller_action' => $action,
                    'user_id' => optional($request?->user())->id,
                    'ip' => $request?->ip(),
                ]);
            } catch (\Throwable) {
                // Avoid recursive reporting failures on fatal paths.
            }
        });

        // API requests must get JSON 401 instead of redirecting to route('login').
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });
    })->create();
