<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    /**
     * List all employees (users with role employee).
     */
    public function index(): JsonResponse
    {
        $employees = User::where('role', User::ROLE_EMPLOYEE)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->employeeResponse($u));

        return response()->json(['employees' => $employees]);
    }

    /**
     * Add a new employee.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'schedule' => ['nullable', 'array'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        $departmentName = $validated['department'] ?? null;
        $departmentId = null;

        // If the provided department matches an existing Department record by name,
        // link the employee via department_id so that department employee counts
        // and "View Employees" lists include this user.
        if ($departmentName !== null && $departmentName !== '') {
            $department = Department::where('name', $departmentName)->first();
            if ($department) {
                $departmentId = $department->id;
                $departmentName = $department->name;
            }
        }

        $user = User::create([
            'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_EMPLOYEE,
            'schedule' => $validated['schedule'] ?? null,
            'is_active' => true,    
            'department' => $departmentName,
            'department_id' => $departmentId,
        ]);

        // Auto-generate QR code based on employee ID.
        $user->forceFill([
            'qr_token' => User::generateQrTokenFor($user),
            'qr_token_generated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Employee added successfully.',
            'employee' => $this->employeeResponse($user),
        ], 201);
    }

    /**
     * Get employee QR token (for printing / issuing badge).
     */
    public function getQr(int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        if (empty($employee->qr_token)) {
            return response()->json([
                'message' => 'No QR token generated yet.',
                'has_qr' => false,
            ], 404);
        }

        return response()->json([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'qr_token' => $employee->qr_token,
            'qr_token_generated_at' => $employee->qr_token_generated_at?->toIso8601String(),
        ]);
    }

    /**
     * Regenerate QR token for an employee (invalidates old QR).
     */
    public function regenerateQr(int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        $employee->update([
            'qr_token' => User::generateQrTokenFor($employee),
            'qr_token_generated_at' => now(),
        ]);

        return response()->json([
            'message' => 'QR token regenerated.',
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'qr_token' => $employee->qr_token,
            'qr_token_generated_at' => $employee->qr_token_generated_at?->toIso8601String(),
        ]);
    }

    /**
     * Clear QR token for an employee (disables QR-based attendance until regenerated).
     */
    public function clearQr(int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        $employee->update([
            'qr_token' => null,
            'qr_token_generated_at' => null,
        ]);

        return response()->json([
            'message' => 'QR token cleared.',
            'employee' => $this->employeeResponse($employee->fresh()),
        ]);
    }

    /**
     * Assign or clear schedule for employee. Pass schedule array or null/empty to clear (no shift assigned).
     */
    public function updateSchedule(Request $request, int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        $validated = $request->validate([
            'schedule' => ['nullable', 'array'],
        ]);

        $schedule = $validated['schedule'] ?? null;
        if ($schedule !== null) {
            if ($schedule === []) {
                $schedule = null;
            } else {
                $hasWorkingDay = false;
                foreach ($schedule as $dayConfig) {
                    if (is_array($dayConfig) && trim((string) ($dayConfig['in'] ?? '')) !== '') {
                        $hasWorkingDay = true;
                        break;
                    }
                }
                if (! $hasWorkingDay) {
                    $schedule = null;
                }
            }
        }
        $employee->update([
            'schedule' => $schedule,
            'working_schedule_id' => null,
        ]);

        return response()->json([
            'message' => 'Schedule updated.',
            'employee' => $this->employeeResponse($employee->fresh()),
        ]);
    }

    /**
     * Toggle employee active status.
     */
    public function toggleActive(int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        $employee->update(['is_active' => !$employee->is_active]);

        return response()->json([
            'message' => $employee->is_active ? 'Employee activated.' : 'Employee deactivated.',
            'employee' => $this->employeeResponse($employee->fresh()),
        ]);
    }

    /**
     * Reset employee password.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $employee = User::where('id', $id)->where('role', User::ROLE_EMPLOYEE)->firstOrFail();

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $employee->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    private function employeeResponse(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'has_qr' => ! empty($user->qr_token),
            'qr_token_generated_at' => $user->qr_token_generated_at?->toIso8601String(),
            'department' => $user->department,
            'department_id' => $user->department_id,
            'schedule' => $user->schedule,
            'working_schedule_id' => $user->working_schedule_id,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'profile_image' => $user->profile_image ? asset('storage/' . $user->profile_image) : null,
        ];
    }
}
