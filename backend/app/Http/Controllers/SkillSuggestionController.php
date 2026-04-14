<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillSuggestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 8);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 20) {
            $limit = 20;
        }

        $fallback = collect([
            'JavaScript',
            'React',
            'Node.js',
            'UI Design',
            'Project Management',
            'Python',
        ]);

        $base = EmployeeSkill::query()
            ->selectRaw('name, COUNT(*) as uses')
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->groupBy('name')
            ->orderByDesc('uses')
            ->orderBy('name')
            ->limit($limit)
            ->pluck('name');

        $list = $base->isNotEmpty()
            ? $base
            : $fallback
                ->filter(fn ($name) => $q === '' ? true : str_contains(mb_strtolower($name), mb_strtolower($q)))
                ->take($limit)
                ->values();

        return response()->json(['suggestions' => $list->values()]);
    }
}
