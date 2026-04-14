<?php

use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Merges duplicate active pay_components rows that share the same normalized code.
 * MySQL allows multiple (code, deleted_at) rows when deleted_at IS NULL — duplicates can slip past the composite unique index.
 * Keeps one canonical row per code (prefer system-protected BASIC_SALARY), re-points assignments, removes duplicate assignment rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        DB::transaction(function (): void {
            $this->mergeDuplicateCodes();
            $this->mergeDuplicateBasicSalaryNames();
        });
    }

    public function down(): void
    {
        // Data migration — not reversible.
    }

    private function mergeDuplicateCodes(): void
    {
        $rows = PayComponent::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'code', 'is_system_protected', 'component_type', 'name']);

        $grouped = $rows->groupBy(fn ($r) => strtoupper(trim((string) ($r->code ?? ''))));

        foreach ($grouped as $code => $group) {
            if ($code === '' || $group->count() < 2) {
                continue;
            }

            $hasComponentType = Schema::hasColumn('pay_components', 'component_type');
            $winner = $group->sortBy([
                fn ($r) => ! ((bool) ($r->is_system_protected ?? false)),
                fn ($r) => $hasComponentType && strtolower((string) ($r->component_type ?? '')) === PayComponent::COMPONENT_SYSTEM ? 0 : 1,
                fn ($r) => (int) $r->id,
            ])->first();

            foreach ($group as $component) {
                if ((int) $component->id === (int) $winner->id) {
                    continue;
                }
                $this->reassignAndRemoveComponent((int) $component->id, (int) $winner->id);
            }
        }
    }

    private function mergeDuplicateBasicSalaryNames(): void
    {
        if (! Schema::hasColumn('pay_components', 'name')) {
            return;
        }

        $canonical = PayComponent::query()
            ->whereNull('deleted_at')
            ->whereRaw("upper(trim(code)) = 'BASIC_SALARY'")
            ->orderByDesc('is_system_protected')
            ->orderBy('id')
            ->first();

        if (! $canonical) {
            return;
        }

        $dupes = PayComponent::query()
            ->whereNull('deleted_at')
            ->where('type', PayComponent::TYPE_EARNING)
            ->whereRaw('lower(trim(name)) = ?', ['basic salary'])
            ->whereRaw('upper(trim(code)) != ?', ['BASIC_SALARY'])
            ->get();

        foreach ($dupes as $dupe) {
            if ((int) $dupe->id === (int) $canonical->id) {
                continue;
            }
            $this->reassignAndRemoveComponent((int) $dupe->id, (int) $canonical->id);
        }
    }

    private function reassignAndRemoveComponent(int $loserId, int $winnerId): void
    {
        if ($loserId === $winnerId) {
            return;
        }

        if (Schema::hasTable('employee_compensation_components')) {
            EmployeeCompensationComponent::query()
                ->where('pay_component_id', $loserId)
                ->update(['pay_component_id' => $winnerId]);

            $this->dedupeEmployeeAssignmentsForPayComponent($winnerId);
        }

        $loser = PayComponent::query()->find($loserId);
        if ($loser) {
            $loser->delete();
        }

        Log::info('pay_components.dedupe_merged', [
            'loser_id' => $loserId,
            'winner_id' => $winnerId,
        ]);
    }

    private function dedupeEmployeeAssignmentsForPayComponent(int $payComponentId): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }

        $grouped = EmployeeCompensationComponent::query()
            ->where('pay_component_id', $payComponentId)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        foreach ($grouped as $rows) {
            if ($rows->count() < 2) {
                continue;
            }

            $keep = $rows->sortByDesc(fn ($r) => (float) ($r->value ?? 0))->first() ?? $rows->first();
            foreach ($rows as $row) {
                if ((int) $row->id === (int) $keep->id) {
                    continue;
                }
                $row->delete();
            }
        }
    }
};
