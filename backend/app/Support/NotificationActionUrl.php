<?php

namespace App\Support;

use App\Models\User;
use App\Services\HrRoleResolver;

/**
 * In-app notification deep links (relative frontend paths).
 * Org heads use the /employee shell; HR admin uses /admin.
 */
class NotificationActionUrl
{
    public static function forApprover(User $approver, string $module, int $requestId): string
    {
        $query = '?review_id='.$requestId;
        $adminShell = app(HrRoleResolver::class)->isAdminHrAccount($approver);

        if ($adminShell) {
            return match ($module) {
                'leave' => '/admin/leave'.$query,
                'overtime' => '/admin/overtime'.$query,
                'attendance_correction' => '/admin/corrections'.$query,
                default => '/admin/dashboard',
            };
        }

        return match ($module) {
            'leave' => '/employee/requests'.$query,
            'overtime' => '/employee/overtime'.$query,
            'attendance_correction' => '/employee/correction-requests'.$query,
            default => '/employee/dashboard',
        };
    }
}
