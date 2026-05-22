<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FlexibleImmediateApproverResolver;
use App\Services\HrApprovalChainResolver;
use App\Services\OrganizationLeadershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationLeadershipController extends Controller
{
    public function __construct(
        private readonly OrganizationLeadershipService $leadershipService,
        private readonly FlexibleImmediateApproverResolver $immediateApproverResolver,
        private readonly HrApprovalChainResolver $approvalChainResolver,
    ) {}

    public function show(string $legacyType, int $legacyId): JsonResponse
    {
        $this->assertLegacyType($legacyType);

        return response()->json($this->leadershipService->leadershipPayload($legacyType, $legacyId));
    }

    public function update(Request $request, string $legacyType, int $legacyId): JsonResponse
    {
        $this->assertLegacyType($legacyType);

        $validated = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*.id' => ['nullable', 'integer'],
            'assignments.*.position_type_id' => ['required', 'integer', 'exists:organization_position_types,id'],
            'assignments.*.employee_id' => ['required', 'integer', 'exists:users,id'],
            'assignments.*.is_primary' => ['sometimes', 'boolean'],
            'assignments.*.approval_priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'assignments.*.effective_from' => ['nullable', 'date'],
            'assignments.*.effective_to' => ['nullable', 'date'],
            'assignments.*.is_active' => ['sometimes', 'boolean'],
            'assignments.*.remarks' => ['nullable', 'string', 'max:500'],
            'assignments.*.department_scope_mode' => ['nullable', 'string', 'in:all,selected,none'],
            'assignments.*.department_scope_ids' => ['nullable', 'array'],
            'assignments.*.department_scope_ids.*' => ['integer', 'min:1'],
            'assignments.*.scope_request_type' => ['nullable', 'string', 'in:all,leave,overtime'],
        ]);

        $payload = $this->leadershipService->syncLeadership(
            $legacyType,
            $legacyId,
            $validated['assignments'],
        );

        return response()->json($payload);
    }

    public function approvalRoutePreview(Request $request, int $employeeId): JsonResponse
    {
        $employee = User::query()->findOrFail($employeeId);
        $requestType = $request->query('request_type');

        $immediate = $this->immediateApproverResolver->resolveImmediateApprover($employee, $requestType, $employee);
        $chain = $this->approvalChainResolver->resolveApprovalChain($employee, $requestType, $employee);

        return response()->json([
            'employee_id' => (int) $employee->id,
            'request_type' => $requestType,
            'immediate_approver' => $immediate ? [
                'approver_id' => $immediate['approver_id'],
                'approver_name' => $immediate['approver_name'],
                'approval_label' => $immediate['approval_label'],
                'leader_role' => $immediate['leader_role'],
                'eligible_approver_ids' => $immediate['eligible_approver_ids'],
                'routing_rule' => $immediate['routing_rule'],
            ] : null,
            'approval_chain' => array_map(fn (array $step): array => [
                'sequence_order' => $step['sequence_order'],
                'approval_level' => $step['approval_level'],
                'approval_label' => $step['approval_label'] ?? null,
                'approver_role' => $step['approver_role'] instanceof \App\Enums\HrRole
                    ? $step['approver_role']->value
                    : (string) $step['approver_role'],
                'approver_id' => $step['approver_id'],
                'approver_name' => $step['approver_name'],
                'eligible_approver_ids' => $step['eligible_approver_ids'] ?? null,
                'routing_rule' => $step['routing_rule'] ?? null,
            ], $chain),
        ]);
    }

    private function assertLegacyType(string $legacyType): void
    {
        if (! in_array($legacyType, $this->leadershipService->supportedLegacyTypes(), true)) {
            abort(404, 'Unsupported organization level.');
        }
    }
}
