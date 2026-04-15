<?php

namespace App\Providers;

use App\Models\EmployeeBenefit;
use App\Models\EmployeeCompensationComponent;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeGovernmentId;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayCalendarService;
use App\Support\EmployeeProfileCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HolidayCalendarService::class, fn () => new HolidayCalendarService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        Holiday::saved(fn () => app(HolidayCalendarService::class)->flushMergedYearCaches());
        Holiday::deleted(fn () => app(HolidayCalendarService::class)->flushMergedYearCaches());
    }
}
