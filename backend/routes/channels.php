<?php

use App\Models\User;
use App\Services\RbacService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::routes(['middleware' => ['auth:sanctum']]);

$notificationUserCanAny = static function (User $user, array $permissions): bool {
    if ($user->isSuperAdmin()) {
        return true;
    }

    return app(RbacService::class)->canAny($user, $permissions);
};

Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === (int) $userId || $user->isSuperAdmin();
});

Broadcast::channel('employee.{employeeId}', function (User $user, int $employeeId): bool {
    return (int) $user->id === (int) $employeeId || $user->isSuperAdmin();
});

Broadcast::channel('role.admin', function (User $user): bool {
    return $user->isAdmin() || $user->isSuperAdmin();
});

Broadcast::channel('role.hr', function (User $user) use ($notificationUserCanAny): bool {
    return $user->isAdmin()
        || $notificationUserCanAny($user, ['dashboard.view', 'leave.approve', 'attendance.corrections.approve']);
});

Broadcast::channel('role.payroll', function (User $user) use ($notificationUserCanAny): bool {
    return $notificationUserCanAny($user, ['payroll.view', 'payroll.compute', 'payslip.finalize', 'payslip.generate']);
});

Broadcast::channel('company.{companyId}', function (User $user, int $companyId): bool {
    return $user->isAdmin()
        || $user->isSuperAdmin()
        || (int) ($user->getEffectiveCompanyId() ?? 0) === (int) $companyId;
});

Broadcast::channel('department.{departmentId}', function (User $user, int $departmentId): bool {
    return $user->isAdmin()
        || $user->isSuperAdmin()
        || (int) ($user->department_id ?? 0) === (int) $departmentId;
});

Broadcast::channel('section.{sectionId}', function (User $user, int $sectionId): bool {
    return $user->isAdmin()
        || $user->isSuperAdmin()
        || (int) ($user->section_unit_id ?? 0) === (int) $sectionId;
});

Broadcast::channel('approval-step.{approverId}', function (User $user, int $approverId): bool {
    return (int) $user->id === (int) $approverId || $user->isSuperAdmin();
});
