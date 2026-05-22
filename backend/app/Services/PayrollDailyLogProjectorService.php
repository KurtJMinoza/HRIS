<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PayrollDailyLogProjectorService
{
    public function rebuildForRange(string $fromDate, string $toDate): int
    {
        $rows = DB::table('payroll_breakdowns as pb')
            ->join('payroll_periods as pp', 'pp.id', '=', 'pb.payroll_period_id')
            ->join('users as u', 'u.id', '=', 'pp.user_id')
            ->whereBetween('pb.date', [$fromDate, $toDate])
            ->whereIn('u.role', ['employee', 'admin'])
            ->where('u.is_system_user', false)
            ->where('u.is_hidden', false)
            ->where('u.exclude_from_payroll', false)
            ->select([
                'pp.user_id',
                'u.company_id',
                'pb.date',
                DB::raw("CASE WHEN COALESCE(pb.late_deduction_minutes,0) > 0 OR COALESCE(pb.unapproved_ot_minutes,0) > 0 THEN 'needs_review' ELSE 'valid' END as review_status"),
                'pb.is_rest_day',
                'pb.holiday_type',
                'pb.holiday_name',
                'pb.regular_day_minutes',
                'pb.regular_night_minutes',
                'pb.ot_day_minutes',
                'pb.ot_night_minutes',
                'pb.approved_ot_minutes',
                'pb.unapproved_ot_minutes',
                'pb.late_deduction_minutes',
                'pb.total_pay',
                'pb.conditions',
                'pb.breakdown',
                'pb.created_at',
                'pb.updated_at',
            ])
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::table('payroll_daily_logs')->updateOrInsert(
                ['user_id' => (int) $row->user_id, 'date' => (string) $row->date],
                [
                    'company_id' => $row->company_id,
                    'review_status' => (string) ($row->review_status ?? 'valid'),
                    'is_rest_day' => (bool) ($row->is_rest_day ?? false),
                    'holiday_type' => $row->holiday_type,
                    'holiday_name' => $row->holiday_name,
                    'regular_day_minutes' => (int) ($row->regular_day_minutes ?? 0),
                    'regular_night_minutes' => (int) ($row->regular_night_minutes ?? 0),
                    'ot_day_minutes' => (int) ($row->ot_day_minutes ?? 0),
                    'ot_night_minutes' => (int) ($row->ot_night_minutes ?? 0),
                    'approved_ot_minutes' => (int) ($row->approved_ot_minutes ?? 0),
                    'unapproved_ot_minutes' => (int) ($row->unapproved_ot_minutes ?? 0),
                    'late_deduction_minutes' => (int) ($row->late_deduction_minutes ?? 0),
                    'total_pay' => (float) ($row->total_pay ?? 0),
                    'conditions' => $row->conditions,
                    'breakdown' => $row->breakdown,
                    'updated_at' => $row->updated_at ?? now(),
                    'created_at' => $row->created_at ?? now(),
                ]
            );
            $count++;
        }

        return $count;
    }
}
