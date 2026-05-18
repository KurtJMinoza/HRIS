<?php

namespace App\Providers;

use App\Events\ScheduleUpdated;
use App\Listeners\RecalculatePayrollDailyRecords;
use App\Models\EmployeeBenefit;
use App\Models\EmployeeCompensationComponent;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeGovernmentId;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayCalendarService;
use App\Services\HolidayService;
use App\Support\EmployeeProfileCache;
use Illuminate\Support\Facades\DB;
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

        User::saved(fn (User $user) => EmployeeProfileCache::invalidate((int) $user->id));
        User::deleted(fn (User $user) => EmployeeProfileCache::invalidate((int) $user->id));

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
            if ($h->is_swap) {
                $dateKey = $h->date instanceof \Carbon\Carbon ? $h->date->format('Y-m-d') : (string) $h->date;
                app(HolidayService::class)->flushCoverageForDate($dateKey);
            }
        });
        Holiday::deleted(function (Holiday $h) {
            app(HolidayCalendarService::class)->flushMergedYearCaches();
            if ($h->is_swap) {
                $dateKey = $h->date instanceof \Carbon\Carbon ? $h->date->format('Y-m-d') : (string) $h->date;
                app(HolidayService::class)->flushCoverageForDate($dateKey);
            }
        });

        Event::listen(ScheduleUpdated::class, RecalculatePayrollDailyRecords::class);
    }
}
