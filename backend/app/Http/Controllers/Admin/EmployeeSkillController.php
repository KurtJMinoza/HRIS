<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeSkillController extends Controller
{
    use AssertsEmployeeOrgScope;

    public function index(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $skills = EmployeeSkill::where('user_id', $employee->id)
            ->orderBy('name')
            ->get()
            ->map(fn (EmployeeSkill $s) => ['id' => $s->id, 'name' => $s->name])
            ->values();

        return response()->json(['skills' => $skills]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim((string) $validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Skill name is required.']]);
        }

        $exists = EmployeeSkill::where('user_id', $employee->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Skill already exists.']]);
        }

        $skill = EmployeeSkill::create([
            'user_id' => $employee->id,
            'name' => $name,
        ]);

        return response()->json([
            'message' => 'Skill added.',
            'skill' => ['id' => $skill->id, 'name' => $skill->name],
        ], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $skill = EmployeeSkill::where('id', $id)->where('user_id', $employee->id)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim((string) $validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Skill name is required.']]);
        }

        $exists = EmployeeSkill::where('user_id', $employee->id)
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

    public function destroy(Request $request, int $userId, int $id): JsonResponse
    {
        $employee = User::where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);
        $skill = EmployeeSkill::where('id', $id)->where('user_id', $employee->id)->firstOrFail();
        $skill->delete();

        return response()->json(['message' => 'Skill removed.']);
    }
}
