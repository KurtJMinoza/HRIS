<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportEmployeeRequest;
use App\Http\Requests\Admin\RollbackEmployeeImportRequest;
use App\Imports\EmployeeImport;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeImportPreviewService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ImportEmployeeController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    public function preview(ImportEmployeeRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file) {
            return response()->json(['message' => 'No import file was uploaded.'], 422);
        }

        return response()->json(EmployeeImportPreviewService::build($file));
    }

    public function import(ImportEmployeeRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file) {
            return response()->json(['message' => 'No import file was uploaded.'], 422);
        }

        $batchId = (string) Str::uuid();
        $import = new EmployeeImport($request->user(), $this->dataScopeService, $batchId);
        Excel::import($import, $file);
        $summary = $import->summary();

        return response()->json([
            'message' => sprintf(
                '%d employees imported successfully, %d failed.',
                $summary['imported'],
                $summary['failed']
            ),
            ...$summary,
        ]);
    }

    /**
     * Delete all employees created in a single bulk-import batch (same {@see User::$employee_import_batch_id}).
     */
    public function rollback(RollbackEmployeeImportRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $batchId = (string) $request->validated('import_batch_id');
        $users = User::query()
            ->where('employee_import_batch_id', $batchId)
            ->where('role', User::ROLE_EMPLOYEE)
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['message' => 'No employees found for this import batch.'], Response::HTTP_NOT_FOUND);
        }

        foreach ($users as $employee) {
            try {
                $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
            } catch (HttpResponseException $e) {
                return $e->getResponse();
            }
            $this->ensureActorCanMutateEmployeeForImportRollback($actor, $employee);
        }

        $ids = [];
        DB::transaction(function () use ($users, &$ids): void {
            foreach ($users as $employee) {
                $ids[] = (int) $employee->id;
                $employee->delete();
            }
        });

        return response()->json([
            'message' => sprintf('Removed %d imported employee(s).', count($ids)),
            'deleted_count' => count($ids),
            'deleted_user_ids' => $ids,
        ]);
    }

    /**
     * Same rules as {@see EmployeeController::ensureActorCanMutateEmployee} for delete operations.
     */
    private function ensureActorCanMutateEmployeeForImportRollback(User $actor, User $employee): void
    {
        if ($this->canMutateAnyEmployee($actor)) {
            return;
        }
        if ((int) $actor->id === (int) $employee->id) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Forbidden. You may only edit your own profile.',
        ], Response::HTTP_FORBIDDEN));
    }

    private function canMutateAnyEmployee(User $actor): bool
    {
        $hrRole = strtolower(trim((string) ($actor->hr_role ?? '')));

        return $actor->isAdmin() || in_array($hrRole, ['admin_hr', 'admin'], true);
    }
}
