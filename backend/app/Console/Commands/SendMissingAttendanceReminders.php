<?php

namespace App\Console\Commands;

use App\Models\AttendanceEmailLog;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\HolidayService;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMissingAttendanceReminders extends Command
{
    protected $signature = 'attendance:send-missing-reminders';

    protected $description = 'Send email reminders to employees who have not clocked in past their scheduled start time';

    public function handle(EmailNotificationService $emailService, HolidayService $holidayService): int
    {
        $tz = config('attendance.timezone', 'Asia/Manila');
        $today = Carbon::now($tz)->toDateString();
        $now = Carbon::now($tz);

        if (! $emailService->isEnabled('attendance_missing_reminder')) {
            $this->info('attendance_missing_reminder is disabled. Skipping.');

            return 0;
        }

        $employees = User::query()
            ->select(['id', 'email', 'first_name', 'middle_name', 'last_name', 'suffix', 'name', 'working_schedule_id', 'schedule', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'])
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();

        $alreadySentIds = AttendanceEmailLog::query()
            ->where('date', $today)
            ->where('reminder_type', 'missing_clock_in')
            ->pluck('employee_id')
            ->all();

        $clockedInIds = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereDate('created_at', $today)
            ->pluck('user_id')
            ->all();

        $approvedLeaveUserIds = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->pluck('user_id')
            ->all();

        $skipIds = array_unique(array_merge($alreadySentIds, $clockedInIds, $approvedLeaveUserIds));
        $sent = 0;

        foreach ($employees as $employee) {
            if (in_array($employee->id, $skipIds, true)) {
                continue;
            }

            if ($holidayService->getEffectiveHolidayForEmployee($employee, $today) !== null) {
                continue;
            }

            $schedule = EmployeeScheduleResolver::resolve($employee);
            if ($schedule === null) {
                continue;
            }

            $dayKey = EmployeeScheduleResolver::dayKeyForDate($now);
            $daySchedule = $schedule[$dayKey] ?? null;

            if ($daySchedule === null) {
                continue;
            }

            $scheduleStart = $daySchedule['in'] ?? null;
            if ($scheduleStart === null) {
                continue;
            }

            $startTime = Carbon::parse($today.' '.$scheduleStart, $tz);
            if ($now->lt($startTime->copy()->addMinutes(30))) {
                continue;
            }

            try {
                $emailService->send('attendance_missing_reminder', ['employee' => $employee], [
                    'employee_name' => $employee->name,
                    'date' => $today,
                    'scheduled_time' => $scheduleStart,
                    'action_url' => config('app.frontend_url', config('app.url')),
                    'company_name' => config('app.name', 'HRIS'),
                ]);

                AttendanceEmailLog::create([
                    'employee_id' => $employee->id,
                    'date' => $today,
                    'reminder_type' => 'missing_clock_in',
                    'sent_at' => now(),
                ]);

                $sent++;
            } catch (\Throwable $e) {
                Log::error('attendance_missing_reminder: failed', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} missing clock-in reminder(s).");

        return 0;
    }
}
