<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeductionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DeductionTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->getEffectiveCompanyId();
        $query = DeductionType::query()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->orderBy('name');

        return response()->json([
            'deduction_types' => $query->with('payComponent:id,name,code')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(DeductionType::TYPES)],
            'is_government' => ['sometimes', 'boolean'],
            'pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'with_interest' => ['sometimes', 'boolean'],
            'interest_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'interest_type' => ['nullable', 'string', Rule::in(['simple', 'compound'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $companyId = $request->user()?->getEffectiveCompanyId();
        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $slug = Str::limit($slug, 100, '');

        $isLoan = ($validated['type'] ?? null) === DeductionType::TYPE_LOAN;
        $withInterest = $isLoan ? (bool) ($validated['with_interest'] ?? false) : false;
        $interestRate = $withInterest ? round(max(0.0, (float) ($validated['interest_rate_percent'] ?? 0)), 4) : null;
        $interestType = $withInterest ? (string) ($validated['interest_type'] ?? 'simple') : null;

        $attributes = [
            'company_id' => $companyId,
            'name' => trim($validated['name']),
            'slug' => $slug.'-'.substr(sha1((string) microtime(true)), 0, 6),
            'type' => $validated['type'],
            'is_government' => (bool) ($validated['is_government'] ?? false),
            'pay_component_id' => $validated['pay_component_id'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
        if (Schema::hasColumn('pay_deduction_types', 'with_interest')) {
            $attributes['with_interest'] = $withInterest;
        }
        if (Schema::hasColumn('pay_deduction_types', 'interest_rate_percent')) {
            $attributes['interest_rate_percent'] = $interestRate;
        }
        if (Schema::hasColumn('pay_deduction_types', 'interest_type')) {
            $attributes['interest_type'] = $interestType;
        }

        $type = DeductionType::create($attributes);

        return response()->json(['deduction_type' => $type->load('payComponent:id,name,code')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = DeductionType::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(DeductionType::TYPES)],
            'is_government' => ['sometimes', 'boolean'],
            'pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'with_interest' => ['sometimes', 'boolean'],
            'interest_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'interest_type' => ['nullable', 'string', Rule::in(['simple', 'compound'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $nextType = $validated['type'] ?? $type->type;
        $isLoan = $nextType === DeductionType::TYPE_LOAN;
        if (! $isLoan) {
            $validated['with_interest'] = false;
            $validated['interest_rate_percent'] = null;
            $validated['interest_type'] = null;
        } elseif (array_key_exists('with_interest', $validated) && ! $validated['with_interest']) {
            $validated['interest_rate_percent'] = null;
            $validated['interest_type'] = null;
        } elseif (array_key_exists('with_interest', $validated) && $validated['with_interest']) {
            $validated['interest_type'] = $validated['interest_type'] ?? 'simple';
            if (array_key_exists('interest_rate_percent', $validated) && $validated['interest_rate_percent'] !== null) {
                $validated['interest_rate_percent'] = round(max(0.0, (float) $validated['interest_rate_percent']), 4);
            }
        }

        foreach (['with_interest', 'interest_rate_percent', 'interest_type'] as $interestCol) {
            if (! Schema::hasColumn('pay_deduction_types', $interestCol)) {
                unset($validated[$interestCol]);
            }
        }

        $type->fill($validated);
        $type->save();

        return response()->json(['deduction_type' => $type->fresh('payComponent:id,name,code')]);
    }
}
