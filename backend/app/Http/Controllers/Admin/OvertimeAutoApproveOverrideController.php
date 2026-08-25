<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OvertimeAutoApproveOverride;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OvertimeAutoApproveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OvertimeAutoApproveOverrideController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OvertimeAutoApproveService $overtimeAutoApproveService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $query = OvertimeAutoApproveOverride::query()
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,employee_code,department,company_id,is_active',
                'user.company:id,name',
                'updatedBy:id,name,first_name,middle_name,last_name,suffix',
            ])
            ->orderByDesc('updated_at');

        if (! empty($validated['search'])) {
            $term = '%'.trim((string) $validated['search']).'%';
            $query->whereHas('user', function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $scopedIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($request->user());
        if (is_array($scopedIds)) {
            $query->whereIn('user_id', array_map('intval', $scopedIds));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'overrides' => collect($paginator->items())->map(fn (OvertimeAutoApproveOverride $row): array => $this->mapRow($row))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total_active' => OvertimeAutoApproveOverride::query()
                    ->where('is_active', true)
                    ->when(
                        is_array($scopedIds),
                        fn ($q) => $q->whereIn('user_id', array_map('intval', $scopedIds)),
                    )
                    ->count(),
            ],
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['present', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'max_hours_per_day' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $requestedIds = collect($validated['employee_ids'])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $employeeQuery = User::query()
            ->activeRoster()
            ->whereIn('id', $requestedIds->all());
        $this->dataScopeService->restrictEmployeeQuery($actor, $employeeQuery);
        $accessibleIds = $employeeQuery->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values();

        if ($requestedIds->count() !== $accessibleIds->count()) {
            return response()->json([
                'message' => 'One or more selected employees are outside your scope or inactive.',
            ], 422);
        }

        $notes = isset($validated['notes']) ? trim((string) $validated['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }

        $maxHours = array_key_exists('max_hours_per_day', $validated) && $validated['max_hours_per_day'] !== null
            ? round((float) $validated['max_hours_per_day'], 2)
            : OvertimeAutoApproveOverride::DEFAULT_MAX_HOURS_PER_DAY;

        DB::transaction(function () use ($accessibleIds, $actor, $notes, $maxHours): void {
            $existing = OvertimeAutoApproveOverride::query()->pluck('user_id')->map(fn ($id): int => (int) $id);
            $toAdd = $accessibleIds->diff($existing);
            $toRemove = $existing->diff($accessibleIds);

            if ($toRemove->isNotEmpty()) {
                OvertimeAutoApproveOverride::query()->whereIn('user_id', $toRemove->all())->delete();
            }

            foreach ($toAdd as $userId) {
                OvertimeAutoApproveOverride::query()->create([
                    'user_id' => (int) $userId,
                    'is_active' => true,
                    'max_hours_per_day' => $maxHours,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }
        });

        return response()->json([
            'message' => 'Overtime auto-approve overrides saved.',
            'employee_ids' => $accessibleIds->all(),
            'max_hours_per_day' => $maxHours,
        ]);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'max_hours_per_day' => ['sometimes', 'numeric', 'min:0.25', 'max:24'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No fields to update.'], 422);
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $override = OvertimeAutoApproveOverride::query()
            ->with(['user:id,name,first_name,middle_name,last_name,suffix,employee_code', 'user.company:id,name', 'updatedBy'])
            ->where('user_id', $userId)
            ->firstOrFail();

        $employeeQuery = User::query()->whereKey($userId);
        $this->dataScopeService->restrictEmployeeQuery($actor, $employeeQuery);
        if (! $employeeQuery->exists()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (array_key_exists('is_active', $validated)) {
            $override->is_active = (bool) $validated['is_active'];
        }
        if (array_key_exists('max_hours_per_day', $validated)) {
            $override->max_hours_per_day = round((float) $validated['max_hours_per_day'], 2);
        }
        if (array_key_exists('notes', $validated)) {
            $notes = trim((string) ($validated['notes'] ?? ''));
            $override->notes = $notes === '' ? null : $notes;
        }
        $maxHoursChanged = array_key_exists('max_hours_per_day', $validated);
        $override->updated_by = $actor->id;
        $override->save();

        if ($maxHoursChanged && $override->is_active) {
            $employee = User::query()->find($userId);
            if ($employee instanceof User) {
                $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                $to = Carbon::now($tz)->startOfDay();
                $from = $to->copy()->subDays(62);
                $this->overtimeAutoApproveService->syncStandingOvertimeHoursForPeriod($employee, $from, $to);
            }
        }

        return response()->json([
            'message' => 'Override updated.',
            'override' => $this->mapRow($override->fresh(['user.company', 'updatedBy'])),
        ]);
    }

    public function updateStatus(Request $request, int $userId): JsonResponse
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->update($request, $userId);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $employeeQuery = User::query()->whereKey($userId);
        $this->dataScopeService->restrictEmployeeQuery($actor, $employeeQuery);
        if (! $employeeQuery->exists()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        OvertimeAutoApproveOverride::query()->where('user_id', $userId)->delete();

        return response()->json(['message' => 'Employee removed from overtime auto-approve list.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(OvertimeAutoApproveOverride $row): array
    {
        $user = $row->user;

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'is_active' => (bool) $row->is_active,
            'max_hours_per_day' => round((float) ($row->max_hours_per_day ?? OvertimeAutoApproveOverride::DEFAULT_MAX_HOURS_PER_DAY), 2),
            'notes' => $row->notes,
            'employee' => $user ? [
                'id' => (int) $user->id,
                'name' => $user->display_name ?? $user->name,
                'employee_code' => $user->employee_code,
                'department' => $user->department,
                'company_name' => $user->company?->name,
                'is_active' => (bool) $user->is_active,
            ] : null,
            'updated_by_name' => $row->updatedBy?->display_name ?? $row->updatedBy?->name,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
