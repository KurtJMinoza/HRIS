<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    private const LOGO_DISK = 'public';
    private const LOGO_DIR = 'department-logos';
    private const LOGO_MAX_KB = 2048; // 2MB
    private const LOGO_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    /**
     * List all departments with total employees, department head, and logo URL.
     */
    public function index(): JsonResponse
    {
        $departments = Department::with('departmentHead:id,name')
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $d) => $this->departmentResponse($d));

        return response()->json(['departments' => $departments]);
    }

    /**
     * Create a department (name + optional logo). Logo: JPG, PNG, WebP; max 2MB.
     * Name is restricted to standard letters/numbers to prevent emojis/symbol spam.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:departments,name',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'logo' => ['nullable', 'image', 'mimes:' . implode(',', self::LOGO_MIMES), 'max:' . self::LOGO_MAX_KB],
        ], [
            'name.regex' => 'Department name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
            'logo.image' => 'The logo must be an image (JPG, PNG, or WebP).',
            'logo.mimes' => 'The logo must be a JPG, PNG, or WebP file.',
            'logo.max' => 'The logo must not exceed 2MB.',
        ]);

        $path = $request->hasFile('logo') ? $request->file('logo')->store(self::LOGO_DIR, self::LOGO_DISK) : null;
        $department = Department::create([
            'name' => $validated['name'],
            'logo' => $path,
        ]);

        return response()->json([
            'message' => 'Department created successfully.',
            'department' => $this->departmentResponse($department),
        ], 201);
    }

    /**
     * List employees in this department (for View Employees). Returns id, name, profile_image URL.
     */
    public function employees(int $id): JsonResponse
    {
        $department = Department::findOrFail($id);
        $employees = $department->employees()
            ->where('role', User::ROLE_EMPLOYEE)
            ->orderBy('name')
            ->get(['id', 'name', 'profile_image'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'profile_image' => $u->profile_image ? asset('storage/' . $u->profile_image) : null,
            ]);

        return response()->json([
            'department' => ['id' => $department->id, 'name' => $department->name],
            'employees' => $employees,
        ]);
    }

    /**
     * Update department (name and/or department head).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                'unique:departments,name,' . $id,
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'department_head_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'name.regex' => 'Department name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        if (isset($validated['name'])) {
            $department->name = $validated['name'];
            $department->save();
            User::where('department_id', $department->id)->update(['department' => $department->name]);
        }

        if (array_key_exists('department_head_id', $validated)) {
            $department->department_head_id = $validated['department_head_id'];
            $department->save();
        }

        return response()->json([
            'message' => 'Department updated successfully.',
            'department' => $this->departmentResponse($department->fresh(['departmentHead:id,name'])),
        ]);
    }

    /**
     * Assign employees to this department.
     */
    public function assignEmployees(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::whereIn('id', $validated['employee_ids'])
            ->where('role', User::ROLE_EMPLOYEE)
            ->update([
                'department_id' => $department->id,
                'department' => $department->name,
            ]);

        return response()->json([
            'message' => 'Employees assigned successfully.',
            'department' => $this->departmentResponse($department->fresh(['departmentHead:id,name'])->loadCount('employees')),
        ]);
    }

    /**
     * Unassign employees from this department (sets department_id and department to null).
     */
    public function unassignEmployees(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        User::whereIn('id', $validated['employee_ids'])
            ->where('department_id', $id)
            ->update(['department_id' => null, 'department' => null]);

        return response()->json([
            'message' => 'Employees unassigned successfully.',
            'department' => $this->departmentResponse($department->fresh(['departmentHead:id,name'])->loadCount('employees')),
        ]);
    }

    /**
     * Delete a department. Unassigns employees and removes logo from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $department = Department::findOrFail($id);
        if ($department->logo && Storage::disk(self::LOGO_DISK)->exists($department->logo)) {
            Storage::disk(self::LOGO_DISK)->delete($department->logo);
        }
        User::where('department_id', $id)->update(['department_id' => null, 'department' => null]);
        $department->delete();

        return response()->json(['message' => 'Department deleted successfully.']);
    }

    private function departmentResponse(Department $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'logo' => $d->logo,
            'logo_url' => $d->logo ? asset('storage/' . $d->logo) : null,
            'total_employees' => $d->employees_count ?? $d->employees()->count(),
            'department_head_id' => $d->department_head_id,
            'department_head_name' => $d->departmentHead?->name,
        ];
    }
}
