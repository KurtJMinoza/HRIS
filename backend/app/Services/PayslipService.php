<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Support\BulkPayrollDraftContext;
use App\Models\Company;
use App\Models\EmployeeGovernmentIdDocument;
use App\Models\PayCycle;
use App\Models\PayrollBatchRun;
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

    public function __construct(
        private readonly BrowsershotEnvironment $browsershotEnvironment,
        private readonly PayrollComputationService $payrollComputation,
        private readonly PayCycleService $payCycleService,
        private readonly DataScopeService $dataScopeService,
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
     * Finalize all payslips in a company period window and lock related payroll periods.
     *
     * @param  int[]|null  $employeeIds  Optional employee scope (company/branch/department filtered list).
     * @return int Updated payslip rows
     */
    public function finalizePayrollWindow(
        int $companyId,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        ?int $periodId = null,
        ?array $employeeIds = null,
        ?int $finalizedByUserId = null
    ): int {
        $start = $periodStart instanceof Carbon ? $periodStart->toDateString() : Carbon::parse((string) $periodStart)->toDateString();
        $end = $periodEnd instanceof Carbon ? $periodEnd->toDateString() : Carbon::parse((string) $periodEnd)->toDateString();
        $scopedEmployeeIds = is_array($employeeIds) ? array_values(array_unique(array_map('intval', $employeeIds))) : null;

        $payslips = Payslip::query()
            ->whereDate('pay_period_start', $start)
            ->whereDate('pay_period_end', $end);
        if ($periodId !== null) {
            $payslips->where('payroll_period_id', $periodId);
        }
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

        $periods = PayrollPeriod::query()
            ->whereDate('from_date', $start)
            ->whereDate('to_date', $end);
        if ($periodId !== null) {
            $periods->where('id', $periodId);
        }
        if (is_array($scopedEmployeeIds) && count($scopedEmployeeIds) > 0) {
            $periods->whereIn('user_id', $scopedEmployeeIds);
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
        if (count($dailyEarningLines) === 0) {
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
        if (is_array($precomputedPreview)) {
            $preview = $precomputedPreview;
        }
        if ($precomputedCycle instanceof PayCycle) {
            $cycle = $precomputedCycle;
        }
        if ($enforcePayrollWindowMutable && ! $skipMutableCheck) {
            $this->assertPayrollPeriodMutableForUserWindow((int) $employee->id, $from, $to);
        }

        $periodCtx = [
            'pay_period_start' => $from->toDateString(),
            'pay_period_end' => $to->toDateString(),
            'selected_pay_date' => $preview['pay_date'] ?? null,
            'company_id' => $employee->getEffectiveCompanyId(),
        ];
        if (! empty($input['payroll_batch_run_id'])) {
            $periodCtx['payroll_batch_run_id'] = (int) $input['payroll_batch_run_id'];
        }
        if ($timingSink !== null) {
            $periodCtx['_timing_sink'] = $timingSink;
        }

        $computed = is_array($precomputed)
            ? $precomputed
            : $this->payrollComputation->computeEmployeePayroll(
                $employee,
                $from,
                $to,
                null,
                $periodCtx
            );
        $summary = $computed['summary'] ?? [];

        $grossPay = round(
            (float) ($summary['gross_pay_this_period']
                ?? ((float) ($summary['total_pay'] ?? 0) + (float) ($summary['non_basic_earnings_this_period'] ?? 0))),
            2
        );
        $empStat = (float) ($summary['employee_statutory_this_period'] ?? 0);
        $custDed = (float) ($summary['custom_deductions_this_period'] ?? 0);
        $wh = (float) ($summary['withholding_tax_this_period_estimate'] ?? 0);
        $totalDed = round($empStat + $custDed + $wh, 2);
        $netPay = (float) ($summary['net_pay_after_withholding_estimate'] ?? 0);

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

        $payDate = isset($preview['pay_date']) ? Carbon::parse((string) $preview['pay_date']) : $to->copy();

        $companyId = $employee->getEffectiveCompanyId();
        $finalPay = (bool) ($input['is_final_pay'] ?? false)
            || EmploymentStatus::tryFromStored($employee->employment_status) === EmploymentStatus::Separated;

        $plainPassword = ! empty($input['password_protect']) ? $this->randomPdfPassword() : null;

        return [
            'unique' => [
                'user_id' => $employee->id,
                'pay_period_start' => $from->toDateString(),
                'pay_period_end' => $to->toDateString(),
                'period_slot' => 0,
            ],
            'attributes' => [
                'payroll_period_id' => $input['payroll_period_id'] ?? null,
                'pay_cycle_id' => $cycle?->id,
                'company_id' => $companyId,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'pay_date' => $payDate->toDateString(),
                'cycle_label' => $preview['cycle_label'] ?? $cycle?->name,
                'gross_pay' => $grossPay,
                'total_deductions' => $totalDed,
                'net_pay' => round($netPay, 2),
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
        $q = User::query()->payrollEmployees()->active();

        if ($actor !== null) {
            $this->dataScopeService->restrictEmployeeQuery($actor, $q);
        }

        if (is_array($employeeIds) && count($employeeIds) > 0) {
            $q->whereIn('id', $employeeIds);
        } else {
            if ($companyId) {
                $q->where('company_id', $companyId);
            }
            if ($branchId) {
                $q->where('branch_id', $branchId);
            }
            if ($departmentId) {
                $q->where('department_id', $departmentId);
            }
        }

        $orderedIds = (clone $q)->orderByLastName()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $employeeCount = count($orderedIds);
        $periodStartForYtd = Carbon::parse((string) ($periodInput['from_date'] ?? now()->toDateString()))->startOfDay();

        $ids = [];
        $processedEmployees = 0;
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
        $prefetchAttendance = ! $withPdf && $fromStr !== '' && $toStr !== '' && $employeeCount > 0;
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
                    ['user_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                    [
                        'pay_cycle_id',
                        'company_id',
                        'branch_id',
                        'department_id',
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
                        ->whereDate('pay_period_start', $periodStartStr)
                        ->whereDate('pay_period_end', $periodEndStr)
                        ->where('period_slot', 0)
                        ->where('status', '!=', Payslip::STATUS_VOIDED)
                        ->orderByDesc('id')
                        ->first();
                    if ($payslip instanceof Payslip) {
                        $this->assignPayrollPeriodId($payslip, $periodId);
                    }
                }
            });
            $timings['bulk_upsert_ms'] += (microtime(true) - $upsertStartedAt) * 1000;
            $processedEmployees += count($chunkIds);
            if ($progressRun instanceof PayrollBatchRun) {
                $this->updateBatchRunProgress($progressRun, [
                    'processed_employees' => $processedEmployees,
                    'employee_count' => $processedEmployees,
                    'total_employees' => $employeeCount,
                ]);
            }
            }
        } finally {
            BulkPayrollDraftContext::$active = false;
            if ($prefetchAttendance) {
                $this->payrollComputation->endBulkPayrollAttendancePrefetch();
            }
        }

        if (! $withPdf && $orderedIds !== [] && $fromStr !== '' && $toStr !== '') {
            $upsertStartedAt = microtime(true);
            $ids = Payslip::query()
                ->whereIn('user_id', $orderedIds)
                ->whereDate('pay_period_start', $fromStr)
                ->whereDate('pay_period_end', $toStr)
                ->where('period_slot', 0)
                ->where('status', '!=', Payslip::STATUS_VOIDED)
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
        ];
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
        if (is_array($snapshotRaw) && $snapshotRaw !== []) {
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

        return $this->normalizeSnapshotForPayslipView(is_array($snapshotRaw) ? $snapshotRaw : []);
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
            'use_company_default' => $payslip->pay_cycle_id === null,
            'payroll_period_id' => $payslip->payroll_period_id !== null ? (int) $payslip->payroll_period_id : null,
            'is_final_pay' => (bool) $payslip->is_final_pay,
            'password_protect' => false,
        ];
    }

    /**
     * Coerce payslip snapshot.summary line arrays for mPDF: 0-based lists, scalar fields only, no junk rows.
     * Stored DB snapshot is unchanged; this is used only when rendering the Blade PDF template.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalizeSnapshotForPayslipPdf(array $snapshot): array
    {
        $out = $snapshot;
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];

        $dailyRate = (float) ($summary['daily_rate'] ?? ($snapshot['daily_rate'] ?? 0));
        $regularHourlyRate = $dailyRate > 0 ? ($dailyRate / 8.0) : null;
        $dailyComputationDays = $this->cleanDailyComputationDays($snapshot['daily_computation_days'] ?? null);
        if ($dailyComputationDays !== []) {
            $out['daily_computation_days'] = $dailyComputationDays;
            $summary = $this->repairRegularPayLineFromDailyComputationDays($summary, $dailyComputationDays, $regularHourlyRate);
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
        $summary['payslip_deduction_lines'] = $this->normalizePayslipLineList(
            $summary['payslip_deduction_lines'] ?? [],
            'Deduction',
            false,
            true
        );
        $summary['payslip_custom_deduction_lines'] = $this->normalizePayslipCustomDeductionLines($summary['payslip_custom_deduction_lines'] ?? []);

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
            $amt = (float) ($row['amount'] ?? 0);
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
    private function normalizePayslipCustomDeductionLines(mixed $raw): array
    {
        $rows = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $amt = (float) ($row['amount'] ?? 0);
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
            ];
        }

        return array_values($out);
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
        $q = User::query()->payrollEmployees()->active();
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
            $q->where('id', (int) $run->employee_id);
        }

        return $q->orderByLastName()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Restrict queries to the active payslip row per employee (non-voided, current period slot).
     */
    public function applyActivePayslipScope(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->where('period_slot', 0)
            ->where('status', '!=', Payslip::STATUS_VOIDED);
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

        $agg = Payslip::query()
            ->whereIn('id', $payslipIds)
            ->selectRaw('COALESCE(SUM(gross_pay), 0) as total_gross, COALESCE(SUM(total_deductions), 0) as total_ded, COALESCE(SUM(net_pay), 0) as total_net, COUNT(*) as cnt')
            ->first();

        return [
            'total_gross' => round((float) ($agg->total_gross ?? 0), 2),
            'total_deductions' => round((float) ($agg->total_ded ?? 0), 2),
            'total_net' => round((float) ($agg->total_net ?? 0), 2),
            'employee_count' => (int) ($agg->cnt ?? 0),
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
    public function aggregateForBatchRun(PayrollBatchRun $run, bool $recomputeDraftTotals = false): array
    {
        if ($recomputeDraftTotals) {
            $live = $this->aggregateLiveDraftForBatchRun($run);
            if (is_array($live)) {
                return $live;
            }
        }

        $q = Payslip::query()
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString());

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
            ->get(['id', 'user_id', 'company_id', 'gross_pay', 'total_deductions', 'net_pay', 'status', 'created_at']);

        $lockedForBatch = Payslip::lockingStatuses();
        $finalized = $rows->filter(fn ($p) => in_array((string) $p->status, $lockedForBatch, true))->count();
        $resolvedCompanyId = $run->company_id !== null
            ? (int) $run->company_id
            : $rows->pluck('company_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->first();
        $sums = $this->sumUniquePayslipsByIds($uniqueIds);
        $totalGrossPay = $sums['total_gross'];
        $totalDeductions = $sums['total_deductions'];
        $totalNetPay = $sums['total_net'];
        if ($totalNetPay <= 0.0 && $totalGrossPay > 0.0) {
            $totalNetPay = round((float) ($run->total_net ?? max(0.0, $totalGrossPay - $totalDeductions)), 2);
        }

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

        $stored = $this->aggregateForBatchRun($run, false);
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

    /**
     * Re-aggregate and persist totals on the batch run record.
     */
    public function syncBatchRunTotals(PayrollBatchRun $run): void
    {
        $agg = $this->aggregateForBatchRun($run);
        $this->updateBatchRunProgress($run, [
            'employee_count' => $agg['payslip_count'],
            'total_employees' => max((int) ($run->total_employees ?? 0), (int) $agg['payslip_count']),
            'total_gross' => $agg['total_gross_pay'],
            'total_deductions' => $agg['total_deductions'],
            'total_net' => $agg['total_net_pay'],
        ]);
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
     * Allowed when status is {@see PayrollBatchRun::STATUS_DRAFT} or {@see PayrollBatchRun::STATUS_QUEUED}
     * (queued {@see \App\Jobs\GeneratePayrollBatchJob} exits harmlessly if the run was removed first).
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
        if ($status === PayrollBatchRun::STATUS_PROCESSING) {
            throw new \RuntimeException('Cannot delete while payslip generation is in progress.');
        }
        if (! in_array($status, [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_QUEUED], true)) {
            throw new \RuntimeException('Only draft or queued batches can be deleted.');
        }

        $userIds = $this->employeeIdsForBatchScope($run);

        DB::transaction(function () use ($run, $userIds) {
            if (count($userIds) === 0) {
                $run->delete();

                return;
            }

            $base = Payslip::query()
                ->whereIn('user_id', $userIds)
                ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
                ->whereDate('pay_period_end', $run->pay_period_end->toDateString())
                ->where('period_slot', 0);
            if ($run->company_id) {
                $base->where('company_id', (int) $run->company_id);
            }

            $nonDraft = (clone $base)->where('status', '!=', Payslip::STATUS_DRAFT)->count();
            if ($nonDraft > 0) {
                throw new \RuntimeException('Cannot delete: some payslips are already finalized.');
            }

            (clone $base)->where('status', Payslip::STATUS_DRAFT)->delete();
            $run->delete();
        });
    }
}
