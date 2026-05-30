<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Support\BulkPayrollDraftContext;
use App\Models\Company;
use App\Models\ExecomEmployeeProfile;
use App\Models\ExecomPayrollSetting;
use App\Models\EmployeeGovernmentIdDocument;
use App\Models\PayCycle;
use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use setasign\Fpdi\Tcpdf\Fpdi;
use Throwable;

/**
 * Payslip generation — single pipeline from HR master data to PDF.
 *
 * Integration chain (all reads flow through {@see PayrollComputationService::computeEmployeePayroll}):
 * - **Pay components & assignments** — resolved inside {@see PayrollCalculatorService} / rules engine from employee compensation.
 * - **Automated deductions & loans** — {@see DeductionApplicationService} / loan amortization in payroll calculator snapshot (`summary`).
 * - **Government deductions (SSS, PhilHealth, Pag-IBIG, WHT)** — {@see PayrollCalculatorService} statutory split; schedule alignment via {@see DeductionScheduleService}.
 * - **Pay cycles & cut-offs** — {@see PayCycleService::resolveForUser} / {@see PayCycleService::buildCyclePreview}; dates drive the `$from`–`to` window passed to daily computation.
 * - **Schedules & attendance** — {@see PayrollComputationService} uses {@see ScheduleRateService}, attendance logs, holidays, OT/ND per day (same engine as Admin Daily Computation).
 * - **Daily computation** — never recomputed differently for payslip; one `computeEmployeePayroll()` call per period builds `snapshot.summary` stored on the payslip.
 *
 * Self-service: persisted payslips are listed under {@see \App\Http\Controllers\EmployeePayslipController} (own `user_id` only).
 *
 * RBAC: only Laravel `admin` role may call generation from HTTP — enforced in {@see \App\Http\Controllers\Admin\PayslipController}.
 */
class PayslipService
{
    private const DRAFT_GENERATION_CHUNK_SIZE = 50;

    /** @var list<string>|null */
    private static ?array $payrollBatchRunColumns = null;

    /** @var array<string, bool> */
    private static array $orgForeignKeyExistsCache = [];

    public function __construct(
        private readonly BrowsershotEnvironment $browsershotEnvironment,
        private readonly PayrollComputationService $payrollComputation,
        private readonly ExecomPayrollComputationService $execomPayrollComputation,
        private readonly PayCycleService $payCycleService,
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollEmployeeEligibilityService $payrollEligibility,
    ) {}

    /**
     * YTD balances for the calendar year of $periodEnd, excluding the current period (caller adds current).
     *
     * @return array{ytd_gross: float, ytd_deductions: float, ytd_tax: float}
     */
    public function calculateYtd(User $employee, Carbon $periodStart): array
    {
        $yearStart = Carbon::create((int) $periodStart->year, 1, 1)->startOfDay();

        $prior = Payslip::query()
            ->where('user_id', $employee->id)
            ->whereDate('pay_period_end', '>=', $yearStart->toDateString())
            ->whereDate('pay_period_end', '<', $periodStart->toDateString())
            ->orderBy('pay_period_end')
            ->get(['gross_pay', 'total_deductions', 'snapshot']);

        $ytdGross = (float) $prior->sum('gross_pay');
        $ytdDed = (float) $prior->sum('total_deductions');
        $ytdTax = 0.0;
        foreach ($prior as $p) {
            $ytdTax += (float) data_get($p->snapshot, 'summary.withholding_tax_this_period_estimate', 0);
        }

        return [
            'ytd_gross' => round($ytdGross, 2),
            'ytd_deductions' => round($ytdDed, 2),
            'ytd_tax' => round($ytdTax, 2),
        ];
    }

    /**
     * Batch-load YTD totals for many employees in one query (draft payroll generation hot path).
     *
     * @param  list<int>  $userIds
     * @return array<int, array{ytd_gross: float, ytd_deductions: float, ytd_tax: float}>
     */
    public function bulkYtdPriorBalances(array $userIds, Carbon $periodStart): array
    {
        $byUser = [];
        foreach ($userIds as $uid) {
            $byUser[(int) $uid] = ['ytd_gross' => 0.0, 'ytd_deductions' => 0.0, 'ytd_tax' => 0.0];
        }
        if ($userIds === []) {
            return $byUser;
        }

        $yearStart = Carbon::create((int) $periodStart->year, 1, 1)->startOfDay();
        $prior = Payslip::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('pay_period_end', '>=', $yearStart->toDateString())
            ->whereDate('pay_period_end', '<', $periodStart->toDateString())
            ->orderBy('pay_period_end')
            ->get(['user_id', 'gross_pay', 'total_deductions', 'snapshot']);

        foreach ($prior as $p) {
            $uid = (int) $p->user_id;
            if (! isset($byUser[$uid])) {
                continue;
            }
            $byUser[$uid]['ytd_gross'] += (float) $p->gross_pay;
            $byUser[$uid]['ytd_deductions'] += (float) $p->total_deductions;
            $snap = $p->snapshot;
            if (is_string($snap)) {
                $snap = json_decode($snap, true);
            }
            if (is_array($snap)) {
                $byUser[$uid]['ytd_tax'] += (float) data_get($snap, 'summary.withholding_tax_this_period_estimate', 0);
            }
        }

        foreach ($byUser as &$row) {
            $row['ytd_gross'] = round($row['ytd_gross'], 2);
            $row['ytd_deductions'] = round($row['ytd_deductions'], 2);
            $row['ytd_tax'] = round($row['ytd_tax'], 2);
        }
        unset($row);

        return $byUser;
    }

    /**
     * @param  array<string, mixed>  $input
     *                                       from_date, to_date, pay_cycle_id?, reference_date?, payroll_period_id?,
     *                                       is_final_pay?, password_protect?
     * @param  bool  $withPdf  When false, persist the payslip row + snapshot but skip PDF generation (fast, non-blocking UI).
     * @return array{payslip: Payslip, pdf_password: ?string}
     */
    public function generatePayslip(User $employee, array $input, bool $withPdf = true): array
    {
        $gen = $this->computePayslipGenerationData($employee, $input, true);
        $plainPassword = $gen['plain_password'];

        $payslip = DB::transaction(function () use ($employee, $gen, $plainPassword, $withPdf) {
            /** @var Payslip $payslip */
            $payslip = $this->upsertPayslipRow($gen['unique'], $gen['attributes']);
            if ($withPdf) {
                $pdfPath = $this->generatePdf($payslip->fresh(), $employee->fresh(), $plainPassword);
                $payslip->update([
                    'pdf_path' => $pdfPath,
                    'pdf_password_protected' => $plainPassword !== null,
                ]);
            }

            return $payslip->fresh();
        });

        return [
            'payslip' => $payslip,
            'pdf_password' => $plainPassword,
        ];
    }

    /**
     * Persist a payslip from payroll data already computed by finalize/payroll flows.
     *
     * This avoids recomputing attendance, premiums, deductions, tax, and contributions immediately
     * after payroll computation has produced the same summary.
     *
     * @param  array<string, mixed>  $computed
     * @param  array<string, mixed>|null  $preview
     * @return array{payslip: Payslip, pdf_password: ?string}
     */
    public function generatePayslipFromComputedPayroll(
        User $employee,
        array $input,
        array $computed,
        ?array $preview = null,
        ?PayCycle $cycle = null,
        bool $withPdf = false
    ): array {
        $gen = $this->computePayslipGenerationData($employee, $input, true, $computed, $preview, $cycle);
        $plainPassword = $gen['plain_password'];

        $payslip = DB::transaction(function () use ($employee, $gen, $plainPassword, $withPdf) {
            /** @var Payslip $payslip */
            $payslip = $this->upsertPayslipRow($gen['unique'], $gen['attributes']);
            if ($withPdf) {
                $pdfPath = $this->generatePdf($payslip->fresh(), $employee->fresh(), $plainPassword);
                $payslip->update([
                    'pdf_path' => $pdfPath,
                    'pdf_password_protected' => $plainPassword !== null,
                ]);
            }

            return $payslip->fresh();
        });

        return [
            'payslip' => $payslip,
            'pdf_password' => $plainPassword,
        ];
    }

    /**
     * Link a payslip to a payroll period while respecting {@code pg_payslips_user_period_unique}.
     * Supersedes duplicate draft rows that already hold the same period link.
     */
    public function assignPayrollPeriodId(Payslip $payslip, int $payrollPeriodId): Payslip
    {
        $payrollPeriodId = (int) $payrollPeriodId;
        if ($payrollPeriodId <= 0) {
            return $payslip;
        }

        if ((int) ($payslip->payroll_period_id ?? 0) === $payrollPeriodId) {
            return $payslip;
        }

        return DB::transaction(function () use ($payslip, $payrollPeriodId): Payslip {
            /** @var Payslip $locked */
            $locked = Payslip::query()->whereKey($payslip->id)->lockForUpdate()->firstOrFail();
            $this->releaseConflictingPayrollPeriodLinks($locked, $payrollPeriodId);

            try {
                $locked->forceFill(['payroll_period_id' => $payrollPeriodId])->save();
            } catch (QueryException $e) {
                if (! $this->isDuplicateUserPayrollPeriodException($e)) {
                    throw $e;
                }
                $this->forceReleasePayrollPeriodSlot((int) $locked->user_id, $payrollPeriodId, (int) $locked->id);
                $locked->forceFill(['payroll_period_id' => $payrollPeriodId])->save();
            }

            return $locked->fresh();
        });
    }

