<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BenefitCatalog;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BenefitCatalogController extends Controller
{
    /**
     * List benefit catalog options, optionally filtered by department (company) and type.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BenefitCatalog::with('department:id,name');
        if ($request->input('all') !== '1' && $request->input('all') !== true) {
            $query->where('is_active', true);
        }

        if ($request->has('department_id') && $request->input('department_id') !== null && $request->input('department_id') !== '') {
            $query->where('department_id', (int) $request->input('department_id'));
        }

        if ($request->has('type') && trim((string) $request->input('type')) !== '') {
            $query->where('type', $request->input('type'));
        }

        $catalogs = $query->orderBy('type')->orderBy('name')->get()->map(fn (BenefitCatalog $c) => $this->catalogResponse($c));

        return response()->json(['catalogs' => $catalogs]);
    }

    /**
     * Store a new benefit catalog item (Company Benefits Configuration).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'type' => ['required', 'string', Rule::in(BenefitCatalog::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'type.in' => 'Benefit type must be one of: health_insurance, retirement_plan, leave_benefits, allowance, other.',
        ]);

        $catalog = BenefitCatalog::create([
            'department_id' => $validated['department_id'],
            'type' => $validated['type'],
            'name' => trim($validated['name']),
            'metadata' => $validated['metadata'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Benefit catalog created successfully.',
            'catalog' => $this->catalogResponse($catalog->load('department:id,name')),
        ], 201);
    }

    /**
     * Update a benefit catalog item.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $catalog = BenefitCatalog::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $catalog->name = trim($validated['name']);
        }
        if (array_key_exists('metadata', $validated)) {
            $catalog->metadata = $validated['metadata'];
        }
        if (array_key_exists('is_active', $validated)) {
            $catalog->is_active = (bool) $validated['is_active'];
        }
        $catalog->save();

        return response()->json([
            'message' => 'Benefit catalog updated successfully.',
            'catalog' => $this->catalogResponse($catalog->fresh('department:id,name')),
        ]);
    }

    /**
     * Delete a benefit catalog item.
     */
    public function destroy(int $id): JsonResponse
    {
        $catalog = BenefitCatalog::findOrFail($id);
        $catalog->delete();

        return response()->json(['message' => 'Benefit catalog deleted successfully.']);
    }

    private function catalogResponse(BenefitCatalog $c): array
    {
        return [
            'id' => $c->id,
            'department_id' => $c->department_id,
            'department_name' => $c->department?->name,
            'type' => $c->type,
            'name' => $c->name,
            'metadata' => $c->metadata,
            'is_active' => $c->is_active,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
