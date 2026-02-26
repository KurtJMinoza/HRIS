<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceCorrectionController extends Controller
{
    /**
     * Normalize time string to H:i (e.g. "12:00:00" -> "12:00"). Accepts H:i or H:i:s.
     */
    private function normalizeTimeToHi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        $v = trim($value);
        if (strlen($v) >= 5 && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        return $v;
    }

    /**
     * Create or update a manual attendance correction for a given employee and date.
     */
    public function store(Request $request): JsonResponse
    {
        // Accept both "HH:MM" and "HH:MM:SS" coming from browsers, normalize to H:i.
        $toMerge = [];
        foreach (['time_in', 'time_out'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                $normalized = $this->normalizeTimeToHi(is_string($val) ? $val : (string) $val);
                if ($normalized !== null) {
                    $toMerge[$key] = $normalized;
                }
            }
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:65535'],
            'approved' => ['nullable', 'boolean'],
        ], [
            'employee_id.required' => 'Employee is required.',
            'employee_id.exists' => 'Selected employee does not exist.',
            'date.required' => 'Date is required.',
        ]);

        // Enforce "no future dates" using the configured attendance timezone so
        // that manual attendance cannot be recorded ahead of server time.
        $tz = config('attendance.timezone', config('app.timezone'));
        $today = now($tz)->toDateString();
        $inputDate = Carbon::parse($validated['date'], $tz)->toDateString();
        if ($inputDate > $today) {
            return response()->json([
                'message' => 'Future dates are not allowed for manual attendance.',
            ], 422);
        }

        $timeInStr = $validated['time_in'] ?? null;
        $timeOutStr = $validated['time_out'] ?? null;
        if ($timeInStr && $timeOutStr && $timeOutStr <= $timeInStr) {
            return response()->json([
                'message' => 'Time out must be after time in.',
            ], 422);
        }

        /** @var User $employee */
        $employee = User::where('id', $validated['employee_id'])
            ->where('role', User::ROLE_EMPLOYEE)
            ->firstOrFail();

        // Store manual attendance in the application timezone without extra conversions.
        // Status/late/undertime logic will handle timezone normalization separately.
        $date = Carbon::parse($validated['date'])->toDateString();

        $timeIn = null;
        $timeOut = null;
        if (! empty($timeInStr)) {
            $timeIn = Carbon::parse($date . ' ' . $timeInStr);
        }
        if (! empty($timeOutStr)) {
            $timeOut = Carbon::parse($date . ' ' . $timeOutStr);
        }

        $approved = (bool) ($validated['approved'] ?? false);
        $now = now();
        $admin = $request->user();

        $correction = AttendanceCorrection::updateOrCreate(
            [
                'user_id' => $employee->id,
                'date' => $date,
            ],
            [
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'remarks' => $validated['remarks'] ?? null,
                'approved' => $approved,
                'approved_by' => $approved ? $admin?->id : null,
                'approved_at' => $approved ? $now : null,
            ]
        );

        return response()->json([
            'message' => 'Attendance correction saved.',
            'correction' => [
                'id' => $correction->id,
                'employee_id' => $correction->user_id,
                'date' => $correction->date?->toDateString(),
                'time_in' => $correction->time_in?->toIso8601String(),
                'time_out' => $correction->time_out?->toIso8601String(),
                'remarks' => $correction->remarks,
                'approved' => $correction->approved,
                'approved_by' => $correction->approved_by,
                'approved_at' => $correction->approved_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete an attendance correction by id.
     */
    public function destroy(int $id): JsonResponse
    {
        $correction = AttendanceCorrection::findOrFail($id);
        $correction->delete();

        return response()->json([
            'message' => 'Attendance correction removed.',
        ]);
    }
}

