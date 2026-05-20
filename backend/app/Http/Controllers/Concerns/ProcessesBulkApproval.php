<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ProcessesBulkApproval
{
    /**
     * @return array{mode: string, ids: int[], filters: array<string, mixed>, remarks: ?string}
     */
    protected function parseBulkApproveRequest(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'string', 'in:selected_ids,all_matching'],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
            'request_ids' => ['sometimes', 'array'],
            'request_ids.*' => ['integer'],
            'filters' => ['sometimes', 'array'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $remarks = isset($data['remarks']) ? (string) $data['remarks'] : null;
        if ($remarks !== null && trim($remarks) === '') {
            $remarks = null;
        }

        $mode = $data['mode'] ?? null;
        if ($mode === null) {
            if (! empty($data['filters']) && empty($data['ids']) && empty($data['request_ids'])) {
                $mode = 'all_matching';
            } else {
                $mode = 'selected_ids';
            }
        }

        if ($mode === 'all_matching') {
            return [
                'mode' => 'all_matching',
                'ids' => [],
                'filters' => is_array($data['filters'] ?? null) ? $data['filters'] : [],
                'remarks' => $remarks,
            ];
        }

        $ids = $data['ids'] ?? $data['request_ids'] ?? [];
        $ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));

        return [
            'mode' => 'selected_ids',
            'ids' => $ids,
            'filters' => [],
            'remarks' => $remarks,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function normalizeBulkApproveFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @throws ValidationException
     */
    protected function assertBulkApproveIdsPresent(array $ids): void
    {
        if (count($ids) === 0) {
            throw ValidationException::withMessages([
                'ids' => ['Select at least one request to approve.'],
            ]);
        }
    }

    protected function bulkApproveJsonResponse(
        int $approved,
        int $skipped,
        int $failed,
        array $failedItems,
        string $entityLabel
    ): JsonResponse {
        $label = strtolower($entityLabel);

        return response()->json([
            'message' => $approved > 0
                ? "Bulk {$entityLabel} approval completed."
                : "No {$label} were approved.",
            'approved_count' => $approved,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'failed_items' => $failedItems,
        ]);
    }
}
