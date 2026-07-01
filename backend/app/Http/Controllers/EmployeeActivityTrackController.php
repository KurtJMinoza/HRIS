<?php

namespace App\Http\Controllers;

use App\Models\EmployeeActivityLog;
use App\Services\EmployeeActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class EmployeeActivityTrackController extends Controller
{
    public function __construct(
        private readonly EmployeeActivityRecorder $recorder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $key = 'employee-activity:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json(['message' => 'Too many activity events.'], 429);
        }
        RateLimiter::hit($key, 60);

        $validated = $request->validate([
            'event_type' => ['nullable', 'string', Rule::in([
                EmployeeActivityLog::EVENT_PAGE_VIEW,
                EmployeeActivityLog::EVENT_MODULE_OPEN,
            ])],
            'path' => ['required', 'string', 'max:512'],
            'module' => ['nullable', 'string', 'max:128'],
            'title' => ['nullable', 'string', 'max:255'],
            'referrer_path' => ['nullable', 'string', 'max:512'],
        ]);

        $this->recorder->recordNavigation($user, $request, $validated);

        return response()->json(['ok' => true]);
    }
}
