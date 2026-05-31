<?php

namespace App\Providers;

use App\Events\ScheduleUpdated;
use App\Listeners\RecalculatePayrollDailyRecords;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeBenefit;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\EmployeeCompensationComponent;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeGovernmentId;
use App\Models\Holiday;
use App\Models\SectionUnit;
use App\Models\Team;
use App\Models\User;
use App\Services\AttendanceCacheService;
use App\Services\EmployeeDashboardCacheService;
use App\Services\HolidayCalendarService;
use App\Services\HolidayService;
use App\Services\LegacyOrganizationMirrorService;
use App\Support\EmployeeProfileCache;
use App\Support\AdminDashboardCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HolidayCalendarService::class, fn () => new HolidayCalendarService);
        $this->app->singleton(HolidayService::class, fn ($app) => new HolidayService(
            $app->make(HolidayCalendarService::class)
        ));
        $this->app->bind(
            \App\Contracts\OrgUnitEmployeeCounter::class,
            \App\Services\OrgUnitEmployeeCountService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function ($query): void {
            if ((float) $query->time < 500.0) {
                return;
            }

            Log::warning('Slow database query', [
                'duration_ms' => round((float) $query->time, 2),
                'sql' => $query->sql,
            ]);
        });

        User::saved(function (User $user): void {
            EmployeeProfileCache::invalidate((int) $user->id);
            AdminDashboardCache::flush();
            Cache::forget('permissions:user:'.(int) $user->id);
            Cache::forget('sidebar:user:'.(int) $user->id);
            if ($user->wasChanged(['schedule', 'working_schedule_id', 'pending_working_schedule_id'])) {
                AttendanceCacheService::invalidate((int) $user->id);
                EmployeeDashboardCacheService::invalidate((int) $user->id);
            }
        });
        User::deleted(function (User $user): void {
            EmployeeProfileCache::invalidate((int) $user->id);
            AttendanceCacheService::invalidate((int) $user->id);
            EmployeeDashboardCacheService::invalidate((int) $user->id);
            AdminDashboardCache::flush();
            Cache::forget('permissions:user:'.(int) $user->id);
            Cache::forget('sidebar:user:'.(int) $user->id);
        });

        $invalidateAttendanceForLog = function (AttendanceLog $log): void {
            if (! $log->user_id) {
                return;
            }
            $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
            $stamp = $log->verified_at ?? $log->created_at;
            $date = $stamp !== null
                ? Carbon::parse($stamp)->timezone($tz)->toDateString()
                : null;
            AttendanceCacheService::invalidate((int) $log->user_id, $date);
            EmployeeDashboardCacheService::invalidate((int) $log->user_id);
            AdminDashboardCache::flush();
        };
        AttendanceLog::saved($invalidateAttendanceForLog);
        AttendanceLog::deleted($invalidateAttendanceForLog);

        AttendanceCorrection::saved(function (AttendanceCorrection $correction): void {
            AdminDashboardCache::flush();
            if ($correction->user_id) {
                $date = $correction->date?->toDateString();
                AttendanceCacheService::invalidate((int) $correction->user_id, $date);
                EmployeeDashboardCacheService::invalidate((int) $correction->user_id);
            }
        });
        AttendanceCorrection::deleted(function (AttendanceCorrection $correction): void {
            AdminDashboardCache::flush();
            if ($correction->user_id) {
                $date = $correction->date?->toDateString();
                AttendanceCacheService::invalidate((int) $correction->user_id, $date);
                EmployeeDashboardCacheService::invalidate((int) $correction->user_id);
            }
        });

        LeaveRequest::saved(function (LeaveRequest $leave): void {
            AdminDashboardCache::flush();
            if (! $leave->user_id) {
                return;
            }
            if ($leave->wasChanged('status') || $leave->status === LeaveRequest::STATUS_APPROVED) {
                AttendanceCacheService::invalidate((int) $leave->user_id);
                EmployeeDashboardCacheService::invalidate((int) $leave->user_id);
            }
        });
        LeaveRequest::deleted(function (LeaveRequest $leave): void {
            AdminDashboardCache::flush();
            if ($leave->user_id) {
                AttendanceCacheService::invalidate((int) $leave->user_id);
                EmployeeDashboardCacheService::invalidate((int) $leave->user_id);
            }
        });

        Overtime::saved(function (Overtime $overtime): void {
            AdminDashboardCache::flush();
            if (! $overtime->user_id) {
                return;
            }
            if ($overtime->wasChanged('status') || $overtime->status === Overtime::STATUS_APPROVED) {
                $date = $overtime->date?->toDateString();
                AttendanceCacheService::invalidate((int) $overtime->user_id, $date);
                EmployeeDashboardCacheService::invalidate((int) $overtime->user_id);
            }
        });
        Overtime::deleted(function (Overtime $overtime): void {
            AdminDashboardCache::flush();
            if ($overtime->user_id) {
                $date = $overtime->date?->toDateString();
                AttendanceCacheService::invalidate((int) $overtime->user_id, $date);
                EmployeeDashboardCacheService::invalidate((int) $overtime->user_id);
            }
        });

        PayrollBatchRun::saved(fn (PayrollBatchRun $run) => AdminDashboardCache::flush());
        PayrollBatchRun::deleted(fn (PayrollBatchRun $run) => AdminDashboardCache::flush());
        PayrollEmployee::saved(fn (PayrollEmployee $employee) => AdminDashboardCache::flush());
        PayrollEmployee::deleted(fn (PayrollEmployee $employee) => AdminDashboardCache::flush());

        EmployeeGovernmentId::saved(function (EmployeeGovernmentId $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });
        EmployeeGovernmentId::deleted(function (EmployeeGovernmentId $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });

        EmployeeEmergencyContact::saved(function (EmployeeEmergencyContact $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });
        EmployeeEmergencyContact::deleted(function (EmployeeEmergencyContact $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });

        EmployeeBenefit::saved(function (EmployeeBenefit $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });
        EmployeeBenefit::deleted(function (EmployeeBenefit $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });

        EmployeeCompensationComponent::saved(function (EmployeeCompensationComponent $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });
        EmployeeCompensationComponent::deleted(function (EmployeeCompensationComponent $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::invalidate((int) $record->user_id);
            }
        });

        Holiday::saved(function (Holiday $h) {
            app(HolidayCalendarService::class)->flushMergedYearCaches();
            EmployeeDashboardCacheService::invalidateAll();
            if ($h->is_swap) {
                $dateKey = $h->date instanceof \Carbon\Carbon ? $h->date->format('Y-m-d') : (string) $h->date;
                app(HolidayService::class)->flushCoverageForDate($dateKey);
            }
        });
        Holiday::deleted(function (Holiday $h) {
            app(HolidayCalendarService::class)->flushMergedYearCaches();
            EmployeeDashboardCacheService::invalidateAll();
            if ($h->is_swap) {
                $dateKey = $h->date instanceof \Carbon\Carbon ? $h->date->format('Y-m-d') : (string) $h->date;
                app(HolidayService::class)->flushCoverageForDate($dateKey);
            }
        });

        Event::listen(ScheduleUpdated::class, RecalculatePayrollDailyRecords::class);
        Event::listen(ScheduleUpdated::class, function (ScheduleUpdated $event): void {
            foreach ($event->affectedUserIds as $userId) {
                AttendanceCacheService::invalidate((int) $userId);
                EmployeeDashboardCacheService::invalidate((int) $userId);
            }
        });

        $mirror = fn () => app(LegacyOrganizationMirrorService::class);
        Company::saved(fn (Company $model) => $mirror()->sync($model));
        Branch::saved(fn (Branch $model) => $mirror()->sync($model));
        Division::saved(fn (Division $model) => $mirror()->sync($model));
        Department::saved(fn (Department $model) => $mirror()->sync($model));
        SectionUnit::saved(fn (SectionUnit $model) => $mirror()->sync($model));
        Team::saved(fn (Team $model) => $mirror()->sync($model));
        User::saved(function (User $user) use ($mirror): void {
            if ($user->wasChanged(['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id', 'team_id', 'supervisor_id'])) {
                $mirror()->sync($user);
            }
        });
        Company::deleted(fn (Company $model) => $mirror()->deactivate($model));
        Branch::deleted(fn (Branch $model) => $mirror()->deactivate($model));
        Division::deleted(fn (Division $model) => $mirror()->deactivate($model));
        Department::deleted(fn (Department $model) => $mirror()->deactivate($model));
        SectionUnit::deleted(fn (SectionUnit $model) => $mirror()->deactivate($model));
        Team::deleted(fn (Team $model) => $mirror()->deactivate($model));
    }
}
