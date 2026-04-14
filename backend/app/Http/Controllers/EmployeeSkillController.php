<?php

namespace App\Http\Controllers;

use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeSkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $skills = EmployeeSkill::where('user_id', $user->id)
            ->orderBy('name')
            ->get()
            ->map(fn (EmployeeSkill $s) => ['id' => $s->id, 'name' => $s->name])
            ->values();

        return response()->json(['skills' => $skills]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim((string) $validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Skill name is required.']]);
        }

        $exists = EmployeeSkill::where('user_id', $user->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Skill already exists.']]);
        }

        $skill = EmployeeSkill::create([
            'user_id' => $user->id,
            'name' => $name,
        ]);

        return response()->json([
            'message' => 'Skill added.',
            'skill' => ['id' => $skill->id, 'name' => $skill->name],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $skill = EmployeeSkill::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim((string) $validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Skill name is required.']]);
        }

        $exists = EmployeeSkill::where('user_id', $user->id)
            ->where('id', '!=', $skill->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Skill already exists.']]);
        }

        $skill->name = $name;
        $skill->save();

        return response()->json([
            'message' => 'Skill updated.',
            'skill' => ['id' => $skill->id, 'name' => $skill->name],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $skill = EmployeeSkill::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $skill->delete();

        return response()->json(['message' => 'Skill removed.']);
    }
}
