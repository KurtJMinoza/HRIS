<?php

namespace App\Services;

use App\Models\EmployeeActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeActivityRecorder
{
    public function recordLogin(User $user, Request $request, string $authMethod, ?int $sessionTokenId = null): void
    {
        $this->record(
            user: $user,
            eventType: EmployeeActivityLog::EVENT_LOGIN,
            category: EmployeeActivityLog::CATEGORY_AUTH,
            title: 'Logged in',
            summary: 'Signed in via '.$this->authMethodLabel($authMethod),
            module: 'Authentication',
            path: '/login',
            request: $request,
            authMethod: $authMethod,
            sessionTokenId: $sessionTokenId,
            meta: [
                'portal' => $this->portalFromUser($user),
            ],
        );
    }

    public function recordLogout(User $user, Request $request, ?int $sessionTokenId = null): void
    {
        $this->record(
            user: $user,
            eventType: EmployeeActivityLog::EVENT_LOGOUT,
            category: EmployeeActivityLog::CATEGORY_AUTH,
            title: 'Logged out',
            summary: 'Ended session',
            module: 'Authentication',
            path: '/logout',
            request: $request,
            sessionTokenId: $sessionTokenId,
            meta: [
                'portal' => $this->portalFromUser($user),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordNavigation(User $user, Request $request, array $payload): void
    {
        $eventType = (string) ($payload['event_type'] ?? EmployeeActivityLog::EVENT_PAGE_VIEW);
        if (! in_array($eventType, [EmployeeActivityLog::EVENT_PAGE_VIEW, EmployeeActivityLog::EVENT_MODULE_OPEN], true)) {
            $eventType = EmployeeActivityLog::EVENT_PAGE_VIEW;
        }

        $path = Str::limit(trim((string) ($payload['path'] ?? '')), 512, '');
        $module = Str::limit(trim((string) ($payload['module'] ?? '')), 128, '');
        $title = Str::limit(trim((string) ($payload['title'] ?? '')), 255, '');
        if ($title === '') {
            $title = $eventType === EmployeeActivityLog::EVENT_MODULE_OPEN ? 'Opened module' : 'Viewed page';
        }

        $summaryParts = array_filter([$module !== '' ? $module : null, $path !== '' ? $path : null]);
        $referrer = trim((string) ($payload['referrer_path'] ?? ''));
        if ($referrer !== '') {
            $summaryParts[] = 'from '.$referrer;
        }

        $this->record(
            user: $user,
            eventType: $eventType,
            category: EmployeeActivityLog::CATEGORY_NAVIGATION,
            title: $title,
            summary: $summaryParts !== [] ? implode(' · ', $summaryParts) : null,
            module: $module !== '' ? $module : null,
            path: $path !== '' ? $path : null,
            request: $request,
            sessionTokenId: $request->user()?->currentAccessToken()?->id,
            meta: array_filter([
                'referrer_path' => $referrer !== '' ? $referrer : null,
                'portal' => $this->portalFromPath($path),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function record(
        User $user,
        string $eventType,
        string $category,
        ?string $title,
        ?string $summary,
        ?string $module,
        ?string $path,
        ?Request $request = null,
        ?string $authMethod = null,
        ?int $sessionTokenId = null,
        ?array $meta = null,
    ): void {
        $ua = $request?->userAgent();

        EmployeeActivityLog::query()->create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'category' => $category,
            'module' => $module,
            'title' => $title,
            'path' => $path,
            'summary' => $summary,
            'auth_method' => $authMethod,
            'session_token_id' => $sessionTokenId,
            'ip_address' => $request?->ip(),
            'user_agent' => $ua,
            'device_type' => $this->detectDeviceType($ua),
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }

    private function authMethodLabel(string $method): string
    {
        return match ($method) {
            'qr' => 'QR code',
            'face' => 'Face recognition',
            default => 'Username & password',
        };
    }

    private function portalFromUser(User $user): string
    {
        if ($user->is_super_admin || in_array((string) $user->role, ['admin', 'hr'], true)) {
            return 'admin';
        }

        return 'employee';
    }

    private function portalFromPath(string $path): ?string
    {
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/company') || str_starts_with($path, '/branch')) {
            return 'admin';
        }
        if (str_starts_with($path, '/employee')) {
            return 'employee';
        }

        return null;
    }

    private function detectDeviceType(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'ipad') || (str_contains($ua, 'tablet') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
