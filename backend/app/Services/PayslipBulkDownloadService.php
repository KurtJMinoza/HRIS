<?php

namespace App\Services;

use App\Jobs\BulkPayslipPdfJob;
use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\PayslipBulkDownload;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PayslipBulkDownloadService
{
    private const CHUNK_SIZE = 15;

    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    /**
     * @param  list<int>|null  $selectedEmployeeIds
     */
    public function queueDownload(
        PayrollBatchRun $run,
        User $actor,
        ?array $selectedEmployeeIds = null,
        bool $forceRegenerate = false
    ): PayslipBulkDownload {
        $agg = $this->payslipService->aggregateForBatchRun($run);
        $payslipIds = $agg['payslip_ids'] ?? [];
        if ($payslipIds === []) {
            throw new \RuntimeException('No payslips found for this batch.');
        }

        if ($selectedEmployeeIds !== null && $selectedEmployeeIds !== []) {
            $allowed = Payslip::query()
                ->whereIn('id', $payslipIds)
                ->whereIn('user_id', $selectedEmployeeIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($allowed === []) {
                throw new \RuntimeException('No payslips match the selected employees.');
            }
            $payslipIds = $allowed;
        }

        $inFlight = PayslipBulkDownload::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->whereIn('status', [PayslipBulkDownload::STATUS_PENDING, PayslipBulkDownload::STATUS_PROCESSING])
            ->orderByDesc('id')
            ->first();
        if ($inFlight instanceof PayslipBulkDownload) {
            return $inFlight;
        }

        if (! $forceRegenerate) {
            $completed = PayslipBulkDownload::query()
                ->where('payroll_batch_run_id', (int) $run->id)
                ->where('status', PayslipBulkDownload::STATUS_COMPLETED)
                ->where('force_regenerate', false)
                ->orderByDesc('id')
                ->first();
            if ($completed instanceof PayslipBulkDownload && $this->zipExists($completed)) {
                return $completed;
            }
        }

        $download = PayslipBulkDownload::query()->create([
            'payroll_batch_run_id' => (int) $run->id,
            'requested_by_user_id' => (int) $actor->id,
            'status' => PayslipBulkDownload::STATUS_PENDING,
            'total_files' => count($payslipIds),
            'processed_files' => 0,
            'file_format' => 'zip',
            'selected_employee_ids' => $selectedEmployeeIds,
            'force_regenerate' => $forceRegenerate,
        ]);

        BulkPayslipPdfJob::dispatch((int) $download->id)
            ->onConnection('redis')
            ->onQueue('payslip-pdf');

        Log::info('Payslip bulk download queued', [
            'bulk_download_id' => (int) $download->id,
            'payroll_batch_run_id' => (int) $run->id,
            'total_files' => count($payslipIds),
            'requested_by' => (int) $actor->id,
        ]);

        return $download;
    }

    public function processDownload(PayslipBulkDownload $download): void
    {
        $locked = DB::transaction(function () use ($download) {
            $row = PayslipBulkDownload::query()->whereKey($download->id)->lockForUpdate()->first();
            if (! $row instanceof PayslipBulkDownload || $row->isTerminal()) {
                return null;
            }
            if ((string) $row->status === PayslipBulkDownload::STATUS_PENDING) {
                $row->update([
                    'status' => PayslipBulkDownload::STATUS_PROCESSING,
                    'started_at' => now(),
                    'error_message' => null,
                ]);
            }

            return $row->fresh();
        });

        if (! $locked instanceof PayslipBulkDownload) {
            return;
        }

        $this->runProcessDownload($locked);
    }

    private function runProcessDownload(PayslipBulkDownload $download): void
    {
        $run = PayrollBatchRun::query()
            ->with(['company:id,name'])
            ->find((int) $download->payroll_batch_run_id);
        if (! $run instanceof PayrollBatchRun) {
            $this->markFailed($download, 'Payroll batch run not found.');

            return;
        }
        if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED) {
            $this->markFailed($download, 'Bulk download is only available for finalized payroll batches.');

            return;
        }

        $meta = $this->cachedBatchMetadata($run);
        $agg = $this->payslipService->aggregateForBatchRun($run);
        /** @var list<int> $ids */
        $ids = $agg['payslip_ids'] ?? [];
        $selected = $download->selected_employee_ids;
        if (is_array($selected) && $selected !== []) {
            $ids = Payslip::query()
                ->whereIn('id', $ids)
                ->whereIn('user_id', array_map('intval', $selected))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($ids === []) {
            $this->markFailed($download, 'No payslips found for this batch.');

            return;
        }

        if (count($ids) > 500) {
            $this->markFailed($download, 'This batch exceeds the maximum of 500 payslips for one ZIP export.');

            return;
        }

        $download->update(['total_files' => count($ids)]);

        $tempDir = storage_path('app/private/payslip-bulk-downloads/'.$download->id.'/parts');
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new \RuntimeException('Could not create temporary bulk download directory.');
        }

        $zipRelative = 'payslip-bulk-downloads/'.$download->id.'/'.$this->buildZipFilename($run, $meta);
        $zipFull = storage_path('app/private/'.$zipRelative);
        $zipDir = dirname($zipFull);
        if (! is_dir($zipDir) && ! mkdir($zipDir, 0755, true) && ! is_dir($zipDir)) {
            throw new \RuntimeException('Could not create ZIP output directory.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFull, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive.');
        }

        $forceRegenerate = (bool) $download->force_regenerate;
        $processed = 0;
        /** @var array<string, int> $zipNameCounts */
        $zipNameCounts = [];

        $query = Payslip::query()
            ->with([
                'employee.company',
                'employee.branch',
                'employee.departmentRelation',
                'employee.governmentIds',
            ])
            ->whereIn('id', $ids)
            ->orderBy('id');

        $query->chunkById(self::CHUNK_SIZE, function ($payslips) use (
            $zip,
            $forceRegenerate,
            $download,
            &$processed,
            &$zipNameCounts
        ) {
            /** @var Payslip $payslip */
            foreach ($payslips as $payslip) {
                $employee = $payslip->employee;
                if (! $employee instanceof User) {
                    continue;
                }

                $relative = $this->payslipService->ensurePayslipPdfOnDisk($payslip, $employee, $forceRegenerate);
                $full = storage_path('app/private/'.$relative);
                if (! is_file($full)) {
                    throw new \RuntimeException('PDF generation failed for payslip id='.$payslip->id);
                }

                $entryName = $this->allocateZipEntryName($payslip, $employee, $zipNameCounts);
                if (! $zip->addFile($full, $entryName)) {
                    throw new \RuntimeException('Could not add payslip to ZIP: '.$entryName);
                }

                $processed++;
            }

            PayslipBulkDownload::query()->whereKey($download->id)->update([
                'processed_files' => $processed,
            ]);
        });

        $zip->close();

        $download->update([
            'status' => PayslipBulkDownload::STATUS_COMPLETED,
            'processed_files' => $processed,
            'file_path' => $zipRelative,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        $this->removeDirectory($tempDir);

        Log::info('Payslip bulk download completed', [
            'bulk_download_id' => (int) $download->id,
            'payroll_batch_run_id' => (int) $run->id,
            'processed_files' => $processed,
            'file_path' => $zipRelative,
        ]);
    }

    public function markFailed(PayslipBulkDownload $download, string $message): void
    {
        $download->update([
            'status' => PayslipBulkDownload::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function zipExists(PayslipBulkDownload $download): bool
    {
        $path = (string) ($download->file_path ?? '');
        if ($path === '') {
            return false;
        }

        return is_file(storage_path('app/private/'.$path));
    }

    public function absoluteZipPath(PayslipBulkDownload $download): ?string
    {
        if (! $this->zipExists($download)) {
            return null;
        }

        return storage_path('app/private/'.(string) $download->file_path);
    }

    public function downloadFilename(PayrollBatchRun $run, ?Company $company = null): string
    {
        $meta = $this->cachedBatchMetadata($run);

        return $this->buildZipFilename($run, $meta);
    }

    /**
     * @return array{company_name: string, pay_period_label: string}
     */
    public function cachedBatchMetadata(PayrollBatchRun $run): array
    {
        $cacheKey = 'payslip_bulk_meta:'.(int) $run->id;

        return Cache::remember($cacheKey, 600, function () use ($run) {
            $companyName = 'Company';
            if ($run->company_id) {
                $company = Company::query()->find((int) $run->company_id);
                if ($company) {
                    $companyName = (string) ($company->name ?? $companyName);
                }
            }
            $periodLabel = $run->pay_period_start && $run->pay_period_end
                ? $run->pay_period_start->format('Y-m-d').'_'.$run->pay_period_end->format('Y-m-d')
                : (string) $run->id;

            return [
                'company_name' => $companyName,
                'pay_period_label' => $periodLabel,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function statusPayload(PayslipBulkDownload $download): array
    {
        $run = $download->payrollBatchRun;
        $ready = (string) $download->status === PayslipBulkDownload::STATUS_COMPLETED && $this->zipExists($download);

        return [
            'id' => (int) $download->id,
            'request_id' => (int) $download->id,
            'payroll_batch_run_id' => (int) $download->payroll_batch_run_id,
            'status' => (string) $download->status,
            'total_files' => (int) $download->total_files,
            'processed_files' => (int) $download->processed_files,
            'progress_percent' => $download->progressPercent(),
            'error_message' => $download->error_message,
            'ready' => $ready,
            'download_filename' => $run instanceof PayrollBatchRun
                ? $this->downloadFilename($run)
                : null,
            'started_at' => $download->started_at?->toIso8601String(),
            'completed_at' => $download->completed_at?->toIso8601String(),
            'created_at' => $download->created_at?->toIso8601String(),
        ];
    }

    public function cleanupExpiredDownloads(int $retentionDays = 7): int
    {
        $cutoff = now()->subDays(max(1, $retentionDays));
        $removed = 0;

        PayslipBulkDownload::query()
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$removed) {
                foreach ($rows as $row) {
                    if ($row->file_path) {
                        Storage::disk('local')->delete('private/'.(string) $row->file_path);
                    }
                    $dir = storage_path('app/private/payslip-bulk-downloads/'.$row->id);
                    $this->removeDirectory($dir);
                    $row->delete();
                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * @param  array{company_name: string, pay_period_label: string}  $meta
     */
    private function buildZipFilename(PayrollBatchRun $run, array $meta): string
    {
        $company = $this->safeFilenameSegment($meta['company_name']);
        $period = $this->safeFilenameSegment($meta['pay_period_label']);

        return 'Payslips_'.$company.'_'.$period.'.zip';
    }

    /**
     * @param  array<string, int>  $zipNameCounts
     */
    private function allocateZipEntryName(Payslip $payslip, User $employee, array &$zipNameCounts): string
    {
        $payYmd = $payslip->pay_date
            ? $payslip->pay_date->format('Y-m-d')
            : ($payslip->pay_period_end?->format('Y-m-d') ?? now()->format('Y-m-d'));

        $last = trim((string) ($employee->last_name ?? ''));
        $first = trim((string) ($employee->first_name ?? ''));

        if ($last !== '' && $first !== '') {
            $baseKey = $this->safeFilenameSegment($last).'_'.$this->safeFilenameSegment($first).'_'.$payYmd;
        } elseif ($last !== '') {
            $baseKey = $this->safeFilenameSegment($last).'_'.$payYmd;
        } elseif ($first !== '') {
            $baseKey = $this->safeFilenameSegment($first).'_'.$payYmd;
        } else {
            $code = trim((string) ($employee->employee_code ?? ''));
            $baseKey = $code !== ''
                ? $this->safeFilenameSegment($code).'_'.$payYmd
                : 'emp_'.$employee->id.'_'.$payYmd;
        }

        $zipNameCounts[$baseKey] = ($zipNameCounts[$baseKey] ?? 0) + 1;
        if ($zipNameCounts[$baseKey] === 1) {
            return $baseKey.'.pdf';
        }

        return $baseKey.'_'.$payslip->id.'.pdf';
    }

    private function safeFilenameSegment(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $value) ?? $value;
        $value = trim((string) $value, '-');

        return $value !== '' ? $value : 'batch';
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
