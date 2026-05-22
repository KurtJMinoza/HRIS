<?php

namespace App\Jobs;

use App\Models\ReportExportRun;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateDetailedReportCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        private readonly int $exportRunId
    ) {}

    public function handle(): void
    {
        $run = ReportExportRun::query()->find($this->exportRunId);
        if (! $run) {
            return;
        }

        $run->update([
            'status' => ReportExportRun::STATUS_PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $filters = is_array($run->filters) ? $run->filters : [];
            $fromDate = (string) ($filters['from_date'] ?? now()->toDateString());
            $toDate = (string) ($filters['to_date'] ?? $fromDate);

            $rows = DB::table('payroll_breakdowns as pb')
                ->join('payroll_periods as pp', 'pp.id', '=', 'pb.payroll_period_id')
                ->join('users as u', 'u.id', '=', 'pp.user_id')
                ->leftJoin('companies as c', 'c.id', '=', 'u.company_id')
                ->whereBetween('pb.date', [$fromDate, $toDate])
                ->whereIn('u.role', ['employee', 'admin'])
                ->where('u.is_system_user', false)
                ->where('u.is_hidden', false)
                ->where('u.exclude_from_reports', false)
                ->select([
                    'pb.date',
                    'u.employee_code',
                    'u.name',
                    'c.name as company_name',
                    'pb.regular_day_minutes',
                    'pb.regular_night_minutes',
                    'pb.ot_day_minutes',
                    'pb.ot_night_minutes',
                    'pb.late_deduction_minutes',
                    'pb.total_pay',
                ])
                ->orderBy('pb.date')
                ->orderBy('u.last_name')
                ->orderBy('u.first_name')
                ->orderBy('u.middle_name')
                ->orderBy('u.id')
                ->get();

            $filename = 'reports/detailed/export_'.$run->id.'_'.Carbon::now()->format('Ymd_His').'.csv';
            $header = [
                'date',
                'employee_code',
                'employee_name',
                'company_name',
                'regular_day_minutes',
                'regular_night_minutes',
                'ot_day_minutes',
                'ot_night_minutes',
                'late_deduction_minutes',
                'total_pay',
            ];
            $lines = [implode(',', $header)];
            foreach ($rows as $row) {
                $lines[] = implode(',', [
                    $this->csvCell((string) $row->date),
                    $this->csvCell((string) ($row->employee_code ?? '')),
                    $this->csvCell((string) ($row->name ?? '')),
                    $this->csvCell((string) ($row->company_name ?? '')),
                    (int) ($row->regular_day_minutes ?? 0),
                    (int) ($row->regular_night_minutes ?? 0),
                    (int) ($row->ot_day_minutes ?? 0),
                    (int) ($row->ot_night_minutes ?? 0),
                    (int) ($row->late_deduction_minutes ?? 0),
                    (float) ($row->total_pay ?? 0),
                ]);
            }

            Storage::disk('local')->put($filename, implode("\n", $lines));

            $run->update([
                'status' => ReportExportRun::STATUS_COMPLETED,
                'file_path' => $filename,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $run->update([
                'status' => ReportExportRun::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    private function csvCell(string $value): string
    {
        $escaped = str_replace('"', '""', $value);

        return '"'.$escaped.'"';
    }
}
