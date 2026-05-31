<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublicSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $start = microtime(true);
        $cacheKey = 'app:public-settings';
        $cacheHit = Cache::has($cacheKey);

        $settings = Cache::remember($cacheKey, now()->addMinutes(30), function () {
            $company = Company::query()
                ->whereNotNull('name')
                ->orderBy('id')
                ->first(['id', 'name', 'logo']);

            return [
                'app_name' => config('app.name', 'HRIS'),
                'company_name' => $company?->name,
                'company_logo_url' => $company?->logo
                    ? url('/api/media/public/'.ltrim((string) $company->logo, '/'))
                    : null,
                'timezone' => config('attendance.timezone', 'Asia/Manila'),
                'theme' => 'light',
            ];
        });

        $settings['_debug'] = [
            'endpoint' => 'public-settings',
            'cache_hit' => $cacheHit,
            'time_ms' => round((microtime(true) - $start) * 1000),
        ];

        Log::debug('[PublicSettings] response timing', [
            'endpoint' => 'public-settings',
            'cache_hit' => $cacheHit,
            'time_ms' => $settings['_debug']['time_ms'],
        ]);

        return response()->json($settings);
    }
}
