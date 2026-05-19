<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\BenefitCatalog;
use App\Models\EmployeeBenefit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeBenefitController extends Controller
{
    use AssertsEmployeeOrgScope;

    /**
     * List benefits assigned to an employee.
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $user);

        $assignments = $user->employeeBenefits()
            ->with('benefitCatalog.department:id,name')
            ->orderByDesc('effective_date')
            ->get()
            ->map(fn (EmployeeBenefit $eb) => $this->assignmentResponse($eb));

        return response()->json([
            'employee_id' => $user->id,
            'employee_name' => $user->display_name,
            'employee_formatted_name' => $user->formatted_name,
            'department_id' => $user->department_id,
            'benefits' => $assignments,
        ]);
    }

    /**
     * Assign a benefit to an employee.
     */
    public function store(Request $request, int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $user);

        $validated = $request->validate([
            'benefit_catalog_id' => ['required', 'integer', 'exists:benefit_catalogs,id'],
            'effective_date' => ['required', 'date'],
            'status' => ['sometimes', 'string', Rule::in([EmployeeBenefit::STATUS_ACTIVE, EmployeeBenefit::STATUS_INACTIVE, EmployeeBenefit::STATUS_SUSPENDED])],
            'metadata' => ['nullable', 'array'],
        ]);

        $catalog = BenefitCatalog::findOrFail($validated['benefit_catalog_id']);

        if ((int) $catalog->department_id !== (int) $user->department_id) {
            throw ValidationException::withMessages([
                'benefit_catalog_id' => ['The selected benefit must belong to the employee\'s company.'],
            ]);
        }

        if ($user->employeeBenefits()->where('benefit_catalog_id', $catalog->id)->exists()) {
            throw ValidationException::withMessages([
                'benefit_catalog_id' => ['This benefit is already assigned to the employee.'],
            ]);
        }

        $assignment = EmployeeBenefit::create([
            'user_id' => $user->id,
            'benefit_catalog_id' => $catalog->id,
            'effective_date' => $validated['effective_date'],
            'status' => $validated['status'] ?? EmployeeBenefit::STATUS_ACTIVE,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $assignment->load('benefitCatalog.department:id,name');

        return response()->json([
            'message' => 'Benefit assigned successfully.',
            'benefit' => $this->assignmentResponse($assignment),
        ], 201);
    }

    /**
     * Update an assigned benefit (effective date, status, metadata).
     */
    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $user = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $user);
        $assignment = EmployeeBenefit::where('user_id', $userId)->where('id', $id)->with('benefitCatalog')->firstOrFail();

        $validated = $request->validate([
            'effective_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'string', Rule::in([EmployeeBenefit::STATUS_ACTIVE, EmployeeBenefit::STATUS_INACTIVE, EmployeeBenefit::STATUS_SUSPENDED])],
            'metadata' => ['nullable', 'array'],
        ]);

        if (array_key_exists('effective_date', $validated)) {
            $assignment->effective_date = $validated['effective_date'];
        }
        if (array_key_exists('status', $validated)) {
            $assignment->status = $validated['status'];
        }
        if (array_key_exists('metadata', $validated)) {
            $assignment->metadata = $validated['metadata'];
        }
        $assignment->save();

        $assignment->load('benefitCatalog.department:id,name');

        return response()->json([
            'message' => 'Benefit assignment updated successfully.',
            'benefit' => $this->assignmentResponse($assignment),
        ]);
    }

    /**
     * Remove a benefit assignment from an employee.
     */
    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $user = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $user);
        $assignment = EmployeeBenefit::where('user_id', $userId)->where('id', $id)->firstOrFail();
        $assignment->delete();

        return response()->json(['message' => 'Benefit removed successfully.']);
    }

    private function assignmentResponse(EmployeeBenefit $eb): array
    {
        $catalog = $eb->benefitCatalog;

        return [
            'id' => $eb->id,
            'user_id' => $eb->user_id,
            'benefit_catalog_id' => $eb->benefit_catalog_id,
            'effective_date' => $eb->effective_date?->toDateString(),
            'status' => $eb->status,
            'metadata' => $eb->metadata,
            'catalog' => $catalog ? [
                'id' => $catalog->id,
                'type' => $catalog->type,
                'name' => $catalog->name,
                'metadata' => $catalog->metadata,
                'department_id' => $catalog->department_id,
                'department_name' => $catalog->department?->name,
            ] : null,
            'created_at' => $eb->created_at?->toIso8601String(),
            'updated_at' => $eb->updated_at?->toIso8601String(),
        ];
    }
}
