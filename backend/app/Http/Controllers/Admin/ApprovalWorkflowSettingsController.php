<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApprovalWorkflowSettingService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalWorkflowSettingsController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowSettingService $workflowSettingService,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->workflowSettingService->listSettings());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.request_type' => ['required', 'string'],
            'settings.*.use_hierarchy_approval' => ['required', 'boolean'],
            'settings.*.fallback_to_parent_approver' => ['sometimes', 'boolean'],
            'settings.*.immediate_approver_mode' => ['sometimes', 'string'],
            'settings.*.approval_chain_mode' => ['sometimes', 'string', 'in:nearest_plus_admin,full_hierarchy,custom_selected_steps'],
            'settings.*.max_org_approval_steps' => ['nullable', 'integer', 'min:0', 'max:6'],
            'settings.*.include_section_head' => ['sometimes', 'boolean'],
            'settings.*.include_department_head' => ['sometimes', 'boolean'],
            'settings.*.include_division_head' => ['sometimes', 'boolean'],
            'settings.*.include_branch_head' => ['sometimes', 'boolean'],
            'settings.*.include_area_head' => ['sometimes', 'boolean'],
            'settings.*.include_company_head' => ['sometimes', 'boolean'],
            'settings.*.include_admin_hr' => ['sometimes', 'boolean'],
            'settings.*.is_active' => ['sometimes', 'boolean'],
        ]);

        $payload = $this->workflowSettingService->updateSettings(
            $validated['settings'],
            $request->user(),
        );

        $requestTypes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['request_type'] ?? ''),
            $validated['settings'],
        )));
        $resynced = $this->approvalWorkflowService->resyncPendingRequestChains($requestTypes);

        return response()->json([
            'message' => 'Approval workflow settings updated.',
            'resynced_pending_requests' => $resynced,
            ...$payload,
        ]);
    }
}
