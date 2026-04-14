<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEmployeesPayCycleForCompaniesJob;
use App\Models\Company;
use App\Models\PayCycle;
use App\Services\DataScopeService;
use App\Services\PayCycleService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayCycleController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly PayCycleService $payCycleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyScope = Company::query()->select('companies.*');
        $this->dataScopeService->restrictCompanyQuery($request->user(), $companyScope);
        $allowedCompanyIds = $companyScope->pluck('companies.id');

        $cycles = PayCycle::query()
            ->with(['company:id,name', 'companies:id,name'])
            ->when($request->filled('company_id'), function ($query) use ($request) {
                $companyId = (int) $request->input('company_id');

                $query->where(function ($builder) use ($companyId) {
                    $builder->where('company_id', $companyId)
                        ->orWhereHas('companies', fn ($companies) => $companies->where('companies.id', $companyId));
                });
            })
            ->when($allowedCompanyIds->isNotEmpty(), function ($query) use ($allowedCompanyIds) {
                $ids = $allowedCompanyIds->all();

                $query->where(function ($builder) use ($ids) {
                    $builder->whereNull('company_id')
                        ->orWhereIn('company_id', $ids)
                        ->orWhereHas('companies', fn ($companies) => $companies->whereIn('companies.id', $ids));
                });
            })
            ->when($this->payCycleService->supportsDefaultFlag(), fn ($query) => $query->orderByDesc('is_default'))
            ->orderBy('name')
            ->get()
            ->map(fn (PayCycle $cycle) => $this->responseRow($cycle))
            ->values();

        return response()->json([
            'data' => $cycles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $companyIds = $this->requestedCompanyIds($validated);
        $companies = $this->resolveCompanies($request, $companyIds);

        $cycle = DB::transaction(function () use ($validated, $companies) {
            $cycle = new PayCycle;
            $cycle->fill($this->normalizedPayload($validated, $companies));
            $cycle->save();

            $this->syncCycleCompanies($cycle, $companies);
            $this->syncCompanyDefaults($cycle, new EloquentCollection, $companies, (bool) ($validated['is_default'] ?? false));

            return $cycle;
        });

        if ($companyIds !== []) {
            SyncEmployeesPayCycleForCompaniesJob::dispatch(array_values(array_unique($companyIds)));
        }

        return response()->json([
            'message' => $companyIds !== [] ? 'Pay cycle created and assigned to selected companies.' : 'Pay cycle created.',
            'data' => $this->responseRow($cycle->fresh(['company', 'companies'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cycle = PayCycle::query()->with(['company', 'companies'])->findOrFail($id);
        $currentCompanies = $this->cycleCompanies($cycle);
        $this->authorizeCycleCompanies($request, $currentCompanies);

        $validated = $this->validatePayload($request, true);
        $nextCompanyIds = array_key_exists('company_ids', $validated) || array_key_exists('company_id', $validated)
            ? $this->requestedCompanyIds($validated)
            : $currentCompanies->pluck('id')->map(fn ($id) => (int) $id)->all();
        $nextCompanies = $this->resolveCompanies($request, $nextCompanyIds);

        $cycle = DB::transaction(function () use ($cycle, $validated, $currentCompanies, $nextCompanies) {
            $payload = $this->normalizedPayload($validated, $nextCompanies, $cycle);

            $cycle->fill($payload);
            $cycle->save();

            $this->syncCycleCompanies($cycle, $nextCompanies);
            $this->syncCompanyDefaults(
                $cycle,
                $currentCompanies,
                $nextCompanies,
                array_key_exists('is_default', $validated) ? (bool) $validated['is_default'] : (bool) $cycle->is_default
            );

            return $cycle;
        });

        $prevCompanyIds = $currentCompanies->pluck('id')->map(fn ($cid) => (int) $cid)->all();
        $toSync = array_values(array_unique(array_merge($prevCompanyIds, $nextCompanyIds)));
        if ($toSync !== []) {
            SyncEmployeesPayCycleForCompaniesJob::dispatch($toSync);
        }

        return response()->json([
            'message' => 'Pay cycle updated.',
            'data' => $this->responseRow($cycle->fresh(['company', 'companies'])),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $cycle = PayCycle::query()->with(['company', 'companies'])->findOrFail($id);
        $companies = $this->cycleCompanies($cycle);
        $this->authorizeCycleCompanies($request, $companies);

        $companyIdsBeforeDelete = $companies->pluck('id')->map(fn ($cid) => (int) $cid)->all();

        DB::transaction(function () use ($cycle, $companies, $id) {
            Company::query()
                ->whereIn('id', $companies->pluck('id'))
                ->where('default_pay_cycle_id', $id)
                ->update(['default_pay_cycle_id' => null]);

            $cycle->companies()->detach();
            $cycle->delete();
        });

        if ($companyIdsBeforeDelete !== []) {
            SyncEmployeesPayCycleForCompaniesJob::dispatch($companyIdsBeforeDelete);
        }

        return response()->json(['message' => 'Pay cycle deleted.']);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'cut_off_type' => ['required', 'string'],
            'cut_off_value' => ['nullable', 'array'],
            'pay_day_type' => ['required', 'string'],
            'pay_day_value' => ['nullable', 'array'],
            'pay_day_offset' => ['nullable', 'integer', 'min:0', 'max:60'],
            'pro_ration_type' => ['nullable', 'string'],
            'reference_date' => ['nullable', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $cycle = new PayCycle($this->normalizedPayload($validated + [
            'company_id' => null,
            'name' => $validated['name'] ?? ucfirst(str_replace('_', ' ', (string) $validated['code'])),
        ], new EloquentCollection));

        return response()->json([
            'data' => $this->payCycleService->buildCyclePreview(
                $cycle,
                Carbon::parse((string) ($validated['reference_date'] ?? now()->toDateString()))
            ),
        ]);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['integer', 'distinct', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:255'],
            'code' => [$required, 'string', Rule::in([
                PayCycle::CODE_MONTHLY,
                PayCycle::CODE_SEMI_MONTHLY,
                PayCycle::CODE_WEEKLY,
                PayCycle::CODE_BI_WEEKLY,
                PayCycle::CODE_DAILY,
                PayCycle::CODE_PROJECT,
            ])],
            'cut_off_type' => [$required, 'string', Rule::in([
                PayCycle::CUT_OFF_FIXED_DAY,
                PayCycle::CUT_OFF_DAY_OF_WEEK,
                PayCycle::CUT_OFF_CUSTOM,
            ])],
            'cut_off_value' => ['nullable', 'array'],
            'pay_day_type' => [$required, 'string', Rule::in([
                PayCycle::PAY_DAY_OFFSET,
                PayCycle::PAY_DAY_FIXED_DAY,
                PayCycle::PAY_DAY_CUSTOM,
            ])],
            'pay_day_value' => ['nullable', 'array'],
            'pay_day_offset' => ['nullable', 'integer', 'min:0', 'max:60'],
            'pro_ration_type' => ['nullable', 'string', Rule::in([
                PayCycle::PRORATION_NONE,
                PayCycle::PRORATION_DAILY,
                PayCycle::PRORATION_HOURLY,
            ])],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => [$this->payCycleService->supportsDefaultFlag() ? 'nullable' : 'sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizedPayload(array $payload, EloquentCollection $companies, ?PayCycle $existingCycle = null): array
    {
        $primaryCompanyId = $companies->pluck('id')->map(fn ($id) => (int) $id)->first()
            ?? ($payload['company_id'] ?? $existingCycle?->company_id);

        return [
            'company_id' => $primaryCompanyId,
            'name' => isset($payload['name']) ? trim((string) $payload['name']) : null,
            'code' => isset($payload['code']) ? (string) $payload['code'] : null,
            'cut_off_type' => isset($payload['cut_off_type']) ? (string) $payload['cut_off_type'] : PayCycle::CUT_OFF_FIXED_DAY,
            'cut_off_value' => $payload['cut_off_value'] ?? null,
            'pay_day_type' => isset($payload['pay_day_type']) ? (string) $payload['pay_day_type'] : PayCycle::PAY_DAY_OFFSET,
            'pay_day_value' => $payload['pay_day_value'] ?? null,
            'pay_day_offset' => $payload['pay_day_offset'] ?? null,
            'pro_ration_type' => (string) ($payload['pro_ration_type'] ?? PayCycle::PRORATION_NONE),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'is_default' => $this->payCycleService->supportsDefaultFlag()
                ? (bool) ($payload['is_default'] ?? false)
                : false,
            'metadata' => $payload['metadata'] ?? null,
        ];
    }

    private function resolveCompany(Request $request, int $companyId): Company
    {
        $query = Company::query()->whereKey($companyId);
        $this->dataScopeService->restrictCompanyQuery($request->user(), $query);

        return $query->firstOrFail();
    }

    /**
     * @param  list<int>  $companyIds
     * @return EloquentCollection<int, Company>
     */
    private function resolveCompanies(Request $request, array $companyIds): EloquentCollection
    {
        if ($companyIds === []) {
            return new EloquentCollection;
        }

        $query = Company::query()->whereIn('id', $companyIds);
        $this->dataScopeService->restrictCompanyQuery($request->user(), $query);
        $companies = $query->get();

        abort_if($companies->count() !== count(array_unique($companyIds)), 404);

        return $companies;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function requestedCompanyIds(array $validated): array
    {
        $companyIds = collect($validated['company_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($companyIds->isEmpty() && ! empty($validated['company_id'])) {
            $companyIds = collect([(int) $validated['company_id']]);
        }

        return $companyIds->all();
    }

    /**
     * @return EloquentCollection<int, Company>
     */
    private function cycleCompanies(PayCycle $cycle): EloquentCollection
    {
        $companies = $cycle->relationLoaded('companies') ? $cycle->companies : $cycle->companies()->get();

        if ($companies->isEmpty() && $cycle->company) {
            return new EloquentCollection([$cycle->company]);
        }

        return $companies;
    }

    /**
     * @param  EloquentCollection<int, Company>  $companies
     */
    private function authorizeCycleCompanies(Request $request, EloquentCollection $companies): void
    {
        if ($companies->isEmpty()) {
            return;
        }

        $allowedIds = $this->resolveCompanies($request, $companies->pluck('id')->map(fn ($id) => (int) $id)->all())->pluck('id')->all();
        abort_if(count($allowedIds) !== $companies->count(), 404);
    }

    /**
     * @param  EloquentCollection<int, Company>  $companies
     */
    private function syncCycleCompanies(PayCycle $cycle, EloquentCollection $companies): void
    {
        $cycle->companies()->sync($companies->pluck('id')->all());
    }

    /**
     * @param  EloquentCollection<int, Company>  $previousCompanies
     * @param  EloquentCollection<int, Company>  $nextCompanies
     */
    private function syncCompanyDefaults(PayCycle $cycle, EloquentCollection $previousCompanies, EloquentCollection $nextCompanies, bool $isDefault): void
    {
        if (! $this->payCycleService->supportsDefaultFlag()) {
            return;
        }

        $previousIds = $previousCompanies->pluck('id')->map(fn ($id) => (int) $id)->all();
        $nextIds = $nextCompanies->pluck('id')->map(fn ($id) => (int) $id)->all();
        $removedIds = array_values(array_diff($previousIds, $nextIds));

        if ($removedIds !== []) {
            Company::query()
                ->whereIn('id', $removedIds)
                ->where('default_pay_cycle_id', $cycle->id)
                ->update(['default_pay_cycle_id' => null]);
        }

        if (! $isDefault) {
            Company::query()
                ->whereIn('id', $nextIds)
                ->where('default_pay_cycle_id', $cycle->id)
                ->update(['default_pay_cycle_id' => null]);

            return;
        }

        if ($nextIds !== []) {
            Company::query()
                ->whereIn('id', $nextIds)
                ->update(['default_pay_cycle_id' => $cycle->id]);
        }
    }

    private function responseRow(PayCycle $cycle): array
    {
        $companies = $this->cycleCompanies($cycle);

        return [
            'id' => $cycle->id,
            'company_id' => $cycle->company_id,
            'company_name' => $cycle->company?->name,
            'company_ids' => $companies->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'company_names' => $companies->pluck('name')->filter()->values()->all(),
            'name' => $cycle->name,
            'code' => $cycle->code,
            'cut_off_type' => $cycle->cut_off_type,
            'cut_off_value' => $cycle->cut_off_value,
            'pay_day_type' => $cycle->pay_day_type,
            'pay_day_value' => $cycle->pay_day_value,
            'pay_day_offset' => $cycle->pay_day_offset,
            'pro_ration_type' => $cycle->pro_ration_type,
            'metadata' => $cycle->metadata,
            'weekend_adjustment_rule' => (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY),
            'is_active' => (bool) $cycle->is_active,
            'is_default' => $this->payCycleService->supportsDefaultFlag() ? (bool) $cycle->is_default : false,
            'preview' => $this->payCycleService->buildCyclePreview($cycle, now()),
        ];
    }
}