    /**
     * Clear payroll_period_id from voided/archived payslips that still block pg_payslips_user_period_unique.
     *
     * @param  list<int>  $userIds
     */
    public function clearBlockingPayrollPeriodLinksForUsers(array $userIds): int
    {
        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($ids === []) {
            return 0;
        }

        return Payslip::query()
            ->whereIn('user_id', $ids)
            ->whereNotNull('payroll_period_id')
            ->where(function ($q): void {
                $q->where('status', Payslip::STATUS_VOIDED)
                    ->orWhereNotNull('voided_at');
            })
            ->update(['payroll_period_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $unique
     * @param  array<string, mixed>  $attributes
     */
    private function upsertPayslipRow(array $unique, array $attributes): Payslip
    {
        $periodId = isset($attributes['payroll_period_id']) ? (int) $attributes['payroll_period_id'] : 0;
        $attrs = $attributes;
        if ($periodId > 0) {
            unset($attrs['payroll_period_id']);
        }
        $attrs['period_slot'] = (int) ($attrs['period_slot'] ?? 0);

        $lookup = array_merge($unique, ['period_slot' => 0]);

        /** @var Payslip|null $payslip */
        $payslip = Payslip::query()
            ->where($lookup)
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->orderByDesc('id')
            ->first();

        if ($payslip instanceof Payslip) {
            $payslip->fill($attrs);
            $payslip->save();
        } else {
            $payslip = Payslip::query()->create(array_merge($lookup, $attrs));
        }

        if ($periodId > 0) {
            return $this->assignPayrollPeriodId($payslip, $periodId);
        }

        return $payslip;
    }

    private function releaseConflictingPayrollPeriodLinks(Payslip $keeper, int $payrollPeriodId): void
    {
        $userId = (int) $keeper->user_id;
        $keeperId = (int) $keeper->id;

        $this->forceReleasePayrollPeriodSlot($userId, $payrollPeriodId, $keeperId);

        // Voided rows are excluded from active conflict scans but still occupy pg_payslips_user_period_unique.
        $voidedCleared = Payslip::query()
            ->where('user_id', $userId)
            ->where('payroll_period_id', $payrollPeriodId)
            ->whereKeyNot($keeperId)
            ->where(function ($q): void {
                $q->where('status', Payslip::STATUS_VOIDED)
                    ->orWhereNotNull('voided_at');
            })
            ->update(['payroll_period_id' => null]);

        if ($voidedCleared > 0) {
            Log::info('payslip: cleared payroll_period_id from voided duplicates', [
                'keeper_payslip_id' => (int) $keeper->id,
                'user_id' => $userId,
                'payroll_period_id' => $payrollPeriodId,
                'rows_cleared' => $voidedCleared,
            ]);
        }

        $conflicts = Payslip::query()
            ->where('user_id', $userId)
            ->where('payroll_period_id', $payrollPeriodId)
            ->whereKeyNot($keeper->id)
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->orderByDesc('id')
            ->get();

        foreach ($conflicts as $conflict) {
            if ($this->shouldArchivePayrollPeriodConflict($keeper, $conflict)) {
                $this->archiveSupersededPayslip($conflict);

                continue;
            }

            throw new \RuntimeException(
                'Payslip '.(int) $conflict->id.' ('.$conflict->status.') already uses payroll period '.$payrollPeriodId
                .' for user_id='.$userId.'. Payslip '.$keeperId.' cannot be linked.'
                .' Run: php artisan payroll:repair-duplicate-rows'
            );
        }

        $this->forceReleasePayrollPeriodSlot($userId, $payrollPeriodId, $keeperId);
    }

    private function forceReleasePayrollPeriodSlot(int $userId, int $payrollPeriodId, int $keeperId): void
    {
        Payslip::query()
            ->where('user_id', $userId)
            ->where('payroll_period_id', $payrollPeriodId)
            ->where('id', '!=', $keeperId)
            ->update(['payroll_period_id' => null]);
    }

    private function isDuplicateUserPayrollPeriodException(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'pg_payslips_user_period_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, 'payroll_period'));
    }

    private function shouldArchivePayrollPeriodConflict(Payslip $keeper, Payslip $conflict): bool
    {
        if (in_array((string) $conflict->status, Payslip::lockingStatuses(), true)) {
            return false;
        }

        // Any superseded draft/generated row holding the same payroll_period_id must be archived.
        return true;
    }

    private function archiveSupersededPayslip(Payslip $duplicate): void
    {
        Log::info('payslip: archiving superseded duplicate before payroll period link', [
            'payslip_id' => (int) $duplicate->id,
            'user_id' => (int) $duplicate->user_id,
            'payroll_period_id' => $duplicate->payroll_period_id,
            'pay_period_start' => $duplicate->pay_period_start?->toDateString(),
            'pay_period_end' => $duplicate->pay_period_end?->toDateString(),
            'status' => (string) $duplicate->status,
        ]);

        $duplicate->forceFill([
            'status' => Payslip::STATUS_VOIDED,
            'voided_at' => now(),
            'period_slot' => (int) $duplicate->id,
            'payroll_period_id' => null,
            'is_sent' => false,
            'delivered_at' => null,
            'sent_at' => null,
        ])->save();
    }

    /**
     * Release a payslip to employee self-service (My Payslips). Accepts any {@see Payslip::lockingStatuses()}
     * value (published payslip), including legacy {@see Payslip::STATUS_VIEWED} after an employee opened a PDF.
     *
     * @return array{ok: true}|array{ok: false, reason: string}
     */
    public function sendPayslip(
        int $payslipId,
        User $actor,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId
    ): array {
        $payslip = Payslip::query()->with('employee')->find($payslipId);
        if (! $payslip) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $employee = $payslip->employee;
        if (! $employee instanceof User) {
            return ['ok' => false, 'reason' => 'no_employee'];
        }

        $allowedUserQuery = User::query()->payrollEmployees()->active();
        $this->dataScopeService->restrictEmployeeQuery($actor, $allowedUserQuery);
        if ($companyId) {
            $allowedUserQuery->where('company_id', $companyId);
        }
        if ($branchId) {
            $allowedUserQuery->where('branch_id', $branchId);
        }
        if ($departmentId) {
            $allowedUserQuery->where('department_id', $departmentId);
        }
        $allowedUserIds = array_flip($allowedUserQuery->pluck('id')->map(fn ($id) => (int) $id)->all());
        $uid = (int) $payslip->user_id;
        if (! isset($allowedUserIds[$uid])) {
            return ['ok' => false, 'reason' => 'out_of_scope'];
        }

        $st = strtolower(trim((string) ($payslip->status ?? '')));
        $published = array_map(static fn (string $x): string => strtolower($x), Payslip::lockingStatuses());
        if (! in_array($st, $published, true)) {
            return ['ok' => false, 'reason' => 'not_finalized'];
        }

        if (! $payslip->pdf_path) {
            try {
                $this->ensurePayslipPdfOnDisk($payslip, $employee);
                $payslip->refresh();
            } catch (Throwable $e) {
                Log::error('Payslip send: PDF generation failed', [
                    'payslip_id' => (int) $payslip->id,
                    'user_id' => (int) $employee->id,
                    'message' => $e->getMessage(),
                ]);

                return ['ok' => false, 'reason' => 'pdf_generation_failed'];
            }
        }

        $full = storage_path('app/private/'.$payslip->pdf_path);
        if (! is_file($full)) {
            try {
                $this->ensurePayslipPdfOnDisk($payslip, $employee, true);
                $payslip->refresh();
                $full = storage_path('app/private/'.$payslip->pdf_path);
            } catch (Throwable $e) {
                Log::error('Payslip send: missing PDF regeneration failed', [
                    'payslip_id' => (int) $payslip->id,
                    'user_id' => (int) $employee->id,
                    'message' => $e->getMessage(),
                ]);

                return ['ok' => false, 'reason' => 'pdf_missing'];
            }

            if (! is_file($full)) {
                return ['ok' => false, 'reason' => 'pdf_missing'];
            }
        }

        $now = now();
        $payslip->update([
            'delivered_at' => $now,
            'sent_at' => $now,
            'is_sent' => true,
            'status' => Payslip::STATUS_SENT_FINALIZED,
        ]);

        return ['ok' => true];
    }

    /**
     * Finalize payslips for one persisted payroll period id (company-scoped).
     *
     * @param  int[]|null  $employeeIds  Optional scoped employees for branch/department runs.
     */
    public function finalizePayrollPeriod(int $companyId, int $payPeriodId, ?array $employeeIds = null, ?int $finalizedByUserId = null): int
    {
        $scopedEmployeeIds = is_array($employeeIds) ? array_values(array_unique(array_map('intval', $employeeIds))) : null;
        $payslips = Payslip::query()
            ->where('payroll_period_id', $payPeriodId);
        if (is_array($scopedEmployeeIds) && count($scopedEmployeeIds) > 0) {
            $payslips->whereIn('user_id', $scopedEmployeeIds);
        } else {
            $payslips->where('company_id', $companyId);
        }
        $updated = $payslips->update([
            'status' => Payslip::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by_user_id' => $finalizedByUserId,
        ]);

        $periodQuery = PayrollPeriod::query()->where('id', $payPeriodId);
        if (is_array($scopedEmployeeIds) && count($scopedEmployeeIds) > 0) {
            $periodQuery->whereIn('user_id', $scopedEmployeeIds);
        }
        $periodQuery->update(['status' => PayrollPeriod::STATUS_LOCKED]);

        return $updated;
    }

    /**
     * Promote explicit draft payslip rows to finalized (batch-safe; does not rely on company scope).
     *
     * @param  list<int>  $payslipIds
     */
    public function finalizePayslipsByIds(array $payslipIds, ?int $finalizedByUserId = null, ?int $payrollBatchRunId = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $payslipIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $query = Payslip::query()
            ->whereIn('id', $ids)
            ->whereNull('voided_at')
            ->whereNotIn('status', Payslip::lockingStatuses());

        if ($payrollBatchRunId !== null && $payrollBatchRunId > 0) {
            $query->where('payroll_batch_run_id', $payrollBatchRunId);
        }

        return (int) $query->update([
            'status' => Payslip::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by_user_id' => $finalizedByUserId,
        ]);
    }

    /**
     * Finalize all payslips in a company period window and lock related payroll periods.
     *
     * @param  int[]|null  $employeeIds  Optional employee scope (company/branch/department filtered list).
     * @return int Updated payslip rows
     */
    public function finalizePayrollWindow(
        ?int $companyId,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        ?int $periodId = null,
        ?array $employeeIds = null,
        ?int $finalizedByUserId = null,
        ?string $payrollModule = null
    ): int {
        $start = $periodStart instanceof Carbon ? $periodStart->toDateString() : Carbon::parse((string) $periodStart)->toDateString();
        $end = $periodEnd instanceof Carbon ? $periodEnd->toDateString() : Carbon::parse((string) $periodEnd)->toDateString();
        $scopedEmployeeIds = is_array($employeeIds) ? array_values(array_unique(array_map('intval', $employeeIds))) : null;
        $hasEmployeeScope = is_array($scopedEmployeeIds) && count($scopedEmployeeIds) > 0;
        $hasCompanyScope = $companyId !== null && $companyId > 0;

        if (! $hasEmployeeScope && ! $hasCompanyScope) {
            return 0;
        }

        $payslips = Payslip::query()
            ->whereDate('pay_period_start', $start)
            ->whereDate('pay_period_end', $end)
            ->whereNull('voided_at')
            ->whereNotIn('status', Payslip::lockingStatuses());
        if ($periodId !== null) {
            $payslips->where('payroll_period_id', $periodId);
        }
        if ($payrollModule !== null && trim($payrollModule) !== '') {
            $payslips->where('payroll_module', $this->normalizePayrollModule($payrollModule));
        }
        if ($hasEmployeeScope) {
            $payslips->whereIn('user_id', $scopedEmployeeIds);
        } else {
            $payslips->where('company_id', (int) $companyId);
        }

        $updated = $payslips->update([
            'status' => Payslip::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by_user_id' => $finalizedByUserId,
        ]);

        $periods = PayrollPeriod::query()
            ->whereDate('from_date', $start)
            ->whereDate('to_date', $end);
        if ($periodId !== null) {
            $periods->where('id', $periodId);
        }
        if ($hasEmployeeScope) {
            $periods->whereIn('user_id', $scopedEmployeeIds);
        } elseif ($hasCompanyScope) {
            $periods->whereHas('user', fn ($q) => $q->where('company_id', (int) $companyId));
        }
        $periods->update(['status' => PayrollPeriod::STATUS_LOCKED]);

        return $updated;
    }

    /**
     * Block payroll mutations when this employee's overlapping pay window is locked or already finalized on payslip.
     *
     * @throws \RuntimeException
     */
    public function assertPayrollPeriodMutableForUserWindow(int $userId, Carbon $from, Carbon $to): void
    {
        PayrollPeriodLock::assertMutableForUserWindow($userId, $from, $to);
    }

    /**
     * Build a sample PDF for the first active employee in scope — same computation as bulk generate, no DB write.
     * Used by admin “preview” to validate pay cycle + period before running bulk.
     *
     * @param  array<string, mixed>  $periodInput  Same keys as {@see generateBulkPayslips} / generate POST body.
     * @return array{relative_path: string, pdf_password: ?string}
     */
    public function previewSamplePdfForScope(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        array $periodInput,
        ?User $actor = null
    ): array {
        $q = User::query()->payrollEmployees()->active();

        if ($actor !== null) {
            $this->dataScopeService->restrictEmployeeQuery($actor, $q);
        }

        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        if ($departmentId) {
            $q->where('department_id', $departmentId);
        }

        $employee = $q->orderBy('id')->first();
        if ($employee === null) {
            throw new \RuntimeException('No active employee in the selected scope.');
        }

        $gen = $this->computePayslipGenerationData($employee, $periodInput);

        $payslip = new Payslip;
        $payslip->forceFill(array_merge($gen['unique'], $gen['attributes']));
        $payslip->exists = false;

        $token = 'preview-'.Str::uuid()->toString();
        $relative = 'payslips/previews/'.$token.'/payslip.pdf';
        $this->generatePdf($payslip, $employee->fresh(), $gen['plain_password'], $relative);

        return [
            'relative_path' => $relative,
            'pdf_password' => $gen['plain_password'],
        ];
    }

    /**
     * Preview a payslip PDF for a specific employee — same pipeline as generate, **no DB write**.
     *
     * @param  array<string, mixed>  $periodInput
     * @return array{relative_path: string, pdf_password: ?string}
     */
    public function previewPdfForEmployee(User $employee, array $periodInput): array
    {
        $gen = $this->computePayslipGenerationData($employee, $periodInput);

        $payslip = new Payslip;
        $payslip->forceFill(array_merge($gen['unique'], $gen['attributes']));
        $payslip->exists = false;

        $token = 'preview-'.Str::uuid()->toString();
        $relative = 'payslips/previews/'.$token.'/payslip.pdf';
        $this->generatePdf($payslip, $employee->fresh(), $gen['plain_password'], $relative);

        return [
            'relative_path' => $relative,
            'pdf_password' => $gen['plain_password'],
        ];
    }

    /**
     * Structured preview payload for UI modal (same computation as PDF; no DB write).
     *
     * @param  array<string, mixed>  $periodInput
     * @return array<string, mixed>
     */
    /**
     * Human-readable employment status for payslips (aligned with {@see EmploymentStatus} labels).
     */
    public function employmentStatusLabelForPayslip(User $employee): ?string
    {
        $label = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        $raw = $employee->employment_status;

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Government ID numbers for payslip display: registry row ({@see \App\Models\EmployeeGovernmentId})
     * plus fallback from latest approved {@see EmployeeGovernmentIdDocument} per ID type (profile upload flow).
     *
     * @return array{tin_number: ?string, sss_number: ?string, philhealth_number: ?string, pagibig_number: ?string}
     */
    public function governmentIdFieldsForPayslip(User $employee): array
    {
        $employee->loadMissing('governmentIds');
        $row = $employee->governmentIds;

        $tin = $row?->tin_number;
        $sss = $row?->sss_number;
        $phil = $row?->philhealth_number;
        $pagibig = $row?->pagibig_number;

        $approved = EmployeeGovernmentIdDocument::query()
            ->where('user_id', $employee->id)
            ->where('status', 'approved')
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->get(['id_type', 'id_number']);

        foreach ($approved as $doc) {
            $key = $this->mapGovernmentDocumentTypeToPayslipKey((string) $doc->id_type);
            $num = trim((string) $doc->id_number);
            if ($key === null || $num === '') {
                continue;
            }
            match ($key) {
                'tin' => $tin = $tin ?: $num,
                'sss' => $sss = $sss ?: $num,
                'philhealth' => $phil = $phil ?: $num,
                'pagibig' => $pagibig = $pagibig ?: $num,
                default => null,
            };
        }

        return [
            'tin_number' => $tin !== null && trim((string) $tin) !== '' ? trim((string) $tin) : null,
            'sss_number' => $sss !== null && trim((string) $sss) !== '' ? trim((string) $sss) : null,
            'philhealth_number' => $phil !== null && trim((string) $phil) !== '' ? trim((string) $phil) : null,
            'pagibig_number' => $pagibig !== null && trim((string) $pagibig) !== '' ? trim((string) $pagibig) : null,
        ];
    }

    /**
     * Map document `id_type` (e.g. "TIN ID", "SSS ID / UMID") to payslip column keys.
     */
    private function mapGovernmentDocumentTypeToPayslipKey(string $idType): ?string
    {
        $t = mb_strtolower(trim($idType));
        if ($t === '') {
            return null;
        }
        if (str_contains($t, 'tin')) {
            return 'tin';
        }
        if (str_contains($t, 'philhealth')) {
            return 'philhealth';
        }
        if (str_contains($t, 'pag-ibig') || str_contains($t, 'pagibig') || str_contains($t, 'hdmf')) {
            return 'pagibig';
        }
        if (str_contains($t, 'sss') || str_contains($t, 'umid')) {
            return 'sss';
        }

        return null;
    }

    public function employmentTypeLabelForPayslip(User $employee): ?string
    {
        $value = strtolower(trim(str_replace(['-', ' '], '_', (string) ($employee->employment_type ?? ''))));

        return match ($value) {
            'consultant' => 'Consultant',
            'full_time' => 'Full-time',
            'part_time' => 'Part-time',
            'contract' => 'Contract',
            'probationary' => 'Probationary',
            default => $value !== '' ? Str::headline(str_replace('_', ' ', $value)) : null,
        };
    }

    public function previewDataForEmployee(User $employee, array $periodInput): array
    {
        $gen = $this->computePayslipGenerationData($employee, $periodInput);
        $attrs = $gen['attributes'];
        $snapshotRaw = is_array($attrs['snapshot'] ?? null) ? $attrs['snapshot'] : [];
        // Keep preview data on the same normalization path used by PDF generation.
        // This guarantees identical Units/Amount rendering between modal preview and PDF output.
        $snapshot = $this->normalizeSnapshotForPayslipPdf($snapshotRaw);
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $dailyEarningLines = is_array($summary['daily_computation_earning_lines'] ?? null)
            ? array_values($summary['daily_computation_earning_lines'])
            : [];
        $isExecomPreview = $this->isExecomSnapshot($snapshot, $summary);
        $isConsultantPreview = $this->isConsultantSnapshot($snapshot, $summary);
        if ($isExecomPreview) {
            $dailyEarningLines = [];
        } elseif ($isConsultantPreview) {
            $dailyEarningLines = [];
        } elseif (count($dailyEarningLines) === 0) {
            $regularPay = (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0));
            $attendancePremium = (float) ($summary['attendance_premium_pay_this_period'] ?? 0);
            if ($regularPay > 0) {
                $dailyEarningLines[] = [
                    'key' => 'fallback:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => round($regularPay, 2),
                ];
            }
            if ($attendancePremium > 0) {
                $dailyEarningLines[] = [
                    'key' => 'fallback:attendance_premium',
                    'label' => 'Attendance premiums (OT/ND/Holiday)',
                    'amount' => round($attendancePremium, 2),
                ];
            }
        }

        $employee->loadMissing(['company', 'branch', 'departmentRelation', 'governmentIds']);
        $company = $employee->company ?? ($attrs['company_id'] ? Company::query()->find((int) $attrs['company_id']) : null);

        $gov = $this->governmentIdFieldsForPayslip($employee);
        $employmentLabel = $this->employmentStatusLabelForPayslip($employee);
        $employmentTypeLabel = $this->employmentTypeLabelForPayslip($employee);

        return [
            'company' => [
                'id' => $company?->id !== null ? (int) $company->id : null,
                'name' => $company?->name,
                'tin' => $company?->tin,
                'address' => $company?->address,
                'email' => $company?->email,
                'phone' => $company?->phone,
                'logo' => $company?->logo,
                'logo_url' => $company?->logo ? '/api/media/public/'.ltrim((string) $company->logo, '/') : null,
            ],
            'batch_scope' => [
                'company_id' => $employee->company_id !== null ? (int) $employee->company_id : null,
                'branch_id' => $employee->branch_id !== null ? (int) $employee->branch_id : null,
                'department_id' => $employee->department_id !== null ? (int) $employee->department_id : null,
            ],
            'employee' => [
                'id' => (int) $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->departmentRelation?->name ?? $employee->department,
                'position' => $employee->position,
                'employment_type' => $employee->employment_type,
                'employment_type_label' => $employmentTypeLabel,
                'employment_status' => $employee->employment_status,
                'employment_status_label' => $employmentLabel,
                'tin_number' => $gov['tin_number'],
                'sss_number' => $gov['sss_number'],
                'philhealth_number' => $gov['philhealth_number'],
                'pagibig_number' => $gov['pagibig_number'],
            ],
            'payroll' => [
                'pay_period_start' => $attrs['pay_period_start'] ?? $gen['unique']['pay_period_start'] ?? null,
                'pay_period_end' => $attrs['pay_period_end'] ?? $gen['unique']['pay_period_end'] ?? null,
                'pay_date' => $attrs['pay_date'] ?? null,
                'pay_cycle_id' => isset($attrs['pay_cycle_id']) ? (int) $attrs['pay_cycle_id'] : (isset($periodInput['pay_cycle_id']) ? (int) $periodInput['pay_cycle_id'] : null),
                'payroll_period_id' => isset($attrs['payroll_period_id']) ? (int) $attrs['payroll_period_id'] : (isset($periodInput['payroll_period_id']) ? (int) $periodInput['payroll_period_id'] : null),
                'payroll_module' => (string) ($attrs['payroll_module'] ?? ($summary['payroll_module'] ?? PayrollBatchRun::MODULE_STANDARD)),
                'cycle_label' => $attrs['cycle_label'] ?? null,
                'is_final_pay' => (bool) ($attrs['is_final_pay'] ?? false),
                /** Live preview only — immutable payslips use {@see PayslipStoredSnapshotViewPayload}. */
                'status' => Payslip::STATUS_DRAFT,
                'daily_rate' => (float) ($summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0)),
                'daily_rate_divisor_days' => (int) ($summary['daily_rate_divisor_days'] ?? ($snapshot['daily_rate_divisor_days'] ?? 0)),
            ],
            'amounts' => [
                'gross_pay' => (float) ($attrs['gross_pay'] ?? 0),
                'total_deductions' => (float) ($attrs['total_deductions'] ?? 0),
                'net_pay' => (float) ($attrs['net_pay'] ?? 0),
                'taxable_total_this_period' => (float) ($attrs['taxable_total_this_period'] ?? 0),
                'non_taxable_total_this_period' => (float) ($attrs['non_taxable_total_this_period'] ?? 0),
                'ytd_gross' => (float) ($attrs['ytd_gross'] ?? 0),
                'ytd_deductions' => (float) ($attrs['ytd_deductions'] ?? 0),
                'ytd_tax' => (float) ($attrs['ytd_tax'] ?? 0),
            ],
            'summary' => [
                'basic_pay' => (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)),
                'attendance_premium_pay_this_period' => (float) ($summary['attendance_premium_pay_this_period'] ?? 0),
                'non_basic_earnings_this_period' => (float) ($summary['non_basic_earnings_this_period'] ?? 0),
                'withholding_tax_this_period_estimate' => (float) ($summary['withholding_tax_this_period_estimate'] ?? 0),
                'employee_statutory_this_period' => (float) ($summary['employee_statutory_this_period'] ?? 0),
                'custom_deductions_this_period' => (float) ($summary['custom_deductions_this_period'] ?? 0),
                'actual_days_worked' => (float) ($summary['actual_days_worked'] ?? 0),
                'daily_rate' => (float) ($summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0)),
                'basic_salary_schedule_type' => (string) ($summary['basic_salary_schedule_type'] ?? ''),
                'basic_salary_schedule_factor' => (float) ($summary['basic_salary_schedule_factor'] ?? 0),
                'payslip_earning_lines' => is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [],
                'daily_computation_earning_lines' => $dailyEarningLines,
                'attendance_display_summary' => is_array($summary['attendance_display_summary'] ?? null)
                    ? $summary['attendance_display_summary']
                    : [
                        'working_days_count' => 0,
                        'presence_days_count' => 0,
                        'lines' => [],
                        'total_regular_hours' => 0.0,
                        'total_presence_regular_hours' => 0.0,
                    ],
                'holiday_premium_breakdown' => is_array($summary['holiday_premium_breakdown'] ?? null)
                    ? array_values($summary['holiday_premium_breakdown'])
                    : [],
                'payslip_deduction_lines' => is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [],
                'payslip_custom_deduction_lines' => is_array($summary['payslip_custom_deduction_lines'] ?? null) ? $summary['payslip_custom_deduction_lines'] : [],
                'statutory_breakdown' => is_array($summary['statutory_breakdown'] ?? null) ? $summary['statutory_breakdown'] : [],
            ],
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Shared live payroll computation entrypoint for draft/preview payslip reads.
     * Draft/preview modes do not persist or freeze rows; finalization uses the same
     * computation data through generatePayslipFromComputedPayroll()/generatePayslip().
     *
     * @param  array<string, mixed>  $periodInput
     * @return array<string, mixed>
     */
    public function computePayrollForEmployee(User|int $employee, ?int $payrollPeriodId = null, string $mode = 'draft', array $periodInput = []): array
    {
        $started = microtime(true);
        $user = $employee instanceof User ? $employee : User::query()->findOrFail((int) $employee);
        $user = app(EmployeeStatusService::class)->syncAutomaticEmploymentStatus($user);
        $period = null;

        if ($payrollPeriodId !== null) {
            $period = PayrollPeriod::query()
                ->whereKey($payrollPeriodId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $periodInput = array_merge([
                'from_date' => $period->from_date?->toDateString(),
                'to_date' => $period->to_date?->toDateString(),
                'pay_cycle_id' => $period->pay_cycle_id !== null ? (int) $period->pay_cycle_id : null,
                'reference_date' => $period->pay_date?->toDateString() ?: $period->reference_date?->toDateString(),
                'payroll_period_id' => (int) $period->id,
                'use_company_default' => $period->pay_cycle_id === null,
                'is_final_pay' => false,
                'password_protect' => false,
            ], $periodInput);
        }

        $payload = $this->previewDataForEmployee($user, $periodInput);
        $computedAt = now()->toIso8601String();
        $summary = is_array($payload['snapshot']['summary'] ?? null) ? $payload['snapshot']['summary'] : [];

        $payload['employee_id'] = (int) $user->id;
        $payload['payroll_period_id'] = $payrollPeriodId ?? ($payload['payroll']['payroll_period_id'] ?? null);
        $payload['mode'] = $mode === 'preview' ? 'preview' : 'draft';
        $payload['computed_at'] = $computedAt;
        $payload['source'] = 'live_computation';
        $payload['gross_pay'] = (float) ($payload['amounts']['gross_pay'] ?? 0);
        $payload['net_pay'] = (float) ($payload['amounts']['net_pay'] ?? 0);
        $payload['earnings'] = is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [];
        $payload['deductions'] = is_array($summary['payslip_custom_deduction_lines'] ?? null) ? $summary['payslip_custom_deduction_lines'] : [];
        $payload['contributions'] = is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [];

        $payload['payroll'] = array_merge($payload['payroll'] ?? [], [
            'mode' => $payload['mode'],
            'status' => Payslip::STATUS_DRAFT,
            'computed_at' => $computedAt,
            'source' => 'live_computation',
        ]);

        $debugLines = collect(array_merge(
            (array) data_get($summary, 'deduction_schedule.earning_lines', []),
            (array) data_get($summary, 'deduction_schedule.custom_lines', [])
        ));

        Log::info('payslip.live_compute', [
            'employee_id' => (int) $user->id,
            'payroll_period_id' => $payrollPeriodId,
            'mode' => $payload['mode'],
            'payroll_finalized' => false,
            'source' => 'live_computation',
            'pay_components_loaded' => $debugLines->count(),
            'resolved_calculation_standards' => $debugLines->pluck('resolved_calculation_standard')->filter()->unique()->values()->all(),
            'resolved_schedules' => $debugLines->pluck('resolved_schedule')->merge($debugLines->pluck('earning_schedule_type'))->merge($debugLines->pluck('deduction_schedule_type'))->filter()->unique()->values()->all(),
            'gross_pay' => $payload['gross_pay'],
            'net_pay' => $payload['net_pay'],
            'computation_ms' => round((microtime(true) - $started) * 1000, 2),
            'cache' => 'skipped',
        ]);

        return $payload;
    }

    /**
     * Persist the same live draft computation used by the payslip page back onto the draft row.
     * Finalization uses this to freeze the visible draft values, not an older capped snapshot.
     */
    public function refreshDraftPayslipFromLiveComputation(Payslip $payslip, User $employee): Payslip
    {
        if (in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
            return $payslip;
        }

        $requiredEmployeeColumns = [
            'company_id',
            'branch_id',
            'department_id',
            'pay_cycle_id',
            'monthly_salary',
            'monthly_rate',
            'daily_rate',
            'working_schedule_id',
            'schedule',
            'employment_status',
            'employment_type',
        ];
        $employeeAttributes = $employee->getAttributes();
        foreach ($requiredEmployeeColumns as $column) {
            if (! array_key_exists($column, $employeeAttributes)) {
                $employee = User::query()->find((int) $employee->id) ?? $employee;
                break;
            }
        }
        $employee->loadMissing([
            'company',
            'branch',
            'payCycle',
            'governmentIds',
            'workingSchedule',
        ]);

        $periodInput = $this->periodInputFromPayslip($payslip);
        $live = $this->computePayrollForEmployee(
            $employee,
            $payslip->payroll_period_id !== null ? (int) $payslip->payroll_period_id : null,
            'draft',
            $periodInput
        );
        $snapshot = is_array($live['snapshot'] ?? null) ? $live['snapshot'] : [];
        $lineTotals = $this->payslipLineTotalsFromSnapshot($snapshot);
        $snapshot = $this->snapshotWithPayslipLineTotals($snapshot, $lineTotals);

        $payslip->forceFill([
            'gross_pay' => $lineTotals['gross_pay'],
            'total_deductions' => $lineTotals['total_deductions'],
            'net_pay' => $lineTotals['net_pay'],
            'taxable_total_this_period' => (float) data_get($live, 'amounts.taxable_total_this_period', $payslip->taxable_total_this_period ?? 0),
            'non_taxable_total_this_period' => (float) data_get($live, 'amounts.non_taxable_total_this_period', $payslip->non_taxable_total_this_period ?? 0),
            'snapshot' => $snapshot,
        ]);
        $payslip->save();

        return $this->ensureDraftPayrollLinesSynced($payslip->fresh() ?? $payslip);
    }

    /**
     * Repair consultant draft snapshots (fixed Basic Pay, no units) without rerunning attendance payroll.
     */
    public function refreshConsultantDraftPayslipSnapshot(Payslip $payslip, User $employee): Payslip
    {
        if (in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
            return $payslip;
        }

        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summary['consultant_fixed_payroll'] = true;
        if (trim((string) ($summary['employment_status'] ?? '')) === '') {
            $summary['employment_status'] = (string) ($employee->employment_status ?? 'consultant');
        }
        $snapshot['summary'] = $summary;

        $snapshot = $this->normalizeSnapshotForPayslipPdf($snapshot);
        $lineTotals = $this->payslipLineTotalsFromNormalizedSnapshot($snapshot);
        $snapshot = $this->snapshotWithPayslipLineTotals($snapshot, $lineTotals);

        $payslip->forceFill([
            'gross_pay' => $lineTotals['gross_pay'],
            'total_deductions' => $lineTotals['total_deductions'],
            'net_pay' => $lineTotals['net_pay'],
            'snapshot' => $snapshot,
        ]);
        $payslip->save();

        return $this->ensureDraftPayrollLinesSynced($payslip->fresh() ?? $payslip);
    }

    /**
     * Align payslip summary columns and payroll_lines draft rows with the stored snapshot.
     */
    public function ensureDraftPayrollLinesSynced(Payslip $payslip, bool $save = true): Payslip
    {
        if (in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
            return $payslip;
        }

        $this->syncPayslipSummaryFromLines($payslip, $save);
        $fresh = $payslip->fresh() ?? $payslip;

        $persist = app(PayrollLinePersistService::class);
        if (! $persist->tablesReady()) {
            return $fresh;
        }

        try {
            $persist->syncDraftLinesFromPayslip($fresh);
        } catch (Throwable $e) {
            Log::warning('Payroll draft line sync failed', [
                'payslip_id' => (int) $fresh->id,
                'employee_id' => (int) $fresh->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fresh->fresh() ?? $fresh;
    }

    public function draftPayrollLineRowCount(Payslip $payslip): int
    {
        $persist = app(PayrollLinePersistService::class);
        if (! $persist->tablesReady()) {
            return 0;
        }

        $payrollEmployee = PayrollEmployee::query()
            ->where('payslip_id', (int) $payslip->id)
            ->where('status', PayrollEmployee::STATUS_DRAFT)
            ->first();

        if (! $payrollEmployee instanceof PayrollEmployee) {
            return 0;
        }

        return (int) PayrollLine::query()
            ->where('payroll_employee_id', (int) $payrollEmployee->id)
            ->where('status', PayrollLine::STATUS_DRAFT)
            ->count();
    }

    /**
     * Payslips have real foreign keys for org columns. Older/stale employee assignments can
     * reference deleted departments/branches; keep the payroll company scope, but null any
     * invalid optional org FK before insert/upsert so one bad assignment does not fail a batch.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizePayslipOrganizationContext(array $context, User $employee): array
    {
        $checks = [
            'company_id' => 'companies',
            'branch_id' => 'branches',
            'division_id' => 'divisions',
            'department_id' => 'departments',
            'section_unit_id' => 'sections_or_units',
        ];

        foreach ($checks as $key => $table) {
            $value = $context[$key] ?? null;
            if ($value === null || $value === '' || (int) $value <= 0) {
                $context[$key] = null;

                continue;
            }

            if ($this->orgForeignKeyExists($table, (int) $value)) {
                $context[$key] = (int) $value;

                continue;
            }

            // Company is the selected payroll scope and should be valid. If it is not, fail
            // with a clear error instead of silently moving the payslip to no company.
            if ($key === 'company_id') {
                throw new \RuntimeException('Selected payroll company no longer exists.');
            }

            Log::warning('Payslip generation ignored stale organization foreign key', [
                'user_id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'field' => $key,
                'stale_id' => (int) $value,
                'table' => $table,
                'assignment_id' => isset($context['assignment_id']) ? (int) $context['assignment_id'] : null,
                'assignment_type' => $context['assignment_type'] ?? null,
            ]);
            $context[$key] = null;
        }

        return $context;
    }

    private function orgForeignKeyExists(string $table, int $id): bool
    {
        $cacheKey = $table.':'.$id;
        if (! array_key_exists($cacheKey, self::$orgForeignKeyExistsCache)) {
            self::$orgForeignKeyExistsCache[$cacheKey] = Schema::hasTable($table)
                && Schema::hasColumn($table, 'id')
                && DB::table($table)->where('id', $id)->exists();
        }

        return self::$orgForeignKeyExistsCache[$cacheKey];
    }

    /**
     * Derive payroll table totals from the exact line arrays rendered by the payslip.
     *
     * @return array{gross_pay: float, total_deductions: float, net_pay: float, earning_lines: list<array<string, mixed>>, deduction_lines: list<array<string, mixed>>}
     */
    public function payslipLineTotalsFromSnapshot(array $snapshot): array
    {
        return $this->payslipLineTotalsFromNormalizedSnapshot($this->normalizeSnapshotForPayslipPdf($snapshot));
    }

    /**
     * Reconcile a saved payslip row with the rendered payslip line totals.
     *
     * @return array{gross_pay: float, total_deductions: float, net_pay: float, changed: bool}
     */
    public function syncPayslipSummaryFromLines(Payslip $payslip, bool $save = true): array
    {
        if (in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
            $metrics = $this->frozenPayslipLineMetrics($payslip);

            return [
                'gross_pay' => $metrics['gross_pay'],
                'total_deductions' => $metrics['total_deductions'],
                'net_pay' => $metrics['net_pay'],
                'changed' => false,
            ];
        }

        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $normalizedSnapshot = $this->normalizeSnapshotForPayslipPdf($snapshot);
        $lineTotals = $this->payslipLineTotalsFromNormalizedSnapshot($normalizedSnapshot);
        $normalizedSnapshot = $this->snapshotWithPayslipLineTotals($normalizedSnapshot, $lineTotals);

        $summaryGross = round((float) ($payslip->gross_pay ?? 0), 2);
        $summaryDeductions = round((float) ($payslip->total_deductions ?? 0), 2);
        $summaryNet = round((float) ($payslip->net_pay ?? 0), 2);
        $changed = $summaryGross !== $lineTotals['gross_pay']
            || $summaryDeductions !== $lineTotals['total_deductions']
            || $summaryNet !== $lineTotals['net_pay']
            || $normalizedSnapshot !== $snapshot;

        $employee = $payslip->relationLoaded('employee') && $payslip->employee instanceof User
            ? $payslip->employee
            : User::query()->select(['id', 'employee_code'])->find((int) $payslip->user_id);
        $this->logPayslipLineSummaryMismatchIfNeeded(
            $employee,
            $payslip,
            $summaryGross,
            $summaryDeductions,
            $summaryNet,
            $lineTotals
        );

        $payslip->forceFill([
            'gross_pay' => $lineTotals['gross_pay'],
            'total_deductions' => $lineTotals['total_deductions'],
            'net_pay' => $lineTotals['net_pay'],
            'snapshot' => $normalizedSnapshot,
        ]);

        if ($changed && $save) {
            $payslip->save();
        }

        return [
            'gross_pay' => $lineTotals['gross_pay'],
            'total_deductions' => $lineTotals['total_deductions'],
            'net_pay' => $lineTotals['net_pay'],
            'changed' => $changed,
        ];
    }

    /**
     * Read-only totals from the stored payslip snapshot lines (no normalize/repair/cap).
     *
     * @return array{gross_pay: float, total_deductions: float, net_pay: float}
     */
    public function payslipTotalsForDisplay(Payslip $payslip): array
    {
        $metrics = $this->frozenPayslipLineMetrics($payslip);

        return [
            'gross_pay' => $metrics['gross_pay'],
            'total_deductions' => $metrics['total_deductions'],
            'net_pay' => $metrics['net_pay'],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{
     *   line_key:string,
     *   component_code:string,
     *   component_name:string,
     *   schedule:string,
     *   calculation_standard:string,
     *   configured_amount:float,
     *   amount:float
     * }>
     */
    public function payrollDeductionLineCatalog(array $snapshot): array
    {
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $lines = array_values(array_merge(
            $this->rawPayslipLineList($summary['payslip_deduction_lines'] ?? []),
            $this->normalizePayslipCustomDeductionLines($summary['payslip_custom_deduction_lines'] ?? [], $summary)
        ));

        $catalog = [];
        foreach ($lines as $index => $line) {
            $lineKey = $this->deductionCatalogLineKey($line, $index);
            $componentCode = trim((string) ($line['component_code'] ?? $line['code'] ?? ''));
            if ($componentCode === '' && preg_match('/^pay_component:(\d+)$/i', $lineKey, $matches)) {
                $componentCode = 'pay_component:'.$matches[1];
            }
            if ($componentCode === '') {
                $componentCode = $lineKey;
            }
            $catalog[] = [
                'line_key' => $lineKey,
                'component_code' => $componentCode,
                'component_name' => trim((string) ($line['label'] ?? $line['name'] ?? '')),
                'schedule' => trim((string) ($line['resolved_schedule'] ?? $line['component_schedule'] ?? $line['deduction_schedule_type'] ?? $line['earning_schedule_type'] ?? $line['schedule'] ?? '')),
                'calculation_standard' => trim((string) ($line['resolved_calculation_standard'] ?? $line['calculation_standard'] ?? '')),
                'configured_amount' => round((float) ($line['component_amount'] ?? $line['configured_amount'] ?? $line['amount'] ?? 0), 2),
                'amount' => round((float) ($line['amount'] ?? $line['resolved_amount'] ?? 0), 2),
            ];
        }

        return $catalog;
    }

    /**
     * Copy the draft payslip snapshot exactly and lock summary totals before status promotion.
     */
    public function freezePayslipSnapshotForFinalization(Payslip $payslip): Payslip
    {
        $metrics = $this->frozenPayslipLineMetrics($payslip);
        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $snapshot = is_string($encoded)
            ? (json_decode($encoded, true) ?: [])
            : $snapshot;
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summary['gross_pay_this_period'] = $metrics['gross_pay'];
        $summary['total_deductions_this_period'] = $metrics['total_deductions'];
        $summary['net_pay_after_withholding_estimate'] = $metrics['net_pay'];
        $snapshot['summary'] = $summary;
        $snapshot['finalization_frozen_at'] = now()->toIso8601String();
        $snapshot['finalization_source'] = 'draft_snapshot_copy';

        $payslip->forceFill([
            'gross_pay' => $metrics['gross_pay'],
            'total_deductions' => $metrics['total_deductions'],
            'net_pay' => $metrics['net_pay'],
            'snapshot' => $snapshot,
        ]);
        $payslip->save();

        return $payslip->fresh() ?? $payslip;
    }

    /**
     * @param  list<array<string, mixed>>  $draftLines
     * @param  list<array<string, mixed>>  $finalizedLines
     * @return list<array<string, mixed>>
     */
    public function deductionLineMismatches(array $draftLines, array $finalizedLines): array
    {
        $draftByKey = [];
        foreach ($draftLines as $line) {
            $draftByKey[(string) ($line['line_key'] ?? '')] = $line;
        }
        $finalByKey = [];
        foreach ($finalizedLines as $line) {
            $finalByKey[(string) ($line['line_key'] ?? '')] = $line;
        }

        $mismatches = [];
        $keys = array_values(array_unique(array_merge(array_keys($draftByKey), array_keys($finalByKey))));
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            $draft = $draftByKey[$key] ?? null;
            $final = $finalByKey[$key] ?? null;
            if ($draft === null || $final === null) {
                $mismatches[] = [
                    'line_key' => $key,
                    'reason' => 'missing_line',
                    'draft' => $draft,
                    'finalized' => $final,
                ];

                continue;
            }

            $fields = ['component_code', 'amount', 'calculation_standard', 'schedule'];
            $fieldMismatch = [];
            foreach ($fields as $field) {
                $draftValue = $field === 'amount'
                    ? round((float) ($draft[$field] ?? 0), 2)
                    : trim((string) ($draft[$field] ?? ''));
                $finalValue = $field === 'amount'
                    ? round((float) ($final[$field] ?? 0), 2)
                    : trim((string) ($final[$field] ?? ''));
                if ($field === 'amount' ? abs($draftValue - $finalValue) >= 0.01 : $draftValue !== $finalValue) {
                    $fieldMismatch[$field] = ['draft' => $draftValue, 'finalized' => $finalValue];
                }
            }

            if ($fieldMismatch !== []) {
                $mismatches[] = [
                    'line_key' => $key,
                    'component_code' => (string) ($draft['component_code'] ?? $key),
                    'component_name' => (string) ($draft['component_name'] ?? ''),
                    'reason' => 'field_mismatch',
                    'fields' => $fieldMismatch,
                    'draft_amount' => round((float) ($draft['amount'] ?? 0), 2),
                    'finalized_amount' => round((float) ($final['amount'] ?? 0), 2),
                ];
            }
        }

        return $mismatches;
    }

    /**
     * @throws \RuntimeException
     */
    public function assertFrozenDeductionLinesMatch(
        Payslip $draftPayslip,
        Payslip $finalizedPayslip,
        int $payrollRunId,
        ?int $companyId,
        int $employeeId
    ): void {
        $draftSnapshot = is_array($draftPayslip->snapshot)
            ? $draftPayslip->snapshot
            : (is_string($draftPayslip->snapshot) ? json_decode($draftPayslip->snapshot, true) : []);
        $finalSnapshot = is_array($finalizedPayslip->snapshot)
            ? $finalizedPayslip->snapshot
            : (is_string($finalizedPayslip->snapshot) ? json_decode($finalizedPayslip->snapshot, true) : []);
        if (! is_array($draftSnapshot)) {
            $draftSnapshot = [];
        }
        if (! is_array($finalSnapshot)) {
            $finalSnapshot = [];
        }

        $draftLines = $this->payrollDeductionLineCatalog($draftSnapshot);
        $finalLines = $this->payrollDeductionLineCatalog($finalSnapshot);
        $mismatches = $this->deductionLineMismatches($draftLines, $finalLines);

        foreach ($mismatches as $mismatch) {
            Log::error('Payroll finalize deduction mismatch', [
                'payroll_run_id' => $payrollRunId,
                'employee_id' => $employeeId,
                'company_id' => $companyId,
                'component_code' => $mismatch['component_code'] ?? ($mismatch['line_key'] ?? null),
                'component_name' => $mismatch['component_name'] ?? null,
                'schedule' => data_get($mismatch, 'fields.schedule.draft') ?? data_get($mismatch, 'draft.schedule'),
                'calculation_standard' => data_get($mismatch, 'fields.calculation_standard.draft') ?? data_get($mismatch, 'draft.calculation_standard'),
                'configured_amount' => data_get($mismatch, 'draft.configured_amount'),
                'draft_amount' => $mismatch['draft_amount'] ?? data_get($mismatch, 'fields.amount.draft'),
                'finalized_amount' => $mismatch['finalized_amount'] ?? data_get($mismatch, 'fields.amount.finalized'),
                'mismatch' => $mismatch,
                'recompute_attempted' => false,
            ]);
        }

        if ($mismatches !== []) {
            throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
        }
    }

    /**
     * Shared computation for generate + preview — keeps one source of truth for payroll snapshot fields.
     *
     * Withholding tax (`withholding_tax_this_period_estimate`, `withholding_tax_monthly_estimate`) comes only from
     * {@see PayrollComputationService::computeEmployeePayroll} — same BIR RR 11-2018 Table A path as Government
     * Deductions / Compliance Audit (mandatory EE first, then tax; loans after tax in deduction order).
     *
     * @param  array<string, mixed>  $input
     * @return array{unique: array<string, mixed>, attributes: array<string, mixed>, plain_password: ?string}
     */
    private function computePayslipGenerationData(
        User $employee,
        array $input,
        bool $enforcePayrollWindowMutable = false,
        ?array $precomputed = null,
        ?array $precomputedPreview = null,
        ?PayCycle $precomputedCycle = null,
        ?array $ytdPriorOverride = null,
        ?object $timingSink = null,
        ?array $precomputedContext = null,
        bool $skipMutableCheck = false,
    ): array
    {
        if (is_array($precomputedContext) && count($precomputedContext) === 4) {
            [$from, $to, $preview, $cycle] = $precomputedContext;
        } else {
            [$from, $to, $preview, $cycle] = $this->resolveComputationContext($employee, $input);
        }
        $periodStart = $from->copy();
        $periodEnd = $to->copy();
        $from = $this->payrollEligibility->clampComputationStart($employee, $from, $to);
        if (is_array($precomputedPreview)) {
            $preview = $precomputedPreview;
        }
        if ($precomputedCycle instanceof PayCycle) {
            $cycle = $precomputedCycle;
        }
        if ($enforcePayrollWindowMutable && ! $skipMutableCheck) {
            $this->assertPayrollPeriodMutableForUserWindow((int) $employee->id, $from, $to);
        }

        $selectedCompanyId = ! empty($input['company_id'])
            ? (int) $input['company_id']
            : $employee->getEffectiveCompanyId();
        $selectedBranchId = ! empty($input['branch_id']) ? (int) $input['branch_id'] : null;
        $selectedDepartmentId = ! empty($input['department_id']) ? (int) $input['department_id'] : null;
        $payrollAssignment = $this->payrollEligibility->contextForEmployee(
            $employee,
            $selectedCompanyId !== null && $selectedCompanyId > 0 ? $selectedCompanyId : null,
            $selectedBranchId,
            $selectedDepartmentId,
            $from,
            $to
        );
        $payrollAssignment = $this->sanitizePayslipOrganizationContext($payrollAssignment, $employee);

        $periodCtx = [
            'pay_period_start' => $from->toDateString(),
            'pay_period_end' => $to->toDateString(),
            'selected_pay_date' => $preview['pay_date'] ?? null,
            'pay_cycle_preview' => $preview,
            'pay_cycle_code' => $cycle?->code ?? data_get($preview, 'pay_cycle_code', data_get($preview, 'code')),
            'semi_month_segment' => data_get($preview, 'semi_month_segment'),
            'company_id' => $payrollAssignment['company_id'] ?? $selectedCompanyId,
        ];
        if (! empty($input['payroll_batch_run_id'])) {
            $periodCtx['payroll_batch_run_id'] = (int) $input['payroll_batch_run_id'];
        }
        if ($timingSink !== null) {
            $periodCtx['_timing_sink'] = $timingSink;
        }

        $payrollModule = $this->resolvePayrollModule($input);
        $computed = is_array($precomputed)
            ? $precomputed
            : $this->computePayrollForModule(
                $employee,
                $from,
                $to,
                $payrollModule,
                $periodCtx,
                $preview
            );
        $summary = $computed['summary'] ?? [];

        $computedGrossPay = round(
            (float) ($summary['gross_pay_this_period']
                ?? ((float) ($summary['total_pay'] ?? 0) + (float) ($summary['non_basic_earnings_this_period'] ?? 0))),
            2
        );
        $empStat = (float) ($summary['employee_statutory_this_period'] ?? 0);
        $custDed = (float) ($summary['custom_deductions_this_period'] ?? 0);
        $wh = (float) ($summary['withholding_tax_this_period_estimate'] ?? 0);
        $computedTotalDed = round($empStat + $custDed + $wh, 2);
        $computedNetPay = (float) ($summary['net_pay_after_withholding_estimate'] ?? 0);

        $compBreakdown = $summary['compensation_breakdown'] ?? [];
        $taxClass = is_array($compBreakdown['tax_classification'] ?? null) ? $compBreakdown['tax_classification'] : [];
        $taxable = (float) ($taxClass['taxable_total'] ?? 0);
        $nonTax = (float) ($taxClass['non_taxable_total'] ?? 0);

        $ytdPrior = is_array($ytdPriorOverride)
            ? $ytdPriorOverride
            : $this->calculateYtd($employee, $from);

        $snapshot = [
            'computed_at' => now()->toIso8601String(),
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'pay_cycle_preview' => $preview,
            'daily_rate' => round((float) ($computed['daily_rate'] ?? 0), 2),
            'daily_rate_divisor_days' => (int) ($computed['daily_rate_divisor_days'] ?? 0),
            'summary' => $summary,
            'daily_computation_days' => is_array($computed['days'] ?? null) ? $computed['days'] : [],
            'days_meta' => [
                'total_worked_minutes' => $summary['total_worked_minutes'] ?? 0,
                'total_regular_day_minutes' => $summary['total_regular_day_minutes'] ?? 0,
                'total_regular_night_minutes' => $summary['total_regular_night_minutes'] ?? 0,
                'total_ot_day_minutes' => $summary['total_ot_day_minutes'] ?? 0,
                'total_ot_night_minutes' => $summary['total_ot_night_minutes'] ?? 0,
            ],
        ];

        // Persist PDF table arrays as 0-based lists with no junk rows so DB + mPDF stay aligned.
        $snapshot = $this->normalizeSnapshotForPayslipPdf($snapshot);
        $lineTotals = $this->payslipLineTotalsFromNormalizedSnapshot($snapshot);
        $snapshot = $this->snapshotWithPayslipLineTotals($snapshot, $lineTotals);
        $grossPay = $lineTotals['gross_pay'];
        $totalDed = $lineTotals['total_deductions'];
        $netPay = $lineTotals['net_pay'];
        $this->logPayslipLineSummaryMismatchIfNeeded(
            $employee,
            null,
            $computedGrossPay,
            $computedTotalDed,
            round($computedNetPay, 2),
            $lineTotals
        );

        $payDate = isset($preview['pay_date']) ? Carbon::parse((string) $preview['pay_date']) : $to->copy();

        $companyId = $payrollAssignment['company_id'] ?? $selectedCompanyId;
        $finalPay = (bool) ($input['is_final_pay'] ?? false)
            || EmploymentStatus::tryFromStored($employee->employment_status) === EmploymentStatus::Separated;

        $plainPassword = ! empty($input['password_protect']) ? $this->randomPdfPassword() : null;

        return [
            'unique' => [
                'user_id' => $employee->id,
                'company_id' => $companyId,
                'payroll_module' => $payrollModule,
                'pay_period_start' => $periodStart->toDateString(),
                'pay_period_end' => $periodEnd->toDateString(),
                'period_slot' => 0,
            ],
            'attributes' => [
                'payroll_module' => $payrollModule,
                'payroll_period_id' => $input['payroll_period_id'] ?? null,
                'payroll_batch_run_id' => ! empty($input['payroll_batch_run_id']) ? (int) $input['payroll_batch_run_id'] : null,
                'pay_cycle_id' => $cycle?->id,
                'company_id' => $companyId,
                'branch_id' => $payrollAssignment['branch_id'] ?? $employee->branch_id,
                'division_id' => $payrollAssignment['division_id'] ?? null,
                'department_id' => $payrollAssignment['department_id'] ?? $employee->department_id,
                'section_unit_id' => $payrollAssignment['section_unit_id'] ?? null,
                'assignment_id' => $payrollAssignment['assignment_id'] ?? null,
                'assignment_type' => $payrollAssignment['assignment_type'] ?? null,
                'pay_date' => $payDate->toDateString(),
                'cycle_label' => $preview['cycle_label'] ?? $cycle?->name,
                'gross_pay' => $grossPay,
                'total_deductions' => $totalDed,
                'net_pay' => $netPay,
                'ytd_gross' => round($ytdPrior['ytd_gross'] + $grossPay, 2),
                'ytd_deductions' => round($ytdPrior['ytd_deductions'] + $totalDed, 2),
                'ytd_tax' => round($ytdPrior['ytd_tax'] + $wh, 2),
                'taxable_total_this_period' => $taxable,
                'non_taxable_total_this_period' => $nonTax,
                'is_final_pay' => $finalPay,
                'snapshot' => $snapshot,
                'status' => Payslip::STATUS_DRAFT,
            ],
            'plain_password' => $plainPassword,
        ];
    }

    /**
     * Bulk payslip generation (company / branch / department). Uses chunking for memory efficiency.
     *
     * @param  array<string, mixed>  $periodInput  from_date, to_date, pay_cycle_id?, reference_date?
     * @param  bool  $withPdf  When false, persist rows only (no PDFs).
     * @return array{payslip_ids: list<int>, timings: array<string, float>}
     */
    public function generateBulkPayslips(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?array $employeeIds,
        array $periodInput,
        ?User $actor = null,
        bool $withPdf = true,
        ?PayrollBatchRun $progressRun = null
    ): array {
        $startedAt = microtime(true);
        $this->payrollComputation->flushRuntimeCaches();
        if ($progressRun instanceof PayrollBatchRun) {
            $periodInput['payroll_batch_run_id'] = (int) $progressRun->id;
        }
        if ($companyId !== null) {
            $periodInput['company_id'] = $companyId;
        }
        if ($branchId !== null) {
            $periodInput['branch_id'] = $branchId;
        }
        if ($departmentId !== null) {
            $periodInput['department_id'] = $departmentId;
        }

        $timingSink = null;
        if (! $withPdf) {
            $timingSink = (object) [
                'load_schedules_ms' => 0.0,
                'daily_iteration_ms' => 0.0,
                'load_pay_components_ms' => 0.0,
                'load_deductions_ms' => 0.0,
                'compute_loop_ms' => 0.0,
            ];
        }

        $timings = [
            'load_employees_ms' => 0.0,
            'generation_loop_ms' => 0.0,
            'bulk_upsert_ms' => 0.0,
        ];
        $periodStartForEligibility = ! empty($periodInput['from_date'])
            ? Carbon::parse((string) $periodInput['from_date'])->startOfDay()
            : null;
        $periodEndForEligibility = ! empty($periodInput['to_date'])
            ? Carbon::parse((string) $periodInput['to_date'])->startOfDay()
            : null;
        $payrollModule = $progressRun instanceof PayrollBatchRun
            ? $this->normalizePayrollModule((string) ($progressRun->payroll_module ?? PayrollBatchRun::MODULE_STANDARD))
            : $this->resolvePayrollModule($periodInput);
        $periodInput['payroll_module'] = $payrollModule;

        $q = $this->payrollEligibility->query(
            $companyId,
            $branchId,
            $departmentId,
            $periodStartForEligibility,
            $periodEndForEligibility,
            $actor,
            $this->dataScopeService,
            $payrollModule
        );

        if (is_array($employeeIds) && count($employeeIds) > 0) {
            $q->whereIn('id', $employeeIds);
        }

        $orderedIds = (clone $q)->orderByLastName()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $employeeCount = count($orderedIds);
        $periodStartForYtd = Carbon::parse((string) ($periodInput['from_date'] ?? now()->toDateString()))->startOfDay();

        $ids = [];
        $processedEmployees = 0;
        $failedEmployees = 0;
        $employeeFailureMessages = [];
        $generationCancelled = false;
        if ($progressRun instanceof PayrollBatchRun) {
            $this->updateBatchRunProgress($progressRun, [
                'total_employees' => $employeeCount,
                'processed_employees' => 0,
                'failed_employees' => 0,
            ]);
        }

        $fromStr = (string) ($periodInput['from_date'] ?? '');
        $toStr = (string) ($periodInput['to_date'] ?? '');
        $prefetchTimings = [];
        $prefetchAttendance = $payrollModule !== PayrollBatchRun::MODULE_EXECOM
            && ! $withPdf
            && $fromStr !== ''
            && $toStr !== ''
            && $employeeCount > 0;
        $sharedComputationContext = null;
        $attendanceRowsCount = 0;
        $payComponentRowsCount = 0;

        if ($prefetchAttendance) {
            $prefetchTimings = $this->payrollComputation->beginBulkPayrollAttendancePrefetch(
                $orderedIds,
                Carbon::parse($fromStr)->startOfDay(),
                Carbon::parse($toStr)->startOfDay(),
                $companyId,
                $progressRun instanceof PayrollBatchRun ? (int) $progressRun->id : null
            );
            $attendanceRowsCount = (int) (($prefetchTimings['corrections_count'] ?? 0)
                + ($prefetchTimings['logs_count'] ?? 0)
                + ($prefetchTimings['leave_rows_count'] ?? 0)
                + ($prefetchTimings['overtime_rows_count'] ?? 0));

            PayrollPeriodLock::assertMutableForUserIds(
                $orderedIds,
                Carbon::parse($fromStr)->startOfDay(),
                Carbon::parse($toStr)->startOfDay()
            );

            $anchorUser = User::query()->whereKey($orderedIds[0])->first();
            if ($anchorUser instanceof User) {
                $sharedComputationContext = $this->resolveComputationContext($anchorUser, $periodInput);
            }
        }

        $allUsersById = collect();
        if (! $withPdf && $orderedIds !== []) {
            $employeeQueryStartedAt = microtime(true);
            $allUsersById = User::query()
                ->whereIn('id', $orderedIds)
                ->get()
                ->loadMissing([
                    'company',
                    'branch',
                    'departmentRelation',
                    'governmentIds',
                    'payCycle',
                    'workingSchedule',
                ])
                ->keyBy('id');
            $timings['load_employees_ms'] += (microtime(true) - $employeeQueryStartedAt) * 1000;
        }

        try {
            if ($prefetchAttendance) {
                BulkPayrollDraftContext::$active = true;
            }

            if ($prefetchAttendance && $allUsersById->isNotEmpty()) {
                $warmStartedAt = microtime(true);
                $calculator = app(PayrollCalculatorService::class);
                foreach ($allUsersById as $user) {
                    if (! $user instanceof User) {
                        continue;
                    }
                    $calculator->buildEmployeeCompensationSummary($user, [
                        'as_of_date' => $toStr,
                        'include_deduction_schedule_catalog' => false,
                        'cache' => true,
                    ]);
                }
                $prefetchTimings['warm_compensation_ms'] = (microtime(true) - $warmStartedAt) * 1000;
            }

            foreach (array_chunk($orderedIds, self::DRAFT_GENERATION_CHUNK_SIZE) as $chunkIds) {
                if ($this->draftGenerationWasCancelled($progressRun)) {
                    $generationCancelled = true;
                    break;
                }
                if ($chunkIds === []) {
                    continue;
                }

                if ($withPdf) {
                    $users = User::query()
                        ->whereIn('id', $chunkIds)
                        ->get()
                        ->sortBy(fn (User $u) => array_search($u->id, $chunkIds, true))
                        ->values();
                    $employeeQueryStartedAt = microtime(true);
                    $users->loadMissing([
                        'company',
                        'branch',
                        'departmentRelation',
                        'governmentIds',
                        'payCycle',
                        'workingSchedule',
                    ]);
                    $timings['load_employees_ms'] += (microtime(true) - $employeeQueryStartedAt) * 1000;
                    $loopStartedAt = microtime(true);
                    foreach ($users as $user) {
                        $ids[] = $this->generatePayslip($user, $periodInput, $withPdf)['payslip']->id;
                    }
                    $timings['generation_loop_ms'] += (microtime(true) - $loopStartedAt) * 1000;

                    continue;
                }

                $users = collect($chunkIds)
                    ->map(fn (int $id) => $allUsersById->get($id))
                    ->filter(fn ($user) => $user instanceof User)
                    ->values();

                $ytdChunk = $this->bulkYtdPriorBalances($chunkIds, $periodStartForYtd);

            $now = now();
            $rows = [];
            $periodStartStr = null;
            $periodEndStr = null;
            $loopStartedAt = microtime(true);
            foreach ($users as $user) {
                $uid = (int) $user->id;
                try {
                    $gen = $this->computePayslipGenerationData(
                        $user,
                        $periodInput,
                        true,
                        null,
                        null,
                        null,
                        $ytdChunk[$uid] ?? ['ytd_gross' => 0.0, 'ytd_deductions' => 0.0, 'ytd_tax' => 0.0],
                        $timingSink,
                        $sharedComputationContext,
                        skipMutableCheck: true,
                    );
                } catch (\Throwable $e) {
                    $failedEmployees++;
                    $employeeName = trim((string) ($user->display_name ?? $user->name ?? ''));
                    $message = sprintf(
                        'Employee %s (user_id=%d): %s',
                        $employeeName !== '' ? $employeeName : 'unknown',
                        $uid,
                        $e->getMessage()
                    );
                    $employeeFailureMessages[] = $message;
                    Log::error('payroll.batch.employee_failed', [
                        'payroll_batch_run_id' => $progressRun instanceof PayrollBatchRun ? (int) $progressRun->id : null,
                        'payroll_module' => $payrollModule,
                        'employee_id' => $uid,
                        'employee_name' => $employeeName,
                        'message' => $e->getMessage(),
                    ]);
                    report($e);

                    continue;
                }

                $row = array_merge($gen['unique'], $gen['attributes']);
                $row['period_slot'] = 0;
                $periodStartStr ??= (string) $gen['unique']['pay_period_start'];
                $periodEndStr ??= (string) $gen['unique']['pay_period_end'];
                if (isset($row['snapshot']) && is_array($row['snapshot'])) {
                    $row['snapshot'] = json_encode($row['snapshot'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                }
                $row['pdf_password_protected'] = $gen['plain_password'] !== null;
                $row['updated_at'] = $now;
                $row['created_at'] = $now;
                $rows[] = $row;
            }
            $timings['generation_loop_ms'] += (microtime(true) - $loopStartedAt) * 1000;

            if ($rows === [] || $periodStartStr === null || $periodEndStr === null) {
                continue;
            }

            if ($this->draftGenerationWasCancelled($progressRun)) {
                $generationCancelled = true;
                break;
            }

            $upsertStartedAt = microtime(true);
            DB::transaction(function () use ($rows, $periodStartStr, $periodEndStr) {
                $periodLinks = [];
                $upsertRows = [];
                foreach ($rows as $row) {
                    $periodId = isset($row['payroll_period_id']) ? (int) $row['payroll_period_id'] : 0;
                    $uid = (int) ($row['user_id'] ?? 0);
                    if ($periodId > 0 && $uid > 0) {
                        $periodLinks[$uid] = $periodId;
                    }
                    $clean = $row;
                    unset($clean['payroll_period_id']);
                    $upsertRows[] = $clean;
                }

                Payslip::query()->upsert(
                    $upsertRows,
                    ['user_id', 'company_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                    [
                        'pay_cycle_id',
                        'payroll_batch_run_id',
                        'company_id',
                        'branch_id',
                        'division_id',
                        'department_id',
                        'section_unit_id',
                        'assignment_id',
                        'assignment_type',
                        'pay_date',
                        'cycle_label',
                        'gross_pay',
                        'total_deductions',
                        'net_pay',
                        'ytd_gross',
                        'ytd_deductions',
                        'ytd_tax',
                        'taxable_total_this_period',
                        'non_taxable_total_this_period',
                        'is_final_pay',
                        'snapshot',
                        'status',
                        'pdf_password_protected',
                        'updated_at',
                    ]
                );

                foreach ($periodLinks as $uid => $periodId) {
                    $payslip = Payslip::query()
                        ->where('user_id', $uid)
                        ->where('company_id', $upsertRows[0]['company_id'] ?? null)
                        ->whereDate('pay_period_start', $periodStartStr)
                        ->whereDate('pay_period_end', $periodEndStr)
                        ->where('period_slot', 0)
                        ->where('status', '!=', Payslip::STATUS_VOIDED)
                        ->orderByDesc('id')
                        ->first();
                    if ($payslip instanceof Payslip) {
                        if ($periodId > 0) {
                            $this->assignPayrollPeriodId($payslip, $periodId);
                        }
                        $this->ensureDraftPayrollLinesSynced($payslip);
                    }
                }
            });
            $timings['bulk_upsert_ms'] += (microtime(true) - $upsertStartedAt) * 1000;
            $processedEmployees += count($rows);
            if ($progressRun instanceof PayrollBatchRun) {
                $progressPayload = [
                    'processed_employees' => $processedEmployees,
                    'employee_count' => $processedEmployees,
                    'total_employees' => $employeeCount,
                    'failed_employees' => $failedEmployees,
                ];
                if ($employeeFailureMessages !== []) {
                    $progressPayload['error_message'] = Str::limit(implode(' | ', $employeeFailureMessages), 2000, '...');
                }
                $this->updateBatchRunProgress($progressRun, $progressPayload);
            }
            }
        } finally {
            BulkPayrollDraftContext::$active = false;
            if ($prefetchAttendance) {
                $this->payrollComputation->endBulkPayrollAttendancePrefetch();
            }
        }

        if (! $generationCancelled && ! $withPdf && $orderedIds !== [] && $fromStr !== '' && $toStr !== '') {
            $upsertStartedAt = microtime(true);
            $ids = Payslip::query()
                ->whereIn('user_id', $orderedIds)
                ->when($progressRun instanceof PayrollBatchRun, fn ($q) => $q->where('payroll_batch_run_id', (int) $progressRun->id))
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereDate('pay_period_start', $fromStr)
                ->whereDate('pay_period_end', $toStr)
                ->where('period_slot', 0)
                ->where('status', Payslip::STATUS_DRAFT)
                ->whereNull('voided_at')
                ->orderBy('user_id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $timings['bulk_upsert_ms'] += (microtime(true) - $upsertStartedAt) * 1000;
        }

        $sink = $timingSink ?? (object) [];
        $payloadTimings = [
            'load_employees_ms' => round($timings['load_employees_ms'], 2),
            'load_attendance_ms' => round((float) (($prefetchTimings['corrections_ms'] ?? 0) + ($prefetchTimings['ot_stub_ms'] ?? 0)), 2),
            'load_attendance_logs_ms' => round((float) ($prefetchTimings['logs_ms'] ?? 0), 2),
            'load_schedules_ms' => round((float) ($sink->load_schedules_ms ?? 0), 2),
            'load_pay_components_ms' => round((float) ($sink->load_pay_components_ms ?? 0), 2),
            'load_deductions_ms' => round((float) ($sink->load_deductions_ms ?? 0), 2),
            'load_contributions_ms' => round((float) ($sink->load_contributions_ms ?? 0), 2),
            'load_allowances_ms' => 0.0,
            'load_leaves_ms' => round((float) ($prefetchTimings['load_leaves_ms'] ?? 0), 2),
            'load_overtime_ms' => round((float) ($prefetchTimings['load_overtime_ms'] ?? 0), 2),
            'seed_attendance_log_cache_ms' => round((float) ($prefetchTimings['seed_attendance_log_cache_ms'] ?? 0), 2),
            'load_compute_context_ms' => round((float) ($prefetchTimings['load_compute_context_ms'] ?? 0), 2),
            'compute_loop_ms' => round(
                (float) ($sink->daily_iteration_ms ?? 0) + (float) ($sink->compute_loop_ms ?? 0),
                2
            ),
            'bulk_insert_payrolls_ms' => round($timings['bulk_upsert_ms'], 2),
            'bulk_insert_payroll_details_ms' => 0.0,
            'insert_payroll_rows_ms' => round($timings['bulk_upsert_ms'], 2),
            'insert_payroll_details_ms' => 0.0,
            'bulk_insert_ms' => round($timings['bulk_upsert_ms'], 2),
            'pdf_generation_ms' => $withPdf ? round($timings['generation_loop_ms'], 2) : 0.0,
            'total_job_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'total_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'employee_count' => $employeeCount,
            'attendance_rows_count' => $attendanceRowsCount,
            'pay_component_rows_count' => $payComponentRowsCount,
        ];

        Log::info('Payslip bulk generation completed', [
            'generated_count' => count($ids),
            'timings_ms' => $payloadTimings,
            'with_pdf' => $withPdf,
        ]);

        return [
            'payslip_ids' => $ids,
            'timings' => $payloadTimings,
            'failed_employees' => $failedEmployees,
            'employee_errors' => $employeeFailureMessages,
        ];
    }

    private function draftGenerationWasCancelled(?PayrollBatchRun $progressRun): bool
    {
        if (! $progressRun instanceof PayrollBatchRun) {
            return false;
        }

        $status = PayrollBatchRun::query()
            ->whereKey((int) $progressRun->id)
            ->value('status');

        return $status === null || (string) $status === PayrollBatchRun::STATUS_VOIDED;
    }

    public function generatePdf(Payslip $payslip, User $employee, ?string $plainPassword = null, ?string $relativeOverride = null): string
    {
        $employee->loadMissing(['company', 'branch', 'departmentRelation', 'governmentIds']);
        $company = $employee->company ?? ($payslip->company_id ? Company::query()->find($payslip->company_id) : null);

        $snapshotForView = $this->snapshotForPayslipRender($payslip, $employee);
        $this->logPayslipPdfContext($payslip, $employee, $snapshotForView, 'generatePdf');

        $html = view('payslips.pdf', [
            'payslip' => $payslip,
            'employee' => $employee,
            'company' => $company,
            'snapshot' => $snapshotForView,
            // Download should use the standard payslip layout.
            'printMode' => false,
            'govIds' => (object) $this->governmentIdFieldsForPayslip($employee),
            'employmentStatusLabel' => $this->employmentStatusLabelForPayslip($employee),
            'employmentTypeLabel' => $this->employmentTypeLabelForPayslip($employee),
        ])->render();
        $sanitized = $this->sanitizeHtmlForPdfRenderer($html);
        $this->logPayslipTableArraysBeforeWriteHtml($payslip, $employee, $snapshotForView, 'generatePdf', strlen($sanitized));
        $pdfBytes = $this->renderHtmlToPdfWithBrowsershot($sanitized, 'generatePdf');

        $relative = $relativeOverride ?? ('payslips/'.$payslip->id.'/payslip.pdf');
        Storage::disk('local')->makeDirectory(dirname($relative));
        Storage::disk('local')->put($relative, $pdfBytes);
        $full = storage_path('app/private/'.$relative);

        if ($plainPassword !== null && $plainPassword !== '') {
            try {
                $this->applyPdfPassword($full, $plainPassword);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $relative;
    }

    /**
     * Return an on-disk payslip PDF path (relative to storage/app/private), reusing {@see Payslip::$pdf_path}
     * when the file already exists — avoids a slow Browsershot pass for bulk ZIP exports after finalize.
     *
     * @param  bool  $forceRegenerate  When true, always run {@see generatePdf()} (e.g. HR explicitly wants a refresh).
     */
    public function ensurePayslipPdfOnDisk(Payslip $payslip, User $employee, bool $forceRegenerate = false): string
    {
        $relative = $payslip->pdf_path;
        if (! $forceRegenerate && is_string($relative) && trim($relative) !== '') {
            $full = storage_path('app/private/'.$relative);
            if (is_file($full)) {
                return $relative;
            }
        }

        $relative = $this->generatePdf($payslip, $employee);
        $payslip->forceFill(['pdf_path' => $relative])->saveQuietly();

        return $relative;
    }

    /**
     * Optional print-optimized PDF (same data, print CSS).
     */
    public function generatePrintPdf(Payslip $payslip, User $employee): string
    {
        $employee->loadMissing(['company', 'branch', 'departmentRelation', 'governmentIds']);
        $company = $employee->company ?? ($payslip->company_id ? Company::query()->find($payslip->company_id) : null);

        $snapshotForView = $this->snapshotForPayslipRender($payslip, $employee);
        $this->logPayslipPdfContext($payslip, $employee, $snapshotForView, 'generatePrintPdf');

        $html = view('payslips.pdf', [
            'payslip' => $payslip,
            'employee' => $employee,
            'company' => $company,
            'snapshot' => $snapshotForView,
            'printMode' => true,
            'govIds' => (object) $this->governmentIdFieldsForPayslip($employee),
            'employmentStatusLabel' => $this->employmentStatusLabelForPayslip($employee),
            'employmentTypeLabel' => $this->employmentTypeLabelForPayslip($employee),
        ])->render();
        $sanitized = $this->sanitizeHtmlForPdfRenderer($html);
        $this->logPayslipTableArraysBeforeWriteHtml($payslip, $employee, $snapshotForView, 'generatePrintPdf', strlen($sanitized));
        $pdfBytes = $this->renderHtmlToPdfWithBrowsershot($sanitized, 'generatePrintPdf');
        $relative = 'payslips/'.$payslip->id.'/payslip-print.pdf';
        Storage::disk('local')->makeDirectory(dirname($relative));
        Storage::disk('local')->put($relative, $pdfBytes);

        return $relative;
    }

    /**
     * Render payslip HTML to PDF using real Chromium via Browsershot.
     * This mirrors browser print output and avoids mPDF table pagination anomalies.
     */
    private function renderHtmlToPdfWithBrowsershot(string $html, string $stage): string
    {
        $tmp = storage_path('app/private/browsershot-temp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        try {
            $shot = Browsershot::html($html)
                ->format('A4')
                ->margins(10, 10, 10, 10)
                ->showBackground()
                ->setOption('printBackground', true)
                ->setOption('preferCSSPageSize', true)
                ->timeout(120)
                ->noSandbox()
                ->setTemporaryDirectory($tmp);

            $this->browsershotEnvironment->configure($shot);

            return $shot->pdf();
        } catch (Throwable $e) {
            $chromePath = $this->browsershotEnvironment->resolveChromePath();
            Log::error('Payslip PDF: Browsershot render failed', [
                'stage' => $stage,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'chrome_path' => $chromePath,
                'node_module_path' => $this->browsershotEnvironment->resolveNodeModulePath(),
            ]);
            throw $e;
        }
    }

    /**
     * Normalize rendered HTML to valid UTF-8 before PDF rendering.
     * Some legacy DB text may include malformed byte sequences.
     */
    private function sanitizeHtmlForPdfRenderer(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // mPDF reserves `{colsum…}` and `{iteration …}` inside table rendering; employee/HR text that
        // contains those substrings can trigger PHP 8+ notices/errors in mPDF (e.g. undefined offsets).
        // Encode the opening brace so the PDF still shows `{…}` but mPDF does not treat them as controls.
        $html = preg_replace_callback('/\{colsum[0-9_]*\}/i', static function (array $m): string {
            return '&#123;'.substr($m[0], 1);
        }, $html) ?? $html;
        $html = preg_replace_callback('/\{iteration\s+([a-zA-Z0-9_]+)\}/', static function (array $m): string {
            return '&#123;iteration '.$m[1].'}';
        }, $html) ?? $html;

        // Strip BOM; remove C0 controls except TAB/LF/CR (can break HTML/XML parsers).
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html) ?? $html;
        $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html) ?? $html;

        // Same coercion mPDF uses when ignore_invalid_utf8 is on: UTF-8 → UTF-32 → UTF-8.
        if (function_exists('mb_convert_encoding')) {
            try {
                $html = mb_convert_encoding(mb_convert_encoding($html, 'UTF-32', 'UTF-8'), 'UTF-8', 'UTF-32');
            } catch (\Throwable) {
                // keep $html; iconv pass below may still help
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $html);
        if (is_string($clean) && $clean !== '') {
            return $clean;
        }

        return is_string($html) ? $html : '';
    }

    /**
     * Normalize a stored/generated payslip snapshot before returning it to UI or PDF rendering.
     *
     * Stored snapshots can outlive payroll logic fixes, so this view-layer normalization also repairs
     * Regular Pay from daily computation days when the saved line still says full scheduled days.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeSnapshotForPayslipView(array $snapshot): array
    {
        return $this->normalizeSnapshotForPayslipPdf($snapshot);
    }

    /**
     * Frozen finalized snapshots render stored line values, while hiding zero off-cycle
     * deduction placeholders that are not scheduled for this payroll date.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function frozenSnapshotForPayslipView(array $snapshot): array
    {
        $out = $snapshot;
        $summary = is_array($out['summary'] ?? null) ? $out['summary'] : [];
        $summary = $this->coerceSummaryTableArraysToZeroIndexedLists($summary);
        if ($this->isExecomSnapshot($out, $summary)) {
            $summary = $this->sanitizeExecomPayslipSummary($summary, $out);
        } elseif ($this->isConsultantSnapshot($out, $summary)) {
            $summary = $this->sanitizeConsultantPayslipSummary($summary, $out);
        }
        $summary['payslip_custom_deduction_lines'] = $this->normalizePayslipCustomDeductionLines(
            $summary['payslip_custom_deduction_lines'] ?? [],
            $summary
        );
        $out['summary'] = $summary;

        return $out;
    }

    /**
     * Recompute a stored payslip's snapshot for rendering when possible.
     *
     * This is intentionally used for preview/download rendering so stale generated snapshots
     * (for example a saved "2 days" Regular Pay line) are corrected from the current attendance
     * and daily-computation logic for the same pay period.
     *
     * @return array<string, mixed>
     */
    public function snapshotForPayslipRender(Payslip $payslip, User $employee): array
    {
        $snapshotRaw = $payslip->snapshot ?? [];
        $isExecomPayslip = (string) ($payslip->payroll_module ?? '') === PayrollBatchRun::MODULE_EXECOM;
        if ($isExecomPayslip && ! in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
            try {
                $live = $this->previewDataForEmployee($employee, $this->periodInputFromPayslip($payslip));
                $snapshot = is_array($live['snapshot'] ?? null) ? $live['snapshot'] : [];
                if ($snapshot !== []) {
                    return $this->normalizeSnapshotForPayslipView(
                        $this->snapshotWithPayrollModule($snapshot, PayrollBatchRun::MODULE_EXECOM)
                    );
                }
            } catch (Throwable $e) {
                Log::warning('EXECOM payslip render: live fixed-pay recomputation failed, falling back to stored snapshot', [
                    'payslip_id' => (int) $payslip->id,
                    'user_id' => (int) $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_array($snapshotRaw) && $snapshotRaw !== []) {
            $snapshotRaw = $this->snapshotWithPayrollModule($snapshotRaw, (string) ($payslip->payroll_module ?? ''));
            if (in_array((string) $payslip->status, Payslip::lockingStatuses(), true)) {
                return $this->frozenSnapshotForPayslipView($snapshotRaw);
            }

            return $this->normalizeSnapshotForPayslipView($snapshotRaw);
        }

        try {
            $live = $this->previewDataForEmployee($employee, $this->periodInputFromPayslip($payslip));
            $snapshot = is_array($live['snapshot'] ?? null) ? $live['snapshot'] : [];
            if ($snapshot !== []) {
                return $this->normalizeSnapshotForPayslipView($snapshot);
            }
        } catch (Throwable $e) {
            Log::warning('Payslip render: live recomputation failed, falling back to stored snapshot', [
                'payslip_id' => (int) $payslip->id,
                'user_id' => (int) $employee->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->normalizeSnapshotForPayslipView(
            $this->snapshotWithPayrollModule(is_array($snapshotRaw) ? $snapshotRaw : [], (string) ($payslip->payroll_module ?? ''))
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function snapshotWithPayrollModule(array $snapshot, string $payrollModule): array
    {
        $module = $this->normalizePayrollModule($payrollModule);
        if ($module === '') {
            return $snapshot;
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summary['payroll_module'] = $module;
        $snapshot['summary'] = $summary;
        $snapshot['payroll_module'] = $module;

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function periodInputFromPayslip(Payslip $payslip): array
    {
        return [
            'from_date' => $payslip->pay_period_start?->toDateString(),
            'to_date' => $payslip->pay_period_end?->toDateString(),
            'pay_cycle_id' => $payslip->pay_cycle_id !== null ? (int) $payslip->pay_cycle_id : null,
            'reference_date' => $payslip->pay_date?->toDateString(),
            'payroll_module' => $this->normalizePayrollModule((string) ($payslip->payroll_module ?? PayrollBatchRun::MODULE_STANDARD)),
            'payroll_batch_run_id' => $payslip->payroll_batch_run_id !== null ? (int) $payslip->payroll_batch_run_id : null,
            'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
            'branch_id' => $payslip->branch_id !== null ? (int) $payslip->branch_id : null,
            'department_id' => $payslip->department_id !== null ? (int) $payslip->department_id : null,
            'use_company_default' => $payslip->pay_cycle_id === null,
            'payroll_period_id' => $payslip->payroll_period_id !== null ? (int) $payslip->payroll_period_id : null,
            'is_final_pay' => (bool) $payslip->is_final_pay,
            'password_protect' => false,
        ];
    }

    /**
     * Immutable metrics from the exact stored payslip snapshot lines. Used by finalized
     * payroll tables, reports, validation, and cards so they all read the same frozen source.
     *
     * @return array{
     *   line_count:int,
     *   gross_pay:float,
     *   total_deductions:float,
     *   net_pay:float,
     *   regular_pay:float,
     *   holiday_pay:float,
     *   overtime_pay:float,
     *   night_differential:float,
     *   paid_leave:float,
     *   allowances:float,
     *   other_deductions:float,
     *   line_ids:list<string>,
     *   categories:list<string>,
     *   category_totals:array<string,float>
     * }
     */
    public function frozenPayslipLineMetrics(Payslip $payslip): array
    {
        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            $snapshot = [];
        }
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];

        $isExecom = (string) ($payslip->payroll_module ?? '') === PayrollBatchRun::MODULE_EXECOM
            || $this->isExecomSnapshot($snapshot, $summary);
        $isConsultant = $this->isConsultantSnapshot($snapshot, $summary);
        if ($isExecom) {
            $summary = $this->sanitizeExecomPayslipSummary($summary, $snapshot);
        } elseif ($isConsultant) {
            $summary = $this->sanitizeConsultantPayslipSummary($summary, $snapshot);
        }

        $earningLines = ($isExecom || $isConsultant)
            ? $this->rawPayslipLineList($summary['payslip_earning_lines'] ?? [])
            : array_values(array_merge(
                $this->rawPayslipLineList($summary['daily_computation_earning_lines'] ?? []),
                $this->rawPayslipLineList($summary['payslip_earning_lines'] ?? [])
            ));
        $deductionLines = array_values(array_merge(
            $this->rawPayslipLineList($summary['payslip_deduction_lines'] ?? []),
            $this->normalizePayslipCustomDeductionLines($summary['payslip_custom_deduction_lines'] ?? [], $summary)
        ));

        $categoryTotals = [];
        $lineIds = [];
        foreach ($earningLines as $idx => $line) {
            $category = $this->payrollLineCategory($line, true);
            $categoryTotals[$category] = round(($categoryTotals[$category] ?? 0.0) + max(0.0, (float) ($line['amount'] ?? 0)), 2);
            $lineIds[] = $this->payrollLineIdentifier($line, 'earning', $idx);
        }
        foreach ($deductionLines as $idx => $line) {
            $category = $this->payrollLineCategory($line, false);
            $categoryTotals[$category] = round(($categoryTotals[$category] ?? 0.0) + max(0.0, (float) ($line['amount'] ?? 0)), 2);
            $lineIds[] = $this->payrollLineIdentifier($line, 'deduction', $idx);
        }

        $grossFromLines = $this->sumPayslipLineAmounts($earningLines);
        $deductionsFromLines = $this->sumPayslipLineAmounts($deductionLines);
        $hasLines = count($earningLines) + count($deductionLines) > 0;
        $gross = $hasLines ? $grossFromLines : round((float) ($payslip->gross_pay ?? 0), 2);
        $deductions = $hasLines ? $deductionsFromLines : round((float) ($payslip->total_deductions ?? 0), 2);
        $net = $hasLines ? round($gross - $deductions, 2) : round((float) ($payslip->net_pay ?? 0), 2);

        $otherDeductions = 0.0;
        foreach (['deduction', 'loan', 'cash_advance', 'late_deduction', 'undertime_deduction', 'absence_deduction'] as $category) {
            $otherDeductions += (float) ($categoryTotals[$category] ?? 0.0);
        }

        return [
            'line_count' => count($earningLines) + count($deductionLines),
            'gross_pay' => round($gross, 2),
            'total_deductions' => round($deductions, 2),
            'net_pay' => round($net, 2),
            'regular_pay' => round((float) ($categoryTotals['regular_pay'] ?? 0.0), 2),
            'holiday_pay' => round((float) ($categoryTotals['holiday_pay'] ?? 0.0), 2),
            'overtime_pay' => round((float) ($categoryTotals['overtime_pay'] ?? 0.0), 2),
            'night_differential' => round((float) ($categoryTotals['night_differential'] ?? 0.0), 2),
            'paid_leave' => round((float) ($categoryTotals['paid_leave'] ?? 0.0), 2),
            'allowances' => round((float) ($categoryTotals['allowance'] ?? 0.0), 2),
            'other_deductions' => round($otherDeductions, 2),
            'line_ids' => array_values(array_unique($lineIds)),
            'categories' => array_values(array_unique(array_keys($categoryTotals))),
            'category_totals' => $categoryTotals,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawPayslipLineList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function payrollLineIdentifier(array $line, string $section, int $index): string
    {
        $payComponentId = $this->resolvePayComponentIdFromLine($line);
        if ($payComponentId !== null) {
            return $section.':key:pay_component:'.$payComponentId;
        }

        foreach (['source_id', 'id', 'loan_id', 'deduction_id', 'key'] as $field) {
            $value = $line[$field] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return $section.':'.$field.':'.trim((string) $value);
            }
        }

        $label = trim((string) ($line['label'] ?? $line['name'] ?? 'line'));

        return $section.':'.$index.':'.$label;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolvePayComponentIdFromLine(array $line): ?int
    {
        if (isset($line['pay_component_id']) && is_numeric($line['pay_component_id'])) {
            $id = (int) $line['pay_component_id'];

            return $id > 0 ? $id : null;
        }

        $key = trim((string) ($line['key'] ?? ''));
        if (preg_match('/^pay_component:(\d+)$/i', $key, $matches)) {
            $id = (int) $matches[1];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Stable deduction catalog key — aligned with payroll line identity (pay_component:id first).
     *
     * @param  array<string, mixed>  $line
     */
    private function deductionCatalogLineKey(array $line, int $index): string
    {
        $key = trim((string) ($line['key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        $payComponentId = $this->resolvePayComponentIdFromLine($line);
        if ($payComponentId !== null) {
            return 'pay_component:'.$payComponentId;
        }

        $componentCode = trim((string) ($line['component_code'] ?? $line['code'] ?? ''));
        if ($componentCode !== '') {
            return $componentCode;
        }

        return 'line:'.$index.':'.trim((string) ($line['label'] ?? $line['name'] ?? 'deduction'));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function payrollLineCategory(array $line, bool $earning): string
    {
        $explicit = $this->normalizePayrollLineCategory($line['category'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $key = strtolower(trim((string) ($line['key'] ?? '')));
        $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));
        $haystack = $key.' '.$label;

        if ($earning) {
            if (str_contains($haystack, 'regular_pay') || str_contains($label, 'regular pay')) {
                return 'regular_pay';
            }
            if (str_contains($haystack, 'holiday_premium') || str_contains($haystack, 'holiday pay') || str_contains($haystack, 'holiday premium')) {
                return 'holiday_pay';
            }
            if (str_contains($haystack, 'ot_pay') || str_contains($haystack, 'overtime')) {
                return 'overtime_pay';
            }
            if (str_contains($haystack, 'nd_pay') || str_contains($haystack, 'night_diff') || str_contains($haystack, 'night differential')) {
                return 'night_differential';
            }
            if (str_contains($haystack, 'paid_leave') || str_contains($haystack, 'leave adjustment') || str_contains($haystack, 'paid leave')) {
                return 'paid_leave';
            }
            if (str_contains($haystack, 'allowance')) {
                return 'allowance';
            }
            if (str_contains($haystack, 'late') || str_contains($haystack, 'tardy')) {
                return 'late_deduction';
            }
            if (str_contains($haystack, 'undertime')) {
                return 'undertime_deduction';
            }
            if (str_contains($haystack, 'absence') || str_contains($haystack, 'absent')) {
                return 'absence_deduction';
            }

            return 'other_earning';
        }

        if (str_contains($haystack, 'sss') || str_contains($haystack, 'philhealth') || str_contains($haystack, 'pag-ibig')
            || str_contains($haystack, 'pagibig') || str_contains($haystack, 'withholding') || str_contains($haystack, 'wht')) {
            return 'government_deduction';
        }
        if (str_contains($haystack, 'loan')) {
            return 'loan';
        }
        if (str_contains($haystack, 'cash advance') || str_contains($haystack, 'cash_advance')) {
            return 'cash_advance';
        }
        if (str_contains($haystack, 'late') || str_contains($haystack, 'tardy')) {
            return 'late_deduction';
        }
        if (str_contains($haystack, 'undertime')) {
            return 'undertime_deduction';
        }
        if (str_contains($haystack, 'absence') || str_contains($haystack, 'absent')) {
            return 'absence_deduction';
        }

        return 'deduction';
    }

    private function normalizePayrollLineCategory(mixed $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        $value = strtolower(trim($category));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return match ($value) {
            'regular', 'regular_pay', 'basic_salary', 'basic_pay' => 'regular_pay',
            'holiday', 'holiday_pay', 'holiday_premium' => 'holiday_pay',
            'overtime', 'ot', 'ot_pay', 'overtime_premium' => 'overtime_pay',
            'night_diff', 'night_differential', 'night_differential_pay', 'nd_pay' => 'night_differential',
            'leave', 'paid_leave', 'paid_leave_daily_flat' => 'paid_leave',
            'allowance', 'fixed_allowance' => 'allowance',
            'other_earning', 'earning' => 'other_earning',
            'government', 'government_deduction', 'statutory', 'statutory_deduction' => 'government_deduction',
            'loan' => 'loan',
            'cash_advance' => 'cash_advance',
            'late', 'late_deduction' => 'late_deduction',
            'undertime', 'undertime_deduction' => 'undertime_deduction',
            'absence', 'absence_deduction' => 'absence_deduction',
            'deduction', 'other_deduction' => 'deduction',
            default => null,
        };
    }

    /**
     * Coerce payslip snapshot.summary line arrays for mPDF: 0-based lists, scalar fields only, no junk rows.
     * Stored DB snapshot is unchanged; this is used only when rendering the Blade PDF template.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function payslipLineTotalsFromNormalizedSnapshot(array $snapshot): array
    {
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $isExecom = $this->isExecomSnapshot($snapshot, $summary);
        $isConsultant = $this->isConsultantSnapshot($snapshot, $summary);
        if ($isExecom) {
            $summary = $this->sanitizeExecomPayslipSummary($summary, $snapshot);
        } elseif ($isConsultant) {
            $summary = $this->sanitizeConsultantPayslipSummary($summary, $snapshot);
        }

        $earningLines = ($isExecom || $isConsultant)
            ? (is_array($summary['payslip_earning_lines'] ?? null) ? array_values($summary['payslip_earning_lines']) : [])
            : array_values(array_merge(
                is_array($summary['daily_computation_earning_lines'] ?? null) ? $summary['daily_computation_earning_lines'] : [],
                is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : []
            ));
        $deductionLines = array_values(array_merge(
            is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [],
            $this->normalizePayslipCustomDeductionLines($summary['payslip_custom_deduction_lines'] ?? [], $summary)
        ));

        $gross = $this->sumPayslipLineAmounts($earningLines);
        $deductions = $this->sumPayslipLineAmounts($deductionLines);

        return [
            'gross_pay' => $gross,
            'total_deductions' => $deductions,
            'net_pay' => round($gross - $deductions, 2),
            'earning_lines' => $earningLines,
            'deduction_lines' => $deductionLines,
        ];
    }

    private function sumPayslipLineAmounts(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $amount = $line['amount'] ?? $line['resolved_amount'] ?? null;
            if (! is_numeric($amount)) {
                continue;
            }
            $total += max(0.0, (float) $amount);
        }

        return round($total, 2);
    }

    /**
     * @param  array{gross_pay: float, total_deductions: float, net_pay: float, earning_lines: list<array<string, mixed>>, deduction_lines: list<array<string, mixed>>}  $lineTotals
     */
    private function snapshotWithPayslipLineTotals(array $snapshot, array $lineTotals): array
    {
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $summary['gross_pay_this_period'] = $lineTotals['gross_pay'];
        $summary['total_deductions_this_period'] = $lineTotals['total_deductions'];
        $summary['net_pay_after_withholding_estimate'] = $lineTotals['net_pay'];
        $snapshot['summary'] = $summary;

        return $snapshot;
    }

    /**
     * @param  array{gross_pay: float, total_deductions: float, net_pay: float, earning_lines: list<array<string, mixed>>, deduction_lines: list<array<string, mixed>>}  $lineTotals
     */
    private function logPayslipLineSummaryMismatchIfNeeded(
        ?User $employee,
        ?Payslip $payslip,
        float $summaryGross,
        float $summaryDeductions,
        float $summaryNet,
        array $lineTotals
    ): void {
        $grossDiff = round($summaryGross - $lineTotals['gross_pay'], 2);
        $deductionDiff = round($summaryDeductions - $lineTotals['total_deductions'], 2);
        $netDiff = round($summaryNet - $lineTotals['net_pay'], 2);
        $isTargetEmployee = $employee instanceof User && trim((string) $employee->employee_code) === 'EMP-1703';
        $hasMismatch = abs($grossDiff) >= 0.01 || abs($deductionDiff) >= 0.01 || abs($netDiff) >= 0.01;

        if (! $hasMismatch && ! $isTargetEmployee) {
            return;
        }

        $logLevel = $hasMismatch ? 'error' : 'debug';
        Log::log($logLevel, 'Payslip line totals reconciled against summary totals', [
            'payslip_id' => $payslip?->id !== null ? (int) $payslip->id : null,
            'payroll_employee_id' => $payslip?->id !== null ? (int) $payslip->id : null,
            'user_id' => $employee?->id !== null ? (int) $employee->id : ($payslip?->user_id !== null ? (int) $payslip->user_id : null),
            'employee_code' => $employee?->employee_code,
            'gross_from_lines' => $lineTotals['gross_pay'],
            'gross_from_summary' => $summaryGross,
            'deductions_from_lines' => $lineTotals['total_deductions'],
            'deductions_from_summary' => $summaryDeductions,
            'net_from_lines' => $lineTotals['net_pay'],
            'net_from_summary' => $summaryNet,
            'difference' => [
                'gross' => $grossDiff,
                'deductions' => $deductionDiff,
                'net' => $netDiff,
            ],
            'all_earning_lines' => $lineTotals['earning_lines'],
            'all_deduction_lines' => $lineTotals['deduction_lines'],
        ]);
    }

    private function normalizeSnapshotForPayslipPdf(array $snapshot): array
    {
        $out = $snapshot;
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $isExecomSnapshot = $this->isExecomSnapshot($snapshot, $summary);
        $isConsultantSnapshot = $this->isConsultantSnapshot($snapshot, $summary);

        $dailyRate = (float) ($summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0));
        $regularHourlyRate = $dailyRate > 0 ? ($dailyRate / 8.0) : null;
        $dailyComputationDays = $this->cleanDailyComputationDays($snapshot['daily_computation_days'] ?? null);
        if ($dailyComputationDays !== [] && ! $isExecomSnapshot && ! $isConsultantSnapshot) {
            $out['daily_computation_days'] = $dailyComputationDays;
            $summary = $this->repairRegularPayLineFromDailyComputationDays($summary, $dailyComputationDays, $regularHourlyRate);
        } elseif ($isExecomSnapshot || $isConsultantSnapshot) {
            $out['daily_computation_days'] = [];
        }

        $summary['payslip_earning_lines'] = $this->normalizePayslipLineList(
            $summary['payslip_earning_lines'] ?? [],
            'Earning',
            false,
            false,
            $regularHourlyRate
        );
        $summary['daily_computation_earning_lines'] = $this->normalizePayslipLineList(
            $summary['daily_computation_earning_lines'] ?? [],
            'Daily computation earning',
            false,
            false,
            $regularHourlyRate
        );
        if ($isExecomSnapshot) {
            $summary = $this->sanitizeExecomPayslipSummary($summary);
        } elseif ($isConsultantSnapshot) {
            $summary = $this->sanitizeConsultantPayslipSummary($summary, $out);
        }
        $summary['payslip_deduction_lines'] = $this->normalizePayslipLineList(
            $summary['payslip_deduction_lines'] ?? [],
            'Deduction',
            false,
            true
        );
        $summary['payslip_custom_deduction_lines'] = $this->normalizePayslipCustomDeductionLines($summary['payslip_custom_deduction_lines'] ?? [], $summary);
        $grossFromShownEarnings = $this->sumPayslipLineAmounts(array_merge(
            $summary['daily_computation_earning_lines'],
            $summary['payslip_earning_lines']
        ));
        $summary['deduction_lines_exceed_gross'] = $this->sumPayslipLineAmounts(array_merge(
            $summary['payslip_deduction_lines'],
            $summary['payslip_custom_deduction_lines']
        )) > $grossFromShownEarnings;

        $holiday = [];
        foreach (is_array($summary['holiday_premium_breakdown'] ?? null) ? $summary['holiday_premium_breakdown'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $holiday[] = [
                'holiday_name' => $this->sanitizePayslipText((string) ($item['holiday_name'] ?? 'Holiday')) ?: 'Holiday',
                'amount' => (float) ($item['amount'] ?? 0),
                'date' => $item['date'] ?? null,
            ];
        }
        $summary['holiday_premium_breakdown'] = array_values($holiday);

        $ads = is_array($summary['attendance_display_summary'] ?? null)
            ? $summary['attendance_display_summary']
            : [
                'working_days_count' => 0,
                'presence_days_count' => 0,
                'lines' => [],
                'total_regular_hours' => 0.0,
                'total_presence_regular_hours' => 0.0,
            ];
        $lines = is_array($ads['lines'] ?? null) ? $ads['lines'] : [];
        $cleanLines = [];
        foreach ($lines as $line) {
            if (is_array($line)) {
                $cleanLines[] = $line;
            }
        }
        $ads['lines'] = array_values($cleanLines);
        $ads['working_days_count'] = (int) ($ads['working_days_count'] ?? 0);
        $ads['presence_days_count'] = (int) ($ads['presence_days_count'] ?? $ads['working_days_count'] ?? 0);
        $ads['total_regular_hours'] = (float) ($ads['total_regular_hours'] ?? 0);
        $ads['total_presence_regular_hours'] = (float) ($ads['total_presence_regular_hours'] ?? $ads['total_regular_hours'] ?? 0);
        $summary['attendance_display_summary'] = $ads;

        $summary = $this->coerceSummaryTableArraysToZeroIndexedLists($summary);

        $out['summary'] = $summary;

        return $out;
    }

    /**
     * EXECOM payslips use a fixed Basic Pay. They must never render attendance-derived
     * daily computation earnings such as "Regular pay".
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function sanitizeExecomPayslipSummary(array $summary, array $snapshot = []): array
    {
        $lines = [];
        $basicAmount = $this->resolveExecomBasicPayDisplayAmount($summary);

        if ($basicAmount > 0.0) {
            $lines[] = [
                'key' => 'execom_basic_pay',
                'label' => 'Basic Pay',
                'name' => 'Basic Pay',
                'category' => 'basic_pay',
                'component_code' => 'BASIC_SALARY',
                'amount' => $basicAmount,
                'resolved_amount' => $basicAmount,
            ];
        }

        foreach (is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [] as $line) {
            if (! is_array($line) || $this->isExecomAttendanceBasedEarningLine($line) || $this->isExecomBasicPayLine($line)) {
                continue;
            }
            if (! $this->execomPayslipLineScheduleApplies($line, $summary, $snapshot, true)) {
                continue;
            }

            $amount = round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
            if ($amount <= 0.0) {
                continue;
            }

            $line['amount'] = $amount;
            $lines[] = $line;
        }

        $lines = $this->deduplicatePayslipLines($lines);
        $summary['daily_computation_earning_lines'] = [];
        $summary['daily_computation_days'] = [];
        $summary['payslip_earning_lines'] = array_values($lines);
        $summary['payslip_custom_deduction_lines'] = $this->filterExecomScheduledDeductionLines(
            $summary['payslip_custom_deduction_lines'] ?? [],
            $summary,
            $snapshot
        );
        $summary['basic_pay'] = $basicAmount;
        $summary['basic_pay_this_period'] = $basicAmount;
        $summary['basic_salary_period'] = $basicAmount;
        $summary['basic_salary'] = $basicAmount;
        $summary['total_pay'] = $basicAmount;
        $summary['attendance_premium_pay_this_period'] = 0.0;
        $summary['attendance_deduction'] = 0.0;
        $summary['leave_deduction'] = 0.0;
        $summary['late_minutes'] = 0;
        $summary['undertime_minutes'] = 0;
        $summary['absent_days'] = 0;

        return $summary;
    }

    /**
     * Consultants stay in Regular Payroll, but their salary line is fixed-pay like EXECOM:
     * no attendance units, no daily Regular pay, and no holiday/OT/ND/leave earning lines.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function sanitizeConsultantPayslipSummary(array $summary, array $snapshot = []): array
    {
        $lines = [];
        $basicAmount = $this->resolveConsultantBasicPayDisplayAmount($summary);

        if ($basicAmount > 0.0) {
            $lines[] = [
                'key' => 'consultant_basic_pay',
                'label' => 'Basic Pay',
                'name' => 'Basic Pay',
                'category' => 'basic_pay',
                'component_code' => 'BASIC_SALARY',
                'amount' => $basicAmount,
                'resolved_amount' => $basicAmount,
                'units' => null,
                'minutes_worked' => null,
                'hourly_rate' => null,
                'fixed_payroll' => true,
                'attendance_based' => false,
                'metadata' => [
                    'employment_status' => 'consultant',
                    'consultant_fixed_payroll' => true,
                    'salary_source_used' => (string) ($summary['consultant_salary_basis'] ?? ''),
                ],
            ];
        }

        foreach (is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [] as $line) {
            if (
                ! is_array($line)
                || $this->isExecomBasicPayLine($line)
                || $this->isConsultantSuppressedEarningLine($line)
            ) {
                continue;
            }

            $amount = round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
            if ($amount <= 0.0) {
                continue;
            }

            $line['amount'] = $amount;
            $lines[] = $line;
        }

        $lines = $this->deduplicatePayslipLines($lines);
        $summary['daily_computation_earning_lines'] = [];
        $summary['daily_computation_days'] = [];
        $summary['holiday_premium_breakdown'] = [];
        $summary['payslip_earning_lines'] = array_values($lines);
        $summary['basic_pay'] = $basicAmount;
        $summary['basic_pay_this_period'] = $basicAmount;
        $summary['basic_salary_period'] = $basicAmount;
        $summary['basic_salary'] = $basicAmount;
        $summary['total_pay'] = $basicAmount;
        $summary['attendance_premium_pay_this_period'] = 0.0;
        $summary['attendance_status'] = 'Auto Present';
        $summary['attendance_deduction'] = 0.0;
        $summary['attendance_salary_deduction'] = 0.0;
        $summary['leave_deduction'] = 0.0;
        $summary['late_minutes'] = 0;
        $summary['undertime_minutes'] = 0;
        $summary['absent_days'] = 0;
        $summary['total_worked_minutes'] = 0;
        $summary['total_regular_day_minutes'] = 0;
        $summary['total_regular_night_minutes'] = 0;
        $summary['total_ot_day_minutes'] = 0;
        $summary['total_ot_night_minutes'] = 0;
        $summary['overtime_total_hours'] = 0.0;
        $summary['overtime_total_amount'] = 0.0;

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function deduplicatePayslipLines(array $lines): array
    {
        $seen = [];
        $out = [];
        foreach ($lines as $line) {
            $key = implode('|', [
                strtolower(trim((string) ($line['component_code'] ?? $line['code'] ?? ''))),
                strtolower(trim((string) ($line['pay_component_id'] ?? ''))),
                strtolower(trim((string) ($line['label'] ?? $line['name'] ?? ''))),
                number_format(round((float) ($line['amount'] ?? $line['resolved_amount'] ?? 0), 2), 2, '.', ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $line;
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterExecomScheduledDeductionLines(mixed $raw, array $summary, array $snapshot): array
    {
        $rows = is_array($raw) ? $raw : [];
        $run = $this->resolveExecomPayslipRunType($summary, $snapshot);
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! $this->execomPayslipLineScheduleApplies($row, $summary, $snapshot, false, $run)) {
                continue;
            }

            $out[] = $row;
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function execomPayslipLineScheduleApplies(
        array $line,
        array $summary,
        array $snapshot,
        bool $earning,
        ?string $resolvedRun = null
    ): bool {
        $schedule = $this->normalizePayslipScheduleValue(
            $line['resolved_schedule']
                ?? $line['component_schedule']
                ?? ($earning ? ($line['earning_schedule_type'] ?? null) : ($line['deduction_schedule_type'] ?? null))
                ?? $line['schedule']
                ?? null
        );
        $inferredSchedule = $this->inferPayslipScheduleFromLineText($line);
        if (($schedule === null || $schedule === 'both') && $inferredSchedule !== null) {
            $schedule = $inferredSchedule;
        }
        if ($schedule === null || $schedule === 'both') {
            return true;
        }

        $rowRun = $this->normalizePayslipRunType(
            $line['payroll_run_type']
                ?? data_get($line, 'pay_component_resolution.payroll_run_type')
                ?? null
        ) ?? $resolvedRun ?? $this->resolveExecomPayslipRunType($summary, $snapshot);

        if ($rowRun === null) {
            return true;
        }

        return ($schedule === '15th' && $rowRun === '15th')
            || ($schedule === '30th' && $rowRun === '30th');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function inferPayslipScheduleFromLineText(array $line): ?string
    {
        $text = strtolower(preg_replace(
            '/[^a-z0-9]+/',
            '_',
            implode(' ', [
                (string) ($line['component_code'] ?? ''),
                (string) ($line['code'] ?? ''),
                (string) ($line['label'] ?? ''),
                (string) ($line['name'] ?? ''),
            ])
        ) ?? '');
        $text = trim($text, '_');
        if ($text === '') {
            return null;
        }

        $has15 = (bool) preg_match('/(^|_)every_?15(_|$)|(^|_)15(th)?(_|$)/', $text);
        $has30 = (bool) preg_match('/(^|_)every_?30(_|$)|(^|_)30(th)?(_|$)|end_?of_?month|(^|_)eom(_|$)/', $text);

        if ($has15 && $has30) {
            return 'both';
        }
        if ($has15) {
            return '15th';
        }
        if ($has30) {
            return '30th';
        }

        return null;
    }

    private function resolveExecomPayslipRunType(array $summary, array $snapshot): ?string
    {
        $candidate = data_get($summary, 'deduction_schedule.current_payroll_run_type')
            ?? data_get($summary, 'deduction_schedule.payroll_run_type')
            ?? data_get($summary, 'deduction_schedule.semi_monthly_period')
            ?? data_get($summary, 'semi_monthly_period');
        $run = $this->normalizePayslipRunType($candidate);
        if ($run !== null) {
            return $run;
        }

        $payDate = data_get($snapshot, 'pay_cycle_preview.pay_date')
            ?? data_get($summary, 'pay_date')
            ?? data_get($snapshot, 'pay_date')
            ?? data_get($snapshot, 'selected_pay_date');
        if (is_string($payDate) && trim($payDate) !== '') {
            try {
                return Carbon::parse($payDate)->day <= 15 ? '15th' : '30th';
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $summary
     */
    private function isExecomSnapshot(array $snapshot, array $summary): bool
    {
        $module = strtolower(trim((string) ($summary['payroll_module'] ?? $snapshot['payroll_module'] ?? '')));
        if ($module === PayrollBatchRun::MODULE_EXECOM) {
            return true;
        }

        if (! empty($summary['execom_badge']) || ! empty($summary['execom_profile_id']) || ! empty($summary['execom_salary_source_used'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $summary
     */
    private function isConsultantSnapshot(array $snapshot, array $summary): bool
    {
        if (! empty($summary['consultant_fixed_payroll'])) {
            return true;
        }

        foreach (['employment_status', 'employment_type'] as $key) {
            $value = strtolower(trim(str_replace(['-', ' '], '_', (string) ($summary[$key] ?? $snapshot[$key] ?? ''))));
            if ($value === 'consultant') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveConsultantBasicPayDisplayAmount(array $summary): float
    {
        foreach (['basic_pay_this_period', 'basic_salary_period', 'basic_pay', 'total_pay'] as $key) {
            $amount = round(max(0.0, (float) ($summary[$key] ?? 0)), 2);
            if ($amount > 0.0) {
                return $amount;
            }
        }

        foreach (is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [] as $line) {
            if (is_array($line) && $this->isExecomBasicPayLine($line)) {
                $amount = round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
                if ($amount > 0.0) {
                    return $amount;
                }
            }
        }

        foreach (is_array($summary['daily_computation_earning_lines'] ?? null) ? $summary['daily_computation_earning_lines'] : [] as $line) {
            if (is_array($line) && $this->isExecomBasicPayLine($line)) {
                $amount = round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
                if ($amount > 0.0) {
                    return $amount;
                }
            }
        }

        return round(max(0.0, (float) ($summary['consultant_fixed_salary'] ?? 0)), 2);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function resolveExecomBasicPayDisplayAmount(array $summary): float
    {
        foreach (is_array(data_get($summary, 'deduction_schedule.earning_lines')) ? data_get($summary, 'deduction_schedule.earning_lines') : [] as $line) {
            if (! is_array($line) || empty($line['is_basic_salary_line'])) {
                continue;
            }

            $amount = round(max(0.0, (float) (
                $line['scheduled_this_period']
                ?? data_get($line, 'pay_component_resolution.applied_amount')
                ?? 0
            )), 2);
            if ($amount > 0.0) {
                return $amount;
            }
        }

        foreach (['basic_pay_this_period', 'basic_salary_period'] as $key) {
            $amount = round(max(0.0, (float) ($summary[$key] ?? 0)), 2);
            if ($amount > 0.0) {
                return $amount;
            }
        }

        foreach (is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [] as $line) {
            if (is_array($line) && $this->isExecomBasicPayLine($line)) {
                $amount = round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
                if ($amount > 0.0) {
                    return $amount;
                }
            }
        }

        foreach (['basic_pay', 'total_pay', 'basic_salary'] as $key) {
            $amount = round(max(0.0, (float) ($summary[$key] ?? 0)), 2);
            if ($amount > 0.0) {
                return $amount;
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isExecomBasicPayLine(array $line): bool
    {
        $code = strtoupper(trim((string) ($line['component_code'] ?? $line['code'] ?? $line['key'] ?? '')));
        $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));

        return $code === 'BASIC_SALARY'
            || str_contains($code, 'BASIC_SALARY')
            || $label === 'basic pay'
            || $label === 'basic salary';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isExecomAttendanceBasedEarningLine(array $line): bool
    {
        $key = strtolower(trim((string) ($line['key'] ?? '')));
        $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));
        $category = strtolower(trim((string) ($line['category'] ?? '')));

        return str_contains($key, 'regular_pay')
            || $label === 'regular pay'
            || $category === 'regular_pay'
            || str_contains($key, 'attendance_premium')
            || str_contains($key, 'holiday_premium')
            || str_contains($key, 'ot_pay')
            || str_contains($key, 'nd_pay');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isConsultantSuppressedEarningLine(array $line): bool
    {
        $key = strtolower(trim((string) ($line['key'] ?? '')));
        $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));
        $category = strtolower(trim((string) ($line['category'] ?? '')));
        $haystack = $key.' '.$label.' '.$category;

        return $this->isExecomAttendanceBasedEarningLine($line)
            || str_contains($haystack, 'holiday')
            || str_contains($haystack, 'overtime')
            || str_contains($haystack, ' ot')
            || str_contains($haystack, 'night_diff')
            || str_contains($haystack, 'night differential')
            || str_contains($haystack, 'leave adjustment')
            || str_contains($haystack, 'paid_leave')
            || str_contains($haystack, 'unpaid_leave');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cleanDailyComputationDays(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $days = [];
        foreach ($raw as $day) {
            if (is_array($day)) {
                $days[] = $day;
            }
        }

        return array_values($days);
    }

    /**
     * Rebuild the Regular Pay row from actual daily computation minutes.
     *
     * This repairs generated/stored snapshots where the row was saved as scheduled day units
     * (for example, "2 days, 0 hrs 0 mins") even though attendance only rendered 102 minutes
     * with 378 minutes undertime. The payable minutes come from regular_day_minutes +
     * regular_night_minutes on worked, non-rest days and the amount is recomputed from
     * actual minutes x hourly rate.
     *
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function repairRegularPayLineFromDailyComputationDays(array $summary, array $days, ?float $regularHourlyRate): array
    {
        $actualRegularMinutes = 0;
        $fallbackAmountFromDays = 0.0;
        $fallbackHourlyRate = null;

        foreach ($days as $day) {
            $status = strtolower(trim((string) ($day['status'] ?? '')));
            if ($status !== 'worked' || (bool) ($day['is_rest_day'] ?? false)) {
                continue;
            }

            $dayRegularMinutes = max(0, (int) ($day['regular_day_minutes'] ?? 0) + (int) ($day['regular_night_minutes'] ?? 0));
            $breakdown = is_array($day['breakdown'] ?? null) ? $day['breakdown'] : [];
            $hasRegularPayComponent = false;
            $componentMinutes = 0;
            $componentAmount = 0.0;
            foreach ($breakdown as $entry) {
                if (! is_array($entry) || strtolower(trim((string) ($entry['component'] ?? ''))) !== 'regular_pay') {
                    continue;
                }

                $hasRegularPayComponent = true;
                $componentMinutes += max(0, (int) ($entry['minutes'] ?? 0));
                $componentAmount += max(0.0, (float) ($entry['amount'] ?? 0));
                $rate = $entry['rate'] ?? null;
                if ($fallbackHourlyRate === null && is_numeric($rate) && (float) $rate > 0) {
                    $fallbackHourlyRate = (float) $rate;
                }
            }

            if (! $hasRegularPayComponent) {
                $holidayPremiumPay = max(0.0, (float) ($day['holiday_premium_pay'] ?? 0));
                if ($dayRegularMinutes <= 0 || $holidayPremiumPay > 0) {
                    continue;
                }
            } elseif ($componentMinutes <= 0 && $componentAmount <= 0.0001) {
                // Holiday/premium days intentionally carry a zero regular_pay row while
                // their first-8h compensation is booked under holiday_premium.
                continue;
            }

            $actualMinutesForDay = $componentMinutes > 0
                ? min($componentMinutes, $dayRegularMinutes > 0 ? $dayRegularMinutes : $componentMinutes)
                : $dayRegularMinutes;

            $actualRegularMinutes += $actualMinutesForDay;
            $fallbackAmountFromDays += $componentAmount;
        }

        if ($actualRegularMinutes <= 0) {
            return $summary;
        }

        $hourlyRate = ($regularHourlyRate !== null && is_finite($regularHourlyRate) && $regularHourlyRate > 0)
            ? $regularHourlyRate
            : $fallbackHourlyRate;
        $amount = ($hourlyRate !== null && is_finite($hourlyRate) && $hourlyRate > 0)
            ? round(($actualRegularMinutes / 60.0) * $hourlyRate, 2)
            : round($fallbackAmountFromDays, 2);

        if ($amount <= 0) {
            return $summary;
        }

        $regularPayLine = [
            'key' => 'daily:regular_pay',
            'label' => 'Regular pay',
            'amount' => $amount,
            'units' => null,
            'minutes_worked' => $actualRegularMinutes,
            'hourly_rate' => $hourlyRate,
        ];

        $lines = is_array($summary['daily_computation_earning_lines'] ?? null)
            ? array_values($summary['daily_computation_earning_lines'])
            : [];
        $replaced = false;
        foreach ($lines as $idx => $line) {
            if (! is_array($line)) {
                continue;
            }

            $key = strtolower(trim((string) ($line['key'] ?? '')));
            $label = strtolower(trim((string) ($line['label'] ?? '')));
            if (str_contains($key, 'regular_pay') || $label === 'regular pay') {
                $lines[$idx] = $regularPayLine;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            array_unshift($lines, $regularPayLine);
        }

        $summary['daily_computation_earning_lines'] = array_values($lines);

        return $summary;
    }

    /**
     * Last-line defense for mPDF: drop non-array rows and force strict 0..n-1 integer keys on every
     * payslip table payload (sparse / string keys from JSON or upstream bugs can confuse table layout).
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function coerceSummaryTableArraysToZeroIndexedLists(array $summary): array
    {
        $tableKeys = [
            'payslip_earning_lines',
            'daily_computation_earning_lines',
            'payslip_deduction_lines',
            'payslip_custom_deduction_lines',
            'holiday_premium_breakdown',
        ];
        foreach ($tableKeys as $tableKey) {
            $raw = $summary[$tableKey] ?? [];
            if (! is_array($raw)) {
                $summary[$tableKey] = [];

                continue;
            }
            $list = [];
            foreach ($raw as $row) {
                if (is_array($row)) {
                    $list[] = $row;
                }
            }
            $summary[$tableKey] = array_values($list);
        }

        $ads = $summary['attendance_display_summary'] ?? [];
        if (is_array($ads)) {
            $lines = $ads['lines'] ?? [];
            if (is_array($lines)) {
                $clean = [];
                foreach ($lines as $line) {
                    if (is_array($line)) {
                        $clean[] = $line;
                    }
                }
                $ads['lines'] = array_values($clean);
            } else {
                $ads['lines'] = [];
            }
            $summary['attendance_display_summary'] = $ads;
        }

        return $summary;
    }

    /**
     * @return list<array{key: string, label: string, amount: float, units: ?string}>
     */
    private function normalizePayslipLineList(
        mixed $raw,
        string $defaultLabel,
        bool $keepWithholdingWhenZero = false,
        bool $keepAllAmounts = false,
        ?float $defaultRegularHourlyRate = null
    ): array
    {
        $rows = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $amt = (float) ($row['amount'] ?? $row['resolved_amount'] ?? 0);
            $minutesWorkedRaw = $row['minutes_worked'] ?? null;
            $minutesWorked = is_numeric($minutesWorkedRaw) ? (int) round((float) $minutesWorkedRaw) : null;
            $hourlyRateRaw = $row['hourly_rate'] ?? null;
            $hourlyRate = is_numeric($hourlyRateRaw) ? (float) $hourlyRateRaw : null;
            $lineKey = strtolower(trim((string) ($row['key'] ?? '')));
            $unitsRaw = $row['units'] ?? null;
            $unitsStr = $unitsRaw === null || $unitsRaw === '' ? null : trim((string) $unitsRaw);

            // Legacy snapshots may miss hourly_rate for regular pay lines.
            // Backfill from daily_rate/8 so amount and minutes can be reconciled exactly.
            if (
                ($hourlyRate === null || ! is_finite($hourlyRate) || $hourlyRate <= 0)
                && $defaultRegularHourlyRate !== null
                && str_contains($lineKey, 'regular_pay')
            ) {
                $hourlyRate = $defaultRegularHourlyRate;
            }

            // Prefer deriving missing minutes from amount + hourly rate. This avoids precision loss
            // from parsing rounded display labels (example: "1.6 days" -> 768 mins).
            if (($minutesWorked === null || $minutesWorked <= 0) && $hourlyRate !== null && $hourlyRate > 0 && $amt > 0) {
                $minutesWorked = (int) round(($amt / $hourlyRate) * 60.0);
            }

            if (($minutesWorked === null || $minutesWorked <= 0) && $unitsStr !== null && $unitsStr !== '') {
                $minutesWorked = $this->parseLegacyUnitsToMinutes($unitsStr);
            }

            // Regular-pay lines use day-split display ("X days, Y hrs Z mins") because
            // they span multiple work days. All other lines use simple "X hrs Y mins".
            $usesDaySplitUnits = str_contains($lineKey, 'regular_pay');
            $formatted = $this->formatUnitsAndAmount($minutesWorked, $hourlyRate);
            $unitsFromMinutes = $usesDaySplitUnits
                ? ($this->formatPayslipUnitsFromMinutes($minutesWorked) ?? $formatted['units'])
                : $formatted['units'];
            $amountFromMinutes = $formatted['amount'];

            // Amounts are minute-based for parity with Daily Computation:
            // amount = (actual worked minutes / 60) * hourly rate, rounded once.
            // For regular pay, the hourly rate is backfilled from daily_rate / 8 when needed.
            $lineLabel = strtolower($label);
            $isWithholdingLine = str_contains($lineKey, 'withholding')
                || str_contains($lineKey, 'wht')
                || str_contains($lineLabel, 'withholding')
                || str_contains($lineLabel, 'wht');
            if (
                ! $keepAllAmounts
                && $amt <= 0.000009
                && ! ($keepWithholdingWhenZero && $isWithholdingLine)
            ) {
                continue;
            }
            if ($label === '' && abs($amt) < 1e-9 && ($unitsStr === null || $unitsStr === '')) {
                continue;
            }
            if ($label === '') {
                $label = $defaultLabel;
            } else {
                $label = $this->sanitizePayslipText($label);
                if ($label === '') {
                    $label = $defaultLabel;
                }
            }
            $out[] = [
                'key' => isset($row['key']) ? (string) $row['key'] : '',
                'label' => $label,
                'amount' => $amountFromMinutes ?? $amt,
                'units' => $unitsFromMinutes ?: (($unitsStr !== null && $unitsStr !== '') ? $this->sanitizePayslipText($unitsStr) : null),
                'minutes_worked' => $minutesWorked,
                'hourly_rate' => $hourlyRate,
            ];
        }

        return array_values($out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizePayslipCustomDeductionLines(mixed $raw, array $summaryContext = []): array
    {
        $rows = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $amt = (float) ($row['amount'] ?? $row['resolved_amount'] ?? 0);
            if ($this->shouldHideUnscheduledZeroDeductionLine($row, $amt, $summaryContext)) {
                continue;
            }
            $bucket = isset($row['priority_bucket']) ? trim((string) $row['priority_bucket']) : '';
            $warn = isset($row['legal_warning']) ? trim((string) $row['legal_warning']) : '';
            if ($label === '' && abs($amt) < 1e-9 && $bucket === '' && $warn === '') {
                continue;
            }
            $labelOut = $label !== '' ? $this->sanitizePayslipText($label) : $this->sanitizePayslipText('Custom deduction');
            if ($labelOut === '') {
                $labelOut = 'Custom deduction';
            }
            $out[] = [
                'key' => isset($row['key']) ? (string) $row['key'] : '',
                'label' => $labelOut,
                'amount' => $amt,
                'units' => isset($row['units']) && (string) $row['units'] !== '' ? $this->sanitizePayslipText((string) $row['units']) : null,
                'priority_bucket' => $bucket !== '' ? $this->sanitizePayslipText($bucket) : null,
                'legal_warning' => $warn !== '' ? $this->sanitizePayslipText($warn) : null,
                'component_code' => isset($row['component_code']) ? (string) $row['component_code'] : null,
                'pay_component_id' => isset($row['pay_component_id']) && is_numeric($row['pay_component_id']) ? (int) $row['pay_component_id'] : null,
                'component_amount' => isset($row['component_amount']) && is_numeric($row['component_amount']) ? round((float) $row['component_amount'], 2) : null,
                'configured_amount' => isset($row['configured_amount']) && is_numeric($row['configured_amount']) ? round((float) $row['configured_amount'], 2) : null,
                'resolved_amount' => isset($row['resolved_amount']) && is_numeric($row['resolved_amount']) ? round((float) $row['resolved_amount'], 2) : round($amt, 2),
                'resolved_schedule' => isset($row['resolved_schedule']) ? (string) $row['resolved_schedule'] : null,
                'component_schedule' => isset($row['component_schedule']) ? (string) $row['component_schedule'] : null,
                'deduction_schedule_type' => isset($row['deduction_schedule_type']) ? (string) $row['deduction_schedule_type'] : null,
                'calculation_standard' => isset($row['calculation_standard']) ? (string) $row['calculation_standard'] : null,
                'resolved_calculation_standard' => isset($row['resolved_calculation_standard']) ? (string) $row['resolved_calculation_standard'] : null,
                'payroll_run_type' => isset($row['payroll_run_type']) ? (string) $row['payroll_run_type'] : null,
                'is_scheduled_this_period' => isset($row['is_scheduled_this_period']) ? (bool) $row['is_scheduled_this_period'] : null,
                'divisor_applied' => isset($row['divisor_applied']) && is_numeric($row['divisor_applied']) ? (float) $row['divisor_applied'] : null,
            ];
        }

        return array_values($out);
    }

    /**
     * Hide off-cycle deduction rows that were included only as zero placeholders.
     * This is display normalization only; scheduled zero rows can still be shown.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $summaryContext
     */
    private function shouldHideUnscheduledZeroDeductionLine(array $row, float $amount, array $summaryContext = []): bool
    {
        if (abs($amount) >= 0.005) {
            return false;
        }

        if (array_key_exists('is_scheduled_this_period', $row)) {
            return ! (bool) $row['is_scheduled_this_period'];
        }

        $divisor = $row['divisor_applied'] ?? null;
        if (is_numeric($divisor) && (float) $divisor > 0.0) {
            return false;
        }

        $schedule = $this->normalizePayslipScheduleValue(
            $row['resolved_schedule']
                ?? $row['component_schedule']
                ?? $row['deduction_schedule_type']
                ?? $row['schedule']
                ?? null
        );
        if ($schedule === null || $schedule === 'both') {
            return false;
        }

        $run = $this->normalizePayslipRunType(
            $row['payroll_run_type']
                ?? data_get($row, 'pay_component_resolution.payroll_run_type')
                ?? data_get($summaryContext, 'deduction_schedule.semi_monthly_period')
                ?? data_get($summaryContext, 'semi_monthly_period')
                ?? null
        );
        if ($run === null) {
            return false;
        }

        return ($schedule === '15th' && $run !== '15th')
            || ($schedule === '30th' && $run !== '30th');
    }

    private function normalizePayslipScheduleValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '15th', '15', '15th_only', '15_only', 'first', 'first_half', 'first-half', 'every_15_only', 'every_15' => '15th',
            '30th', '30', '30th_only', '30_only', 'second', 'second_half', 'second-half', 'end_of_month', 'end-of-month', 'eom', 'every_30_only', 'every_30' => '30th',
            'both', 'every_payroll', 'every-payroll', '15th_and_30th', 'every_15_and_30', '15_and_30', '15/30', '50/50', 'half', 'split', 'semi-monthly', 'semi_monthly' => 'both',
            default => null,
        };
    }

    private function normalizePayslipRunType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '15th', 'first' => '15th',
            '30th', 'second', 'end_of_month', 'eom', 'monthly', 'payroll' => '30th',
            default => null,
        };
    }

    private function sanitizePayslipText(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = \App\Support\TextSanitizer::clean($text, '') ?? '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 500);
        }

        return substr($text, 0, 500);
    }

    /**
     * Convert minutes to "X days, Y hrs Z mins" using 8 hours/day.
     * Regular pay intentionally keeps zero segments visible (for example, "0 days, 1 hr 42 mins").
     */
    private function formatPayslipUnitsFromMinutes(?int $minutesWorked): ?string
    {
        if ($minutesWorked === null || $minutesWorked <= 0) {
            return null;
        }
        $minutesPerDay = 8 * 60;
        $days = intdiv($minutesWorked, $minutesPerDay);
        $remaining = $minutesWorked % $minutesPerDay;
        $hours = intdiv($remaining, 60);
        $minutes = $remaining % 60;

        $dayPart = $days.' '.($days === 1 ? 'day' : 'days');
        $hourPart = $hours.' '.($hours === 1 ? 'hr' : 'hrs');
        $minutePart = $minutes.' '.($minutes === 1 ? 'min' : 'mins');

        return $dayPart.', '.$hourPart.' '.$minutePart;
    }

    private function formatDurationUnitsFromMinutes(?int $minutesWorked): ?string
    {
        if ($minutesWorked === null || $minutesWorked <= 0) {
            return null;
        }

        $hours = (int) floor($minutesWorked / 60);
        $minutes = $minutesWorked % 60;
        $minLabel = $minutes === 1 ? 'min' : 'mins';
        $timePart = null;
        if ($hours > 0 && $minutes > 0) {
            $timePart = $hours.' '.($hours === 1 ? 'hr' : 'hrs').' '.$minutes.' '.$minLabel;
        } elseif ($hours > 0) {
            $timePart = $hours.' '.($hours === 1 ? 'hr' : 'hrs');
        } elseif ($minutes > 0) {
            $timePart = $minutes.' '.$minLabel;
        }

        return $timePart;
    }

    /**
     * Unified minute-based formatter for both preview payload and PDF snapshot normalization.
     *
     * All unit display and amount computation is based on **total minutes** to avoid
     * floating-point errors from hours-based arithmetic.
     *
     * Rules:
     * - Units: integer arithmetic only → "X hr(s) Y min(s)" (uses {@see formatPayslipUnitsFromMinutes}
     *   for regular-pay lines where day-split is appropriate).
     * - Amount: `round((totalMinutes / 60) * hourlyRate, 2)` — single rounding at the end.
     *
     * @return array{units: ?string, amount: ?float}
     */
    private function formatUnitsAndAmount(?int $totalMinutes, ?float $hourlyRate): array
    {
        if ($totalMinutes === null || $totalMinutes <= 0) {
            return ['units' => null, 'amount' => null];
        }

        $hours = (int) floor($totalMinutes / 60);
        $minutes = $totalMinutes % 60;

        $unitString = '';
        if ($hours > 0) {
            $unitString .= $hours.' '.($hours > 1 ? 'hrs' : 'hr').' ';
        }
        if ($minutes > 0) {
            $unitString .= $minutes.' '.($minutes > 1 ? 'mins' : 'min');
        }
        $units = trim($unitString) ?: '0 mins';

        if ($hourlyRate === null || ! is_finite($hourlyRate) || $hourlyRate <= 0) {
            return ['units' => $units, 'amount' => null];
        }

        $amount = round(($totalMinutes / 60.0) * $hourlyRate, 2);

        return [
            'units' => $this->formatDurationUnitsFromMinutes($totalMinutes) ?? $units,
            'amount' => $amount,
        ];
    }

    /**
     * Parse legacy unit strings into total minutes.
     *
     * Supported formats:
     *  - "1.2 days" → 1.2 × 8 × 60 = 576 minutes
     *  - "1.70 hrs" / "1.70 hr" → 1.70 × 60 = 102 minutes (decimal hours)
     *  - "2 hrs 30 mins" / "2 hr 30 min" → 2×60 + 30 = 150 minutes
     *  - "45 mins" / "45 min" → 45 minutes
     *  - "3 hrs" / "3 hr" → 180 minutes
     */
    private function parseLegacyUnitsToMinutes(string $units): ?int
    {
        $raw = trim($units);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*days?$/i', $raw, $m)) {
            $days = (float) $m[1];

            return $days > 0 ? (int) round($days * 8 * 60) : null;
        }

        $hours = 0;
        $mins = 0;
        $matched = false;
        // Match decimal hours (e.g., "1.70 hrs", "2.5 hr")
        if (preg_match('/(\d+(?:\.\d+)?)\s*hrs?/i', $raw, $hm)) {
            $hours = (float) $hm[1];
            $matched = true;
        }
        if (preg_match('/(\d+)\s*mins?/i', $raw, $mm)) {
            $mins = (int) $mm[1];
            $matched = true;
        }
        if ($matched) {
            // Convert decimal hours to minutes: round(hours * 60) + mins
            $total = (int) round($hours * 60) + $mins;

            return $total > 0 ? $total : null;
        }

        return null;
    }

    /** Max rows logged per table array (full structure); larger lists log head/tail only. */
    private const MPDF_TABLE_LOG_MAX_ROWS = 200;

    /**
     * Log normalized table payloads and PHP index shape immediately before mPDF::WriteHTML.
     *
     * @param  array<string, mixed>  $normalizedSnapshot  Output of {@see normalizeSnapshotForPayslipPdf()}
     */
    private function logPayslipTableArraysBeforeWriteHtml(
        Payslip $payslip,
        User $employee,
        array $normalizedSnapshot,
        string $stage,
        int $sanitizedHtmlBytes
    ): void {
        $summary = is_array($normalizedSnapshot['summary'] ?? null) ? $normalizedSnapshot['summary'] : [];

        $earn = is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [];
        $ded = is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [];

        $daily = is_array($summary['daily_computation_earning_lines'] ?? null) ? $summary['daily_computation_earning_lines'] : [];
        $customDed = is_array($summary['payslip_custom_deduction_lines'] ?? null) ? $summary['payslip_custom_deduction_lines'] : [];
        $holiday = is_array($summary['holiday_premium_breakdown'] ?? null) ? $summary['holiday_premium_breakdown'] : [];

        Log::info('Payslip PDF: table data structure immediately before mPDF WriteHTML', [
            'stage' => $stage,
            'payslip_id' => $payslip->id,
            'user_id' => $employee->id,
            'sanitized_html_bytes' => $sanitizedHtmlBytes,
            'summary_keys' => array_keys($summary),
            'payslip_earning_lines' => $this->tableArrayPayloadForLog($earn),
            'payslip_deduction_lines' => $this->tableArrayPayloadForLog($ded),
            'payslip_earning_lines_index' => $this->listIndexDiagnostics($earn),
            'payslip_deduction_lines_index' => $this->listIndexDiagnostics($ded),
            'daily_computation_earning_lines' => $this->tableArrayPayloadForLog($daily),
            'daily_computation_earning_lines_index' => $this->listIndexDiagnostics($daily),
            'payslip_custom_deduction_lines' => $this->tableArrayPayloadForLog($customDed),
            'payslip_custom_deduction_lines_index' => $this->listIndexDiagnostics($customDed),
            'holiday_premium_breakdown' => $this->tableArrayPayloadForLog($holiday),
            'holiday_premium_breakdown_index' => $this->listIndexDiagnostics($holiday),
            'payslip_earning_lines_json' => $this->jsonEncodeForMpdfLog($earn),
            'payslip_deduction_lines_json' => $this->jsonEncodeForMpdfLog($ded),
            'daily_computation_earning_lines_json' => $this->jsonEncodeForMpdfLog($daily),
            'payslip_custom_deduction_lines_json' => $this->jsonEncodeForMpdfLog($customDed),
            'holiday_premium_breakdown_json' => $this->jsonEncodeForMpdfLog($holiday),
        ]);
    }

    /**
     * @param  list<mixed>|array<int|string, mixed>  $list
     * @return array<string, mixed>
     */
    private function tableArrayPayloadForLog(array $list): array
    {
        $n = count($list);
        if ($n === 0) {
            return ['row_count' => 0, 'rows' => []];
        }
        if ($n <= self::MPDF_TABLE_LOG_MAX_ROWS) {
            return ['row_count' => $n, 'rows' => array_values($list)];
        }

        return [
            'row_count' => $n,
            'truncated_for_log' => true,
            'max_rows_logged' => self::MPDF_TABLE_LOG_MAX_ROWS,
            'head' => array_values(array_slice($list, 0, min(100, $n), true)),
            'tail' => array_values(array_slice($list, -20, null, true)),
        ];
    }

    /**
     * Compact JSON for grep-friendly logs (invalid UTF-8 replaced).
     *
     * @param  array<int|string, mixed>  $list
     */
    private function jsonEncodeForMpdfLog(array $list): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $n = count($list);
        try {
            if ($n > self::MPDF_TABLE_LOG_MAX_ROWS) {
                $payload = [
                    '_truncated' => true,
                    'row_count' => $n,
                    'rows_preview' => array_values(array_slice($list, 0, self::MPDF_TABLE_LOG_MAX_ROWS, true)),
                ];
                $json = json_encode($payload, $flags);
            } else {
                $json = json_encode(array_values($list), $flags);
            }
        } catch (\Throwable) {
            return '{"error":"json_encode_failed"}';
        }

        return is_string($json) ? $json : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function listIndexDiagnostics(mixed $list): array
    {
        if (! is_array($list)) {
            return ['valid' => false, 'type' => gettype($list)];
        }
        $keys = array_keys($list);
        $n = count($keys);
        if ($n === 0) {
            return [
                'valid' => true,
                'row_count' => 0,
                'sequential_zero_based' => true,
                'array_is_list' => array_is_list($list),
            ];
        }
        $expected = range(0, $n - 1);

        return [
            'valid' => true,
            'row_count' => $n,
            'min_key' => min($keys),
            'max_key' => max($keys),
            'sequential_zero_based' => $keys === $expected,
            'array_is_list' => array_is_list($list),
            'first_keys' => array_slice($keys, 0, min(8, $n)),
            'last_keys' => array_slice($keys, max(0, $n - 8)),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedSnapshot
     */
    private function logPayslipPdfContext(Payslip $payslip, User $employee, array $normalizedSnapshot, string $stage): void
    {
        $summary = is_array($normalizedSnapshot['summary'] ?? null) ? $normalizedSnapshot['summary'] : [];
        $lineCounts = [
            'payslip_earning_lines' => count($summary['payslip_earning_lines'] ?? []),
            'daily_computation_earning_lines' => count($summary['daily_computation_earning_lines'] ?? []),
            'payslip_deduction_lines' => count($summary['payslip_deduction_lines'] ?? []),
            'payslip_custom_deduction_lines' => count($summary['payslip_custom_deduction_lines'] ?? []),
            'holiday_premium_breakdown' => count($summary['holiday_premium_breakdown'] ?? []),
            'attendance_display_lines' => count(is_array(data_get($summary, 'attendance_display_summary.lines')) ? $summary['attendance_display_summary']['lines'] : []),
        ];
        Log::info('Payslip PDF: normalized snapshot for mPDF', [
            'stage' => $stage,
            'payslip_id' => $payslip->id,
            'payslip_exists' => $payslip->exists,
            'user_id' => $employee->id,
            'company_id' => $payslip->company_id ?? $employee->company_id,
            'pay_period_start' => $payslip->pay_period_start,
            'pay_period_end' => $payslip->pay_period_end,
            'line_counts' => $lineCounts,
            'summary_top_level_keys' => array_keys($summary),
        ]);
    }

    /**
     * @param  array<string, mixed>  $normalizedSnapshot
     */
    private function logPayslipMpdfFailure(Payslip $payslip, User $employee, array $normalizedSnapshot, Throwable $e, string $stage): void
    {
        $summary = is_array($normalizedSnapshot['summary'] ?? null) ? $normalizedSnapshot['summary'] : [];
        $sample = static function (string $key) use ($summary): ?array {
            $list = $summary[$key] ?? [];
            if (! is_array($list) || $list === []) {
                return null;
            }
            $first = $list[0] ?? null;

            return is_array($first) ? array_keys($first) : null;
        };
        Log::error('Payslip PDF: mPDF WriteHTML failed', [
            'stage' => $stage,
            'payslip_id' => $payslip->id,
            'user_id' => $employee->id,
            'exception' => $e->getMessage(),
            'exception_class' => $e::class,
            'line_counts' => [
                'payslip_earning_lines' => count($summary['payslip_earning_lines'] ?? []),
                'daily_computation_earning_lines' => count($summary['daily_computation_earning_lines'] ?? []),
                'payslip_deduction_lines' => count($summary['payslip_deduction_lines'] ?? []),
                'payslip_custom_deduction_lines' => count($summary['payslip_custom_deduction_lines'] ?? []),
                'holiday_premium_breakdown' => count($summary['holiday_premium_breakdown'] ?? []),
            ],
            'index_diagnostics' => [
                'payslip_earning_lines' => $this->listIndexDiagnostics($summary['payslip_earning_lines'] ?? []),
                'daily_computation_earning_lines' => $this->listIndexDiagnostics($summary['daily_computation_earning_lines'] ?? []),
                'payslip_deduction_lines' => $this->listIndexDiagnostics($summary['payslip_deduction_lines'] ?? []),
                'payslip_custom_deduction_lines' => $this->listIndexDiagnostics($summary['payslip_custom_deduction_lines'] ?? []),
                'holiday_premium_breakdown' => $this->listIndexDiagnostics($summary['holiday_premium_breakdown'] ?? []),
            ],
            'first_row_keys' => array_filter([
                'payslip_earning_lines' => $sample('payslip_earning_lines'),
                'daily_computation_earning_lines' => $sample('daily_computation_earning_lines'),
                'payslip_deduction_lines' => $sample('payslip_deduction_lines'),
                'payslip_custom_deduction_lines' => $sample('payslip_custom_deduction_lines'),
                'holiday_premium_breakdown' => $sample('holiday_premium_breakdown'),
            ]),
        ]);
    }

    private function randomPdfPassword(): string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (Throwable) {
            return (string) random_int(10000000, 99999999);
        }
    }

    /**
     * Encrypt an existing PDF on disk using FPDI + TCPDF (user password).
     */
    private function applyPdfPassword(string $absolutePath, string $userPassword): void
    {
        $pdf = new Fpdi;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pageCount = $pdf->setSourceFile($absolutePath);
        for ($i = 1; $i <= $pageCount; $i++) {
            $pdf->AddPage();
            $tpl = $pdf->importPage($i);
            $pdf->useTemplate($tpl);
        }
        $pdf->SetProtection(['print', 'copy'], $userPassword, null, 0, null);
        $pdf->Output($absolutePath, 'F');
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon, 2: array<string, mixed>|null, 3: ?PayCycle}
     */
    private function resolveComputationContext(User $user, array $validated): array
    {
        // UI contract:
        // - `use_company_default=true` means "force the configured company default pay cycle" (ignore employee overrides).
        // - `reference_date` is treated as the *actual pay date* when provided.
        //
        // Pay date rule (PH semi-monthly, any year):
        //   - Cut-off 11–25 → pay date = last calendar day of the month (30, 31, or 28/29 for Feb)
        //   - Cut-off 26–10 → pay date = 15th of the month containing the cut-off end
        //   - Weekend adjustment: Saturday/Sunday → previous Friday
        $useCompanyDefault = (bool) ($validated['use_company_default'] ?? false);

        if (! empty($validated['from_date']) && ! empty($validated['to_date'])) {
            $from = Carbon::parse($validated['from_date'])->startOfDay();
            $to = Carbon::parse($validated['to_date'])->endOfDay();
            $toStartOfDay = $to->copy()->startOfDay();

            $cycle = ! empty($validated['pay_cycle_id'])
                ? PayCycle::query()->find((int) $validated['pay_cycle_id'])
                : ($useCompanyDefault ? $this->payCycleService->resolveCompanyDefaultForUser($user) : $this->payCycleService->resolveForUser($user));

            $referenceDate = $validated['reference_date'] ?? null;

            if (! empty($referenceDate)) {
                // Explicit pay date provided — honour it with weekend adjustment only.
                $payDateRaw = Carbon::parse((string) $referenceDate, $this->payCycleService->timezone())->startOfDay();
                $weekendRule = $cycle
                    ? (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY)
                    : PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY;
                $payDate = app(\App\Services\PayrollCalculatorService::class)->adjustForWeekend($payDateRaw, $weekendRule);

                $weekendAdjusted = ! $payDate->isSameDay($payDateRaw);
                $validated['reference_date'] = $payDate->toDateString();

                return [$from, $to, $this->buildCustomPeriodPreview($from, $toStartOfDay, $payDate, $weekendAdjusted), $cycle];
            }

            // No explicit pay date — derive it from the cut-off window.
            // Only use template logic when an explicit pay_cycle_id was selected by the user.
            // Otherwise, always use the canonical PH semi-monthly rule (15th / last day of month).
            if ($cycle && ! empty($validated['pay_cycle_id'])) {
                $cyclePreview = $this->payCycleService->buildCyclePreview($cycle, $validated['to_date'] ?? $validated['from_date']);
                $payDateRawStr = $cyclePreview['pay_date'] ?? $toStartOfDay->toDateString();
                $payDateRaw = Carbon::parse((string) $payDateRawStr, $this->payCycleService->timezone())->startOfDay();
                $weekendRule = (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY);
                $payDate = app(\App\Services\PayrollCalculatorService::class)->adjustForWeekend($payDateRaw, $weekendRule);
            } else {
                $computed = $this->payCycleService->computePayDateForPeriod($from, $toStartOfDay);
                $payDate = $computed['pay_date'];
            }

            // Determine weekend adjustment by comparing against the unadjusted canonical pay date.
            $segment = $this->payCycleService->inferSemiMonthSegment($from, $toStartOfDay);
            $unadjustedPayDate = $segment === 'first'
                ? $toStartOfDay->copy()->startOfMonth()->day(15)->startOfDay()
                : $toStartOfDay->copy()->endOfMonth()->startOfDay();
            $weekendAdjusted = ! $payDate->isSameDay($unadjustedPayDate);
            $validated['reference_date'] = $payDate->toDateString();

            return [$from, $to, $this->buildCustomPeriodPreview($from, $toStartOfDay, $payDate, $weekendAdjusted), $cycle];
        }

        $referenceDate = (string) ($validated['reference_date'] ?? now()->toDateString());

        $cycle = ! empty($validated['pay_cycle_id'])
            ? PayCycle::query()->find((int) $validated['pay_cycle_id'])
            : ($useCompanyDefault ? $this->payCycleService->resolveCompanyDefaultForUser($user) : $this->payCycleService->resolveForUser($user));

        if ($cycle) {
            $preview = ! empty($validated['reference_date'])
                ? $this->payCycleService->buildCyclePreviewFromPayDate($cycle, $referenceDate)
                : $this->payCycleService->buildCyclePreview($cycle, $referenceDate);
            $from = Carbon::parse($preview['cut_off_start_date'] ?? $referenceDate)->startOfDay();
            $to = Carbon::parse($preview['cut_off_end_date'] ?? $referenceDate)->endOfDay();

            return [$from, $to, $preview, $cycle];
        }

        // Fallback: canonical PH company default rule (15th / last day of month + weekend adjustment).
        $preview = $this->payCycleService->buildCompanyDefaultPreviewFromPayDate($referenceDate);
        $from = Carbon::parse($preview['cut_off_start_date'])->startOfDay();
        $to = Carbon::parse($preview['cut_off_end_date'])->endOfDay();

        return [$from, $to, $preview, null];
    }

    /**
     * Build a preview array for custom from/to dates (shared by explicit and derived pay date paths).
     */
    private function buildCustomPeriodPreview(Carbon $from, Carbon $to, Carbon $payDate, bool $weekendAdjusted): array
    {
        return [
            'cut_off_start_date' => $from->toDateString(),
            'cut_off_end_date' => $to->toDateString(),
            'pay_date' => $payDate->toDateString(),
            'reference_date' => $payDate->toDateString(),
            'cycle_label' => sprintf(
                '%s %s, %s – %s %s, %s',
                $from->format('F'), $from->format('j'), $from->format('Y'),
                $to->format('F'), $to->format('j'), $to->format('Y')
            ),
            'weekend_adjusted' => $weekendAdjusted,
            'weekend_adjustment_note' => $weekendAdjusted
                ? 'Weekend adjustment applied: pay dates landing on Saturday or Sunday move to the previous Friday.'
                : null,
        ];
    }


    /**
     * Exposes the same pay-cycle / cut-off window resolution as payslip PDF generation for finalize payroll and previews.
     *
     * @param  array<string, mixed>  $periodInput  from_date, to_date, pay_cycle_id?, reference_date?
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon, 2: array<string, mixed>|null, 3: ?PayCycle}
     */
    public function resolveComputationWindow(User $user, array $periodInput): array
    {
        return $this->resolveComputationContext($user, $periodInput);
    }

    /**
     * Employee IDs matching the same scope as bulk payslip generation for this batch run.
     *
     * @return list<int>
     */
    public function employeeIdsForBatchScope(PayrollBatchRun $run): array
    {
        $payrollModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
        $q = $this->payrollEligibility->query(
            $run->company_id ? (int) $run->company_id : null,
            $run->branch_id ? (int) $run->branch_id : null,
            $run->department_id ? (int) $run->department_id : null,
            $run->pay_period_start,
            $run->pay_period_end,
            null,
            $this->dataScopeService,
            $payrollModule
        );
        if ($run->employee_id) {
            $q->where('users.id', (int) $run->employee_id);
        }

        return $q->orderByLastName()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Restrict queries to the active payslip row per employee (non-voided, current period slot).
     */
    public function applyActivePayslipScope(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->where('period_slot', 0)
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->whereNull('voided_at');
    }

    /**
     * Latest payslip id per user for the given scoped query (one row per employee).
     *
     * @return list<int>
     */
    public function latestUniquePayslipIdsForQuery(\Illuminate\Database\Eloquent\Builder $baseQuery): array
    {
        return $this->applyActivePayslipScope(clone $baseQuery)
            ->selectRaw('MAX(id) as id')
            ->groupBy('user_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{total_gross: float, total_deductions: float, total_net: float, employee_count: int}
     */
    public function sumUniquePayslipsByIds(array $payslipIds): array
    {
        if ($payslipIds === []) {
            return [
                'total_gross' => 0.0,
                'total_deductions' => 0.0,
                'total_net' => 0.0,
                'employee_count' => 0,
            ];
        }

        $rows = Payslip::query()
            ->whereIn('id', $payslipIds)
            ->with(['employee:id,employee_code'])
            ->get(['id', 'user_id', 'gross_pay', 'total_deductions', 'net_pay', 'snapshot']);

        $gross = 0.0;
        $deductions = 0.0;
        $net = 0.0;
        foreach ($rows as $row) {
            $totals = $this->frozenPayslipLineMetrics($row);
            $gross += $totals['gross_pay'];
            $deductions += $totals['total_deductions'];
            $net += $totals['net_pay'];
        }

        return [
            'total_gross' => round($gross, 2),
            'total_deductions' => round($deductions, 2),
            'total_net' => round($net, 2),
            'employee_count' => $rows->count(),
        ];
    }

    /**
     * Payslip totals for rows belonging to this batch scope (company/branch/dept + pay window).
     *
     * Queries payslips directly by company_id + pay_period dates instead of relying on currently-active
     * employee IDs, which would miss payslips for employees deactivated after generation.
     * Totals use one row per employee (latest active payslip id) to avoid duplicate SUM inflation.
     *
     * @return array{payslip_count: int, total_net_pay: float, generated_at: ?\Carbon\Carbon, finalized_count: int, payslip_ids: list<int>, company_id: ?int, total_gross_pay: float, total_deductions: float}
     */
    public function aggregateForBatchRun(
        PayrollBatchRun $run,
        bool $recomputeDraftTotals = false,
        bool $syncMissingEligibleEmployees = true,
    ): array {
        if ($recomputeDraftTotals) {
            $live = $this->aggregateLiveDraftForBatchRun($run);
            if (is_array($live)) {
                return $live;
            }
        }

        $this->cleanupStaleBatchModulePayslips($run);
        if ($syncMissingEligibleEmployees && (string) $run->status === PayrollBatchRun::STATUS_DRAFT) {
            $this->syncMissingEligibleEmployeesToDraftBatch($run);
        } else {
            $this->attachMatchingPayslipsToBatchRun($run);
        }

        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
        $eligibleEmployeeIds = $this->employeeIdsForBatchScope($run);

        $q = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', $expectedModule)
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString());
        if ($eligibleEmployeeIds !== []) {
            $q->whereIn('user_id', $eligibleEmployeeIds);
        } else {
            $q->whereRaw('1 = 0');
        }
        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            $q->whereIn('status', Payslip::lockingStatuses());
        } else {
            $q->whereIn('status', $this->draftSnapshotStatuses());
        }

        if ($run->company_id) {
            $q->where('company_id', (int) $run->company_id);
        }
        if ($run->branch_id) {
            $q->where('branch_id', (int) $run->branch_id);
        }
        if ($run->department_id) {
            $q->where('department_id', (int) $run->department_id);
        }
        if ($run->employee_id) {
            $q->where('user_id', (int) $run->employee_id);
        }

        $uniqueIds = $this->latestUniquePayslipIdsForQuery($q);
        if ($uniqueIds === []) {
            return [
                'payslip_count' => 0,
                'total_net_pay' => 0.0,
                'total_gross_pay' => 0.0,
                'total_deductions' => 0.0,
                'generated_at' => null,
                'finalized_count' => 0,
                'payslip_ids' => [],
                'company_id' => $run->company_id !== null ? (int) $run->company_id : null,
            ];
        }

        $rows = Payslip::query()
            ->whereIn('id', $uniqueIds)
            ->with(['employee:id,employee_code'])
            ->get(['id', 'user_id', 'company_id', 'gross_pay', 'total_deductions', 'net_pay', 'status', 'created_at', 'snapshot']);

        $lockedForBatch = Payslip::lockingStatuses();
        $finalized = $rows->filter(fn ($p) => in_array((string) $p->status, $lockedForBatch, true))->count();
        $resolvedCompanyId = $run->company_id !== null
            ? (int) $run->company_id
            : $rows->pluck('company_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->first();
        $sums = $this->sumUniquePayslipsByIds($uniqueIds);
        $totalGrossPay = $sums['total_gross'];
        $totalDeductions = $sums['total_deductions'];
        $totalNetPay = $sums['total_net'];
        return [
            'payslip_count' => $sums['employee_count'],
            'total_net_pay' => $totalNetPay,
            'total_gross_pay' => $totalGrossPay,
            'total_deductions' => $totalDeductions,
            'generated_at' => $rows->max('created_at'),
            'finalized_count' => $finalized,
            'payslip_ids' => $uniqueIds,
            'company_id' => $resolvedCompanyId,
        ];
    }

    /**
     * Recompute draft batch totals from current payroll inputs for real-time Recent Payslips display.
     *
     * Finalized batches remain immutable and queued/processing batches use their persisted progress.
     *
     * @return array{payslip_count: int, total_net_pay: float, generated_at: ?\Carbon\Carbon, finalized_count: int, payslip_ids: list<int>, company_id: ?int, total_gross_pay: float, total_deductions: float}|null
     */
    private function aggregateLiveDraftForBatchRun(PayrollBatchRun $run): ?array
    {
        if ((string) $run->status !== PayrollBatchRun::STATUS_DRAFT) {
            return null;
        }
        if ($run->pay_period_start === null || $run->pay_period_end === null) {
            return null;
        }

        $stored = $this->aggregateForBatchRun($run, false, false);
        if ((int) ($stored['finalized_count'] ?? 0) > 0) {
            return null;
        }

        $userIds = $this->employeeIdsForBatchScope($run);
        if ($userIds === []) {
            return $stored;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderByLastName()
            ->get();
        if ($users->isEmpty()) {
            return $stored;
        }

        $users->loadMissing([
            'company',
            'branch',
            'payCycle',
            'governmentIds',
            'workingSchedule',
        ]);

        $periodInput = [
            'from_date' => $run->pay_period_start->toDateString(),
            'to_date' => $run->pay_period_end->toDateString(),
            'pay_cycle_id' => $run->pay_cycle_id,
            'reference_date' => $run->reference_date?->toDateString(),
            'payroll_period_id' => $run->payroll_period_id,
            'is_final_pay' => (bool) $run->is_final_pay,
            'password_protect' => (bool) $run->password_protect,
        ];

        $grossTotal = 0.0;
        $deductionsTotal = 0.0;
        $netTotal = 0.0;

        try {
            foreach ($users as $user) {
                [$from, $to, $cyclePreview] = $this->resolveComputationWindow($user, $periodInput);
                $computed = $this->payrollComputation->computeEmployeePayroll(
                    $user,
                    $from,
                    $to,
                    null,
                    [
                        'pay_period_start' => $from->toDateString(),
                        'pay_period_end' => $to->toDateString(),
                        'selected_pay_date' => is_array($cyclePreview) ? ($cyclePreview['pay_date'] ?? null) : null,
                    ]
                );
                $summary = is_array($computed['summary'] ?? null) ? $computed['summary'] : [];
                $gross = round(
                    (float) ($summary['gross_pay_this_period']
                        ?? ((float) ($summary['total_pay'] ?? 0) + (float) ($summary['non_basic_earnings_this_period'] ?? 0))),
                    2
                );
                $deductions = round(
                    (float) ($summary['employee_statutory_this_period'] ?? 0)
                    + (float) ($summary['custom_deductions_this_period'] ?? 0)
                    + (float) ($summary['withholding_tax_this_period_estimate'] ?? 0),
                    2
                );
                $net = round((float) ($summary['net_pay_after_withholding_estimate'] ?? 0), 2);

                $grossTotal += $gross;
                $deductionsTotal += $deductions;
                $netTotal += $net;
            }
        } catch (Throwable $e) {
            Log::warning('Payslip draft batch live aggregate failed; using persisted totals', [
                'payroll_batch_run_id' => (int) $run->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'payslip_count' => $users->count(),
            'total_net_pay' => round($netTotal, 2),
            'total_gross_pay' => round($grossTotal, 2),
            'total_deductions' => round($deductionsTotal, 2),
            'generated_at' => $stored['generated_at'] ?? $run->completed_at ?? $run->created_at,
            'finalized_count' => 0,
            'payslip_ids' => $stored['payslip_ids'] ?? [],
            'company_id' => $run->company_id !== null ? (int) $run->company_id : ($stored['company_id'] ?? null),
        ];
    }

    private function attachMatchingPayslipsToBatchRun(PayrollBatchRun $run): int
    {
        if ($run->pay_period_start === null || $run->pay_period_end === null || $run->company_id === null) {
            return 0;
        }

        $expectedStatuses = (string) $run->status === PayrollBatchRun::STATUS_FINALIZED
            ? Payslip::lockingStatuses()
            : $this->draftSnapshotStatuses();

        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));

        $query = Payslip::query()
            ->whereNull('payroll_batch_run_id')
            ->whereNull('voided_at')
            ->whereIn('status', $expectedStatuses)
            ->where('period_slot', 0)
            ->where('payroll_module', $expectedModule)
            ->where('company_id', (int) $run->company_id)
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString());

        if ($run->branch_id !== null) {
            $query->where('branch_id', (int) $run->branch_id);
        }
        if ($run->department_id !== null) {
            $query->where('department_id', (int) $run->department_id);
        }
        if ($run->employee_id !== null) {
            $query->where('user_id', (int) $run->employee_id);
        }

        $updated = (int) $query->update(['payroll_batch_run_id' => (int) $run->id]);
        if ($updated > 0) {
            Log::info('Payslip batch aggregate attached matching saved payslips to batch run', [
                'payroll_run_id' => (int) $run->id,
                'selected_company_id' => (int) $run->company_id,
                'statuses' => $expectedStatuses,
                'attached_count' => $updated,
            ]);
        }

        return $updated;
    }

    /**
     * Draft payroll snapshots have historically used both statuses before finalization.
     *
     * @return list<string>
     */
    private function draftSnapshotStatuses(): array
    {
        return [Payslip::STATUS_DRAFT, Payslip::STATUS_GENERATED];
    }

    /**
     * Re-aggregate and persist totals on the batch run record.
     */
    public function syncBatchRunTotals(PayrollBatchRun $run): void
    {
        if ((string) $run->status === PayrollBatchRun::STATUS_DRAFT) {
            $this->syncMissingEligibleEmployeesToDraftBatch($run);
        }
        $agg = $this->aggregateForBatchRun($run, false, false);
        $payload = [
            'employee_count' => $agg['payslip_count'],
            'total_employees' => max((int) ($run->total_employees ?? 0), (int) $agg['payslip_count']),
            'total_gross' => $agg['total_gross_pay'],
            'total_deductions' => $agg['total_deductions'],
            'total_net' => $agg['total_net_pay'],
        ];

        if ($run->company_id === null && (int) $agg['payslip_count'] > 0) {
            $companyQuery = Payslip::query()
                ->where('payroll_batch_run_id', (int) $run->id)
                ->whereNull('voided_at')
                ->whereNotNull('company_id');

            $module = trim((string) ($run->payroll_module ?? ''));
            if ($module !== '') {
                $companyQuery->where('payroll_module', $module);
            }

            $companyIds = $companyQuery->distinct()->pluck('company_id');
            if ($companyIds->count() === 1) {
                $payload['company_id'] = (int) $companyIds->first();
            }
        }

        $this->updateBatchRunProgress($run, $payload);
    }

    /**
     * Keep queue progress updates compatible while migrations roll across environments.
     *
     * @param  array<string, mixed>  $payload
     */
    private function updateBatchRunProgress(PayrollBatchRun $run, array $payload): void
    {
        if (self::$payrollBatchRunColumns === null) {
            self::$payrollBatchRunColumns = Schema::hasTable('payroll_batch_runs')
                ? Schema::getColumnListing('payroll_batch_runs')
                : [];
        }
        if (self::$payrollBatchRunColumns === []) {
            return;
        }

        $allowed = array_flip(self::$payrollBatchRunColumns);
        $filtered = array_intersect_key($payload, $allowed);
        if ($filtered === []) {
            return;
        }

        PayrollBatchRun::query()->whereKey($run->id)->update($filtered);
    }

    /**
     * Remove draft payslips for this batch scope and delete the batch run.
     * Queued or processing jobs no-op once the run is deleted.
     */
    public function deleteDraftBatchRun(PayrollBatchRun $run): void
    {
        $status = (string) $run->status;
        if ($status === PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Finalized payslips cannot be deleted.');
        }
        if ($status === PayrollBatchRun::STATUS_VOIDED) {
            throw new \RuntimeException('Voided payroll batches cannot be deleted.');
        }
        $attachedPayslips = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->count();
        $keepCancelledRun = $status === PayrollBatchRun::STATUS_PROCESSING;
        if (! in_array($status, [
            PayrollBatchRun::STATUS_DRAFT,
            PayrollBatchRun::STATUS_QUEUED,
            PayrollBatchRun::STATUS_PROCESSING,
            PayrollBatchRun::STATUS_FAILED,
        ], true)) {
            throw new \RuntimeException('Only draft, queued, processing, or failed batches can be deleted.');
        }

        if ($keepCancelledRun) {
            $this->updateBatchRunProgress($run, [
                'status' => PayrollBatchRun::STATUS_VOIDED,
                'voided_at' => now(),
                'void_reason' => 'Cancelled during draft payroll generation.',
                'completed_at' => now(),
            ]);
        }

        if ($attachedPayslips === 0 && (int) ($run->processed_employees ?? 0) === 0) {
            if (! $keepCancelledRun) {
                $run->delete();
            }

            return;
        }

        $userIds = $this->employeeIdsForBatchScope($run);

        DB::transaction(function () use ($run, $userIds, $keepCancelledRun) {
            if (count($userIds) === 0) {
                if (! $keepCancelledRun) {
                    $run->delete();
                }

                return;
            }

            $base = Payslip::query()
                ->whereIn('user_id', $userIds)
                ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
                ->whereDate('pay_period_end', $run->pay_period_end->toDateString())
                ->where('payroll_module', $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD)))
                ->where('period_slot', 0);
            if ($run->company_id) {
                $base->where('company_id', (int) $run->company_id);
            }

            $draftStatuses = $this->draftSnapshotStatuses();
            $nonDraft = (clone $base)->whereNotIn('status', $draftStatuses)->count();
            if ($nonDraft > 0) {
                throw new \RuntimeException('Cannot delete: some payslips are already finalized.');
            }

            (clone $base)->whereIn('status', $draftStatuses)->delete();
            if (! $keepCancelledRun) {
                $run->delete();
            }
        });
    }

    private function resolvePayrollModule(array $input): string
    {
        if (! empty($input['payroll_module'])) {
            return $this->normalizePayrollModule((string) $input['payroll_module']);
        }
        if (! empty($input['payroll_batch_run_id'])) {
            $module = PayrollBatchRun::query()
                ->whereKey((int) $input['payroll_batch_run_id'])
                ->value('payroll_module');
            if (is_string($module) && trim($module) !== '') {
                return $this->normalizePayrollModule($module);
            }
        }

        return PayrollBatchRun::MODULE_STANDARD;
    }

    private function normalizePayrollModule(string $module): string
    {
        return strtolower(trim($module)) === PayrollBatchRun::MODULE_EXECOM
            ? PayrollBatchRun::MODULE_EXECOM
            : PayrollBatchRun::MODULE_STANDARD;
    }

    /**
     * @param  array<string, mixed>  $periodCtx
     * @param  array<string, mixed>|null  $preview
     * @return array<string, mixed>
     */
    private function computePayrollForModule(
        User $employee,
        Carbon $from,
        Carbon $to,
        string $payrollModule,
        array $periodCtx,
        ?array $preview,
    ): array {
        if ($payrollModule !== PayrollBatchRun::MODULE_EXECOM) {
            return $this->payrollComputation->computeEmployeePayroll(
                $employee,
                $from,
                $to,
                null,
                $periodCtx
            );
        }

        $profile = $employee->activeExecomProfileForPeriod($from, $to);
        if (! $profile instanceof ExecomEmployeeProfile) {
            throw new \RuntimeException('Active EXECOM profile is required for EXECOM payroll (user_id='.(int) $employee->id.').');
        }

        $settings = ExecomPayrollSetting::forCompany($profile->company_id ? (int) $profile->company_id : null);
        $periodContext = array_merge($periodCtx, [
            'pay_cycle_preview' => $preview ?? $periodCtx['pay_cycle_preview'] ?? null,
            'pay_cycle_code' => $periodCtx['pay_cycle_code'] ?? data_get($preview, 'pay_cycle_code', data_get($preview, 'code')),
            'semi_month_segment' => $periodCtx['semi_month_segment'] ?? data_get($preview, 'semi_month_segment'),
            'selected_pay_date' => $preview['pay_date'] ?? $periodCtx['selected_pay_date'] ?? null,
            'company_working_days' => $this->resolveCompanyWorkingDaysForExecom($employee, $profile),
        ]);

        return $this->execomPayrollComputation->computeExecomPayroll(
            $employee,
            $from,
            $to,
            $profile,
            $settings,
            $periodContext
        );
    }

    private function resolveCompanyWorkingDaysForExecom(User $employee, ExecomEmployeeProfile $profile): int
    {
        $companyId = $profile->company_id ? (int) $profile->company_id : ($employee->getEffectiveCompanyId() ?? 0);
        if ($companyId > 0) {
            $company = Company::query()->find($companyId);
            if ($company && isset($company->working_days_per_month) && (int) $company->working_days_per_month > 0) {
                return max(1, (int) $company->working_days_per_month);
            }
        }

        try {
            return max(1, (int) config('payroll.execom_working_days_per_month', 26));
        } catch (\Throwable) {
            return 26;
        }
    }

    public function cleanupStaleBatchModulePayslips(PayrollBatchRun $run): void
    {
        if ($run->pay_period_start === null || $run->pay_period_end === null) {
            return;
        }

        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
        $this->normalizeDraftBatchPayslipPeriodDates($run, $expectedModule);
        $wrongModule = $expectedModule === PayrollBatchRun::MODULE_EXECOM
            ? PayrollBatchRun::MODULE_STANDARD
            : PayrollBatchRun::MODULE_EXECOM;
        $eligibleEmployeeIds = $this->employeeIdsForBatchScope($run);

        $staleQuery = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->whereIn('status', $this->draftSnapshotStatuses())
            ->where(function ($query) use ($wrongModule, $eligibleEmployeeIds) {
                $query->where('payroll_module', $wrongModule);
                if ($eligibleEmployeeIds === []) {
                    $query->orWhereNotNull('user_id');
                } else {
                    $query->orWhereNotIn('user_id', $eligibleEmployeeIds);
                }
            });

        $stalePayslipIds = $staleQuery
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($stalePayslipIds === []) {
            return;
        }

        $removedEmployees = User::query()
            ->whereIn('id', Payslip::query()
                ->whereIn('id', $stalePayslipIds)
                ->select('user_id'))
            ->get();
        foreach ($removedEmployees as $employee) {
            $this->payrollEligibility->evaluateEmployeeEligibility(
                $employee,
                $run->company_id ? (int) $run->company_id : null,
                $run->pay_period_start,
                $run->pay_period_end,
                $run->branch_id ? (int) $run->branch_id : null,
                $run->department_id ? (int) $run->department_id : null,
                $expectedModule,
                (int) $run->id
            );
        }

        $this->deletePayslipsAndPayrollLines($stalePayslipIds);
        $this->refreshBatchRunDraftTotals($run, $expectedModule);

        Log::info('Payroll batch removed stale payslips from wrong payroll module or membership', [
            'payroll_batch_run_id' => (int) $run->id,
            'expected_module' => $expectedModule,
            'removed_count' => count($stalePayslipIds),
        ]);
    }

    /**
     * Add draft payslips for employees who became payroll-eligible after the batch was first generated.
     */
    public function syncMissingEligibleEmployeesToDraftBatch(PayrollBatchRun $run): int
    {
        if ($run->pay_period_start === null || $run->pay_period_end === null) {
            return 0;
        }
        if ((string) $run->status !== PayrollBatchRun::STATUS_DRAFT) {
            return 0;
        }

        $this->cleanupStaleBatchModulePayslips($run);

        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
        $normalizedCount = $this->normalizeDraftBatchPayslipPeriodDates($run, $expectedModule);
        $this->attachMatchingPayslipsToBatchRun($run);
        $eligibleIds = $this->employeeIdsForBatchScope($run);
        if ($eligibleIds === []) {
            return 0;
        }

        $existingUserIds = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', $expectedModule)
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString())
            ->whereIn('status', $this->draftSnapshotStatuses())
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $missingIds = array_values(array_diff($eligibleIds, $existingUserIds));
        if ($missingIds === []) {
            if ($normalizedCount > 0) {
                $this->refreshBatchRunDraftTotals($run, $expectedModule);
                $run->refresh();
            }

            return 0;
        }

        $maxSync = 150;
        if (count($missingIds) > $maxSync) {
            Log::warning('Payroll batch has too many newly eligible employees to sync synchronously', [
                'payroll_batch_run_id' => (int) $run->id,
                'missing_count' => count($missingIds),
                'max_sync' => $maxSync,
            ]);
            $missingIds = array_slice($missingIds, 0, $maxSync);
        }

        $periodInput = [
            'from_date' => $run->pay_period_start->toDateString(),
            'to_date' => $run->pay_period_end->toDateString(),
            'pay_cycle_id' => $run->pay_cycle_id,
            'reference_date' => $run->reference_date?->toDateString(),
            'payroll_period_id' => $run->payroll_period_id,
            'payroll_batch_run_id' => (int) $run->id,
            'payroll_module' => $expectedModule,
            'is_final_pay' => (bool) $run->is_final_pay,
            'password_protect' => (bool) $run->password_protect,
        ];

        Log::info('Payroll batch syncing newly eligible employees into draft', [
            'payroll_batch_run_id' => (int) $run->id,
            'missing_count' => count($missingIds),
        ]);

        try {
            $this->generateBulkPayslips(
                $run->company_id ? (int) $run->company_id : null,
                $run->branch_id ? (int) $run->branch_id : null,
                $run->department_id ? (int) $run->department_id : null,
                $missingIds,
                $periodInput,
                null,
                withPdf: false,
                progressRun: $run
            );
        } catch (\Throwable $e) {
            Log::error('Payroll batch sync for missing eligible employees failed', [
                'payroll_batch_run_id' => (int) $run->id,
                'missing_count' => count($missingIds),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        $this->refreshBatchRunDraftTotals($run, $expectedModule);
        $run->refresh();

        return count($missingIds);
    }

    private function normalizeDraftBatchPayslipPeriodDates(PayrollBatchRun $run, string $expectedModule): int
    {
        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            return 0;
        }
        if ($run->pay_period_start === null || $run->pay_period_end === null) {
            return 0;
        }

        $expectedStart = $run->pay_period_start->toDateString();
        $expectedEnd = $run->pay_period_end->toDateString();

        $candidateIds = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', $expectedModule)
            ->whereIn('status', $this->draftSnapshotStatuses())
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->whereDate('pay_period_end', $expectedEnd)
            ->whereDate('pay_period_start', '!=', $expectedStart)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($candidateIds === []) {
            return 0;
        }

        $updated = 0;
        foreach ($candidateIds as $payslipId) {
            $payslip = Payslip::query()->find($payslipId);
            if (! $payslip instanceof Payslip) {
                continue;
            }

            $conflict = Payslip::query()
                ->where('user_id', (int) $payslip->user_id)
                ->where('company_id', (int) $payslip->company_id)
                ->where('payroll_module', $expectedModule)
                ->whereDate('pay_period_start', $expectedStart)
                ->whereDate('pay_period_end', $expectedEnd)
                ->where('period_slot', 0)
                ->where('status', '!=', Payslip::STATUS_VOIDED)
                ->whereKeyNot($payslipId)
                ->exists();
            if ($conflict) {
                continue;
            }

            $payslip->forceFill([
                'pay_period_start' => $expectedStart,
                'pay_period_end' => $expectedEnd,
            ])->save();
            $updated++;
        }

        if ($updated > 0) {
            Log::info('Payroll batch normalized draft payslip period dates', [
                'payroll_batch_run_id' => (int) $run->id,
                'expected_module' => $expectedModule,
                'updated_count' => $updated,
            ]);
        }

        return $updated;
    }

    private function refreshBatchRunDraftTotals(PayrollBatchRun $run, string $expectedModule): void
    {
        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            return;
        }

        $remainingIds = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', $expectedModule)
            ->whereIn('status', $this->draftSnapshotStatuses())
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString())
            ->selectRaw('MAX(id) as id')
            ->groupBy('user_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $sums = $this->sumUniquePayslipsByIds($remainingIds);
        $run->forceFill([
            'employee_count' => (int) $sums['employee_count'],
            'total_employees' => (int) $sums['employee_count'],
            'total_gross' => round((float) $sums['total_gross'], 2),
            'total_deductions' => round((float) $sums['total_deductions'], 2),
            'total_net' => round((float) $sums['total_net'], 2),
        ])->save();
    }

    /**
     * @param  list<int>  $payslipIds
     */
    private function deletePayslipsAndPayrollLines(array $payslipIds): void
    {
        if ($payslipIds === []) {
            return;
        }

        $payrollEmployeeIds = PayrollEmployee::query()
            ->whereIn('payslip_id', $payslipIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($payrollEmployeeIds !== []) {
            PayrollLine::query()->whereIn('payroll_employee_id', $payrollEmployeeIds)->delete();
            PayrollEmployee::query()->whereIn('id', $payrollEmployeeIds)->delete();
        }

        Payslip::query()->whereIn('id', $payslipIds)->delete();
    }
}
