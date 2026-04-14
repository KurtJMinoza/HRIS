<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeductionScheduleSetting;
use App\Services\DeductionScheduleService;
use App\Services\PayCycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeductionScheduleController extends Controller
{
    public function __construct(
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly PayCycleService $payCycleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->filled('company_id')
            ? (int) $request->query('company_id')
            : $request->user()->getEffectiveCompanyId();

        $rows = $this->deductionScheduleService->listRowsForAdmin($companyId);

        return response()->json([
            'company_id' => $companyId,
            'settings' => $rows,
            'schedule_options' => [
                ['value' => DeductionScheduleSetting::SCHEDULE_15TH, 'label' => 'First semi-monthly run'],
                ['value' => DeductionScheduleSetting::SCHEDULE_30TH, 'label' => 'End of month'],
                ['value' => DeductionScheduleSetting::SCHEDULE_BOTH, 'label' => '50/50 split'],
            ],
        ]);
    }

    public function nextDeductionDates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_type' => ['required', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $targetUser = null;
        if (! empty($validated['user_id'])) {
            $targetUser = \App\Models\User::query()->find((int) $validated['user_id']);
        }
        $targetUser = $targetUser ?: $request->user();

        $payload = $this->payCycleService->getNextDeductionDate(
            $targetUser,
            (string) $validated['schedule_type'],
            $validated['as_of_date'] ?? null
        );
        $payload['pay_cycle_preview'] = $this->payCycleService->previewForUser($targetUser, $validated['as_of_date'] ?? null);

        return response()->json($payload);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deduction_key' => ['required', 'string', 'max:128'],
            'schedule_type' => ['required', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = array_key_exists('company_id', $validated) && $validated['company_id'] !== null
            ? (int) $validated['company_id']
            : $request->user()->getEffectiveCompanyId();

        $row = $this->deductionScheduleService->upsertSetting(
            $companyId,
            $validated['deduction_key'],
            $validated['schedule_type']
        );

        return response()->json([
            'message' => 'Deduction schedule updated for all employees with this deduction.',
            'setting' => [
                'id' => $row->id,
                'company_id' => $row->company_id,
                'deduction_key' => $row->deduction_key,
                'schedule_type' => $row->schedule_type,
            ],
        ]);
    }

    /**
     * Save multiple deduction schedules at once (same company scope as single update).
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.deduction_key' => ['required', 'string', 'max:128'],
            'settings.*.schedule_type' => ['required', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = array_key_exists('company_id', $validated) && $validated['company_id'] !== null
            ? (int) $validated['company_id']
            : $request->user()->getEffectiveCompanyId();

        $saved = $this->deductionScheduleService->upsertMany($companyId, $validated['settings']);

        return response()->json([
            'message' => 'Deduction schedules saved. They apply to all employees on the next payroll and daily computation run.',
            'updated_count' => count($saved),
        ]);
    }
}
