<?php

use App\Http\Controllers\Admin\AdminUserAccountController;
use App\Http\Controllers\Admin\AttendanceCorrectionController;
use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Http\Controllers\Admin\BenefitCatalogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeductionScheduleController;
use App\Http\Controllers\Admin\DeductionTypeController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EmployeeBenefitController;
use App\Http\Controllers\Admin\EmployeeCompensationController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EmployeeDeductionController;
use App\Http\Controllers\Admin\EmployeeSkillController as AdminEmployeeSkillController;
use App\Http\Controllers\Admin\EmployeeStatusController;
use App\Http\Controllers\Admin\GovernmentContributionController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\ImportEmployeeController;
use App\Http\Controllers\Admin\LeaveController;
use App\Http\Controllers\Admin\LoanRequestController;
use App\Http\Controllers\Admin\OvertimeController;
use App\Http\Controllers\Admin\PayComponentController;
use App\Http\Controllers\Admin\PayCycleController;
use App\Http\Controllers\Admin\PayPolicyController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayrollFinalizeController;
use App\Http\Controllers\Admin\PayrollPeriodUnlockController;
use App\Http\Controllers\Admin\PayslipController as AdminPayslipController;
use App\Http\Controllers\Admin\RbacController;
use App\Http\Controllers\Admin\RegularizationApprovalController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ScheduleRequestController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeCertificationController;
use App\Http\Controllers\EmployeeContributionController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\EmployeeGovernmentIdDocumentController;
use App\Http\Controllers\EmployeeLeaveController;
use App\Http\Controllers\EmployeeLoanRequestController;
use App\Http\Controllers\EmployeeOvertimeController;
use App\Http\Controllers\EmployeePayslipController;
use App\Http\Controllers\EmployeeProfileController;
use App\Http\Controllers\EmployeeSkillController;
use App\Http\Controllers\LivenessController;
use App\Http\Controllers\MyScheduleController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PayslipDownloadController;
use App\Http\Controllers\PresenceFilingController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\RegularizationController;
use App\Http\Controllers\SkillSuggestionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/qr', [AuthController::class, 'loginWithQr']);
Route::post('/login/face', [AuthController::class, 'loginWithFace']);
Route::post('/password/forgot', [PasswordResetController::class, 'requestOtp']);
Route::post('/password/verify-otp', [PasswordResetController::class, 'verifyOtp']);
Route::post('/password/reset', [PasswordResetController::class, 'resetPassword']);
Route::post('/face/liveness/session', [LivenessController::class, 'createSession']);
Route::post('/face/liveness/results', [LivenessController::class, 'sessionResults']);
Route::get('/face/liveness/session/{sessionId}', [LivenessController::class, 'getSessionResult']);
Route::post('/face/verify-only', [AttendanceController::class, 'verifyFaceOnly']);
Route::get('/media/public/{path}', [PublicMediaController::class, 'show'])->where('path', '.*');

// Unified scan: optional auth (kiosk = no token, employee = Bearer). Real-time flow: decode QR → validate → record → JSON.
Route::post('/attendance/scan', [AttendanceController::class, 'scan']);

// Kiosk: QR-only attendance (no login required) — kept for backward compatibility
Route::post('/attendance/kiosk', [AttendanceController::class, 'recordKiosk']);
Route::post('/attendance/kiosk/face', [AttendanceController::class, 'scanFace']);
Route::get('/attendance/kiosk/recent', [AttendanceController::class, 'recentKiosk']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/auth/verify-qr', [AuthController::class, 'verifyQr']);

    Route::patch('/profile', [\App\Http\Controllers\ProfileController::class, 'update']);
    Route::post('/profile/photo', [\App\Http\Controllers\ProfileController::class, 'uploadPhoto']);
    Route::delete('/profile/photo', [\App\Http\Controllers\ProfileController::class, 'removePhoto']);
    Route::get('/profile/qr', [\App\Http\Controllers\ProfileController::class, 'getMyQr']);
    Route::post('/profile/qr/regenerate', [\App\Http\Controllers\ProfileController::class, 'regenerateMyQr']);
    Route::get('/profile/face', [\App\Http\Controllers\ProfileController::class, 'getMyFace']);
    Route::post('/profile/face/register', [\App\Http\Controllers\ProfileController::class, 'registerMyFace']);
    Route::get('/profile/face/register/status/{trackId}', [\App\Http\Controllers\ProfileController::class, 'faceRegistrationStatus']);
    Route::delete('/profile/face', [\App\Http\Controllers\ProfileController::class, 'removeMyFace']);

    // Employee Dashboard → Profile module (tabbed). Each tab has independent validation/saving.
    Route::get('/employee/profile', [EmployeeProfileController::class, 'show']);
    Route::get('/employee/profile/export/csv', [EmployeeProfileController::class, 'exportMyCsv'])->middleware('permission:profile.view');
    Route::patch('/employee/profile/personal', [EmployeeProfileController::class, 'updatePersonal']);
    Route::post('/employee/profile/signature', [EmployeeProfileController::class, 'saveSignature']);
    Route::delete('/employee/profile/signature', [EmployeeProfileController::class, 'clearSignature']);
    Route::patch('/employee/profile/government-ids', [EmployeeProfileController::class, 'updateGovernmentIds']);
    Route::put('/employee/profile/emergency-contacts', [EmployeeProfileController::class, 'replaceEmergencyContacts']);
    Route::get('/employee/profile/skills', [EmployeeSkillController::class, 'index']);
    Route::post('/employee/profile/skills', [EmployeeSkillController::class, 'store']);
    Route::patch('/employee/profile/skills/{id}', [EmployeeSkillController::class, 'update']);
    Route::delete('/employee/profile/skills/{id}', [EmployeeSkillController::class, 'destroy']);
    Route::get('/employee/profile/certifications', [EmployeeCertificationController::class, 'index']);
    Route::post('/employee/profile/certifications', [EmployeeCertificationController::class, 'store']);
    Route::post('/employee/profile/certifications/{id}', [EmployeeCertificationController::class, 'update']);
    Route::delete('/employee/profile/certifications/{id}', [EmployeeCertificationController::class, 'destroy']);
    Route::get('/employee/profile/government-id-documents', [EmployeeGovernmentIdDocumentController::class, 'index']);
    Route::post('/employee/profile/government-id-documents', [EmployeeGovernmentIdDocumentController::class, 'store']);
    Route::post('/employee/profile/government-id-documents/{id}', [EmployeeGovernmentIdDocumentController::class, 'update']);
    Route::delete('/employee/profile/government-id-documents/{id}', [EmployeeGovernmentIdDocumentController::class, 'destroy']);
    Route::get('/employee/profile/documents', [EmployeeDocumentController::class, 'index']);
    Route::post('/employee/profile/documents', [EmployeeDocumentController::class, 'store']);
    Route::post('/employee/profile/documents/{id}', [EmployeeDocumentController::class, 'update']);
    Route::delete('/employee/profile/documents/{id}', [EmployeeDocumentController::class, 'destroy']);
    Route::get('/skills/suggestions', [SkillSuggestionController::class, 'index']);

    Route::post('/attendance', [AttendanceController::class, 'record']);
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
    Route::get('/employee/presence-filing/attendance-detail', [PresenceFilingController::class, 'attendanceDetail']);
    Route::post('/employee/presence-filing', [PresenceFilingController::class, 'store']);
    Route::get('/employee/presence-filing', [PresenceFilingController::class, 'mine']);
    Route::get('/employee/presence-filings', [PresenceFilingController::class, 'listMine']);
    Route::delete('/employee/presence-filings/{id}', [PresenceFilingController::class, 'destroy']);
    Route::get('/leave/my', [EmployeeLeaveController::class, 'my']);
    Route::get('/leave/halfday-availability', [EmployeeLeaveController::class, 'halfdayAvailability']);
    Route::get('/leave/undertime-preview', [EmployeeLeaveController::class, 'undertimePreview']);
    Route::get('/leave/paid-leave-preview', [EmployeeLeaveController::class, 'paidLeavePreview']);
    Route::get('/leave/validate-range', [EmployeeLeaveController::class, 'validateLeaveDateRange']);
    Route::post('/leave', [EmployeeLeaveController::class, 'apply']);
    Route::post('/leave/{id}/document', [EmployeeLeaveController::class, 'uploadDocument']);
    Route::delete('/leave/{id}', [EmployeeLeaveController::class, 'destroy']);
    Route::get('/overtime/request-context', [EmployeeOvertimeController::class, 'requestContext']);
    Route::get('/overtime/my', [EmployeeOvertimeController::class, 'myIndex']);
    Route::get('/overtime/my/{id}', [EmployeeOvertimeController::class, 'myShow']);
    Route::patch('/overtime/my/{id}', [EmployeeOvertimeController::class, 'myUpdate']);
    Route::delete('/overtime/my/{id}', [EmployeeOvertimeController::class, 'myDestroy']);
    Route::post('/overtime/request', [EmployeeOvertimeController::class, 'store']);
    /** Anyone who can file a schedule change must also load their own request history (same data scope). */
    Route::middleware('permission:view-my-schedule|request-schedule')->get('/my-schedule', [MyScheduleController::class, 'index']);
    Route::middleware('permission:request-schedule')->get('/my-schedule/request-context', [MyScheduleController::class, 'requestContext']);
    Route::middleware('permission:request-schedule')->post('/my-schedule/requests', [MyScheduleController::class, 'store']);
    Route::middleware('permission:request-schedule')->delete('/my-schedule/requests/{id}', [MyScheduleController::class, 'destroy']);
    Route::get('/employee/contributions', [EmployeeContributionController::class, 'mine']);

    /** Payslips (self-service; own records only — {@see EmployeePayslipController}). */
    Route::middleware('permission:payslip.view')->group(function () {
        Route::get('/employee/payslips', [EmployeePayslipController::class, 'index']);
        Route::get('/employee/payslips/salary-history', [EmployeePayslipController::class, 'salaryHistory']);
        Route::get('/employee/payslips/{id}/data', [EmployeePayslipController::class, 'viewData'])->whereNumber('id');
        Route::get('/employee/payslips/{id}', [EmployeePayslipController::class, 'show'])->whereNumber('id');
    });
    Route::middleware('permission:payslip.download')->group(function () {
        Route::get('/payslips/{id}/download', [PayslipDownloadController::class, 'download'])->whereNumber('id');
        Route::get('/employee/payslips/{id}/pdf', [EmployeePayslipController::class, 'download'])->whereNumber('id');
        Route::get('/employee/payslips/{id}/print', [EmployeePayslipController::class, 'downloadPrint'])->whereNumber('id');
    });

    Route::get('/employee/loan-requests/context', [EmployeeLoanRequestController::class, 'deductionsContext']);
    Route::get('/employee/loan-requests/next-deduction-dates', [EmployeeLoanRequestController::class, 'nextDeductionDates']);
    Route::get('/employee/my-deductions', [EmployeeLoanRequestController::class, 'myDeductions']);
    Route::post('/employee/loan-requests', [EmployeeLoanRequestController::class, 'store']);
    Route::get('/employee/loan-requests/{id}', [EmployeeLoanRequestController::class, 'show']);

    /** Plain employees only (not org heads): self-service detailed report without HR panel / reports.view. */
    Route::get('/employee/reports/detailed', [ReportsController::class, 'detailed'])->name('employee.reports.detailed');

    /** Subject employee: read-only list of recommendations about self (no HR panel required). */
    Route::get('/regularization/my-status', [RegularizationController::class, 'myRegularizationAsSubject']);

    Route::middleware(['hr.panel'])->group(function () {
        // Regularization: org heads + admin HR recommend (not plain employees)
        Route::get('/regularization/eligible-employees', [RegularizationController::class, 'eligibleEmployees']);
        Route::post('/regularization/recommend', [RegularizationController::class, 'submitRecommendation']);
        Route::get('/regularization/my-recommendations', [RegularizationController::class, 'myRecommendations']);
        Route::get('/regularization/required-actions', [RegularizationController::class, 'requiredActions']);
        Route::patch('/regularization/required-actions/{userId}', [RegularizationController::class, 'updateRequiredActions']);

        Route::middleware('permission:dashboard.view')->group(function () {
            Route::get('/admin/dashboard', [DashboardController::class, 'index']);
            Route::get('/admin/dashboard/birthdays', [DashboardController::class, 'birthdaysByMonth']);
            Route::get('/admin/dashboard/half-day-list', [DashboardController::class, 'halfDayList']);
            Route::get('/admin/dashboard/company-attendance', [DashboardController::class, 'companyAttendance']);
        });

        Route::middleware('permission:attendance.view')->group(function () {
            Route::get('/admin/attendance', [AttendanceMonitoringController::class, 'index']);
            Route::get('/admin/attendance/export', [AttendanceMonitoringController::class, 'export']);
        });
        Route::middleware('permission:attendance.corrections.create')->post('/admin/attendance/corrections', [AttendanceCorrectionController::class, 'store']);
        Route::middleware('permission:attendance.corrections.delete')->delete('/admin/attendance/corrections/{id}', [AttendanceCorrectionController::class, 'destroy']);

        Route::middleware('permission:attendance.corrections.approve')->group(function () {
            Route::get('/admin/presence-filings', [PresenceFilingController::class, 'adminIndex']);
            Route::get('/admin/presence-filings/attendance-detail', [PresenceFilingController::class, 'adminAttendanceDetail']);
            Route::post('/admin/presence-filings', [PresenceFilingController::class, 'adminStore']);
            Route::post('/admin/presence-filings/bulk-approve-preview', [PresenceFilingController::class, 'bulkApprovePreview']);
            Route::post('/admin/presence-filings/bulk-approve', [PresenceFilingController::class, 'bulkApprove']);
            Route::post('/admin/presence-filings/{id}/approve', [PresenceFilingController::class, 'approve']);
            Route::post('/admin/presence-filings/{id}/reject', [PresenceFilingController::class, 'reject']);
            Route::post('/admin/presence-filings/{id}/note', [PresenceFilingController::class, 'addHrNote']);
            Route::delete('/admin/presence-filings/{id}', [PresenceFilingController::class, 'destroy']);
        });

        Route::middleware('permission:holiday.view')->get('/admin/holidays', [HolidayController::class, 'index']);
        Route::middleware('permission:holiday.manage')->post('/admin/holidays', [HolidayController::class, 'store']);
        Route::middleware('permission:holiday.manage')->post('/admin/holidays/swap', [HolidayController::class, 'storeSwap']);
        Route::middleware('permission:holiday.manage')->post('/admin/holidays/seeded/swap', [HolidayController::class, 'swapSeeded']);
        Route::middleware('permission:holiday.manage')->post('/admin/holidays/{id}/swap', [HolidayController::class, 'swap']);
        Route::middleware('permission:holiday.manage')->patch('/admin/holidays/{id}/swap', [HolidayController::class, 'updateSwap']);
        Route::middleware('permission:holiday.manage')->patch('/admin/holidays/{id}', [HolidayController::class, 'update']);
        Route::middleware('permission:holiday.manage')->delete('/admin/holidays/{id}', [HolidayController::class, 'destroy']);

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/admin/reports/detailed', [ReportsController::class, 'detailed']);
            Route::post('/admin/reports/detailed/export', [ReportsController::class, 'queueDetailedExport']);
            Route::get('/admin/reports/detailed/export/{id}/status', [ReportsController::class, 'detailedExportStatus']);
            Route::get('/admin/reports/leave-credits', [ReportsController::class, 'leaveCredits']);
        });

        Route::middleware('permission:manage-schedules|schedule.view')->get('/admin/schedules', [ScheduleController::class, 'index']);
        Route::middleware('permission:manage-schedules|schedule.manage')->post('/admin/schedules', [ScheduleController::class, 'store']);
        Route::middleware('permission:manage-schedules|schedule.manage')->patch('/admin/schedules/{id}', [ScheduleController::class, 'update']);
        Route::middleware('permission:manage-schedules|schedule.manage')->delete('/admin/schedules/{id}', [ScheduleController::class, 'destroy']);
        Route::middleware('permission:manage-schedules|schedule.assign')->post('/admin/schedules/{id}/assign', [ScheduleController::class, 'assign']);
        Route::middleware('permission:approve-schedule|manage-schedules')->get('/admin/schedule-requests', [ScheduleRequestController::class, 'index']);
        Route::middleware('permission:approve-schedule|manage-schedules')->get('/admin/schedule-requests/{id}', [ScheduleRequestController::class, 'show']);
        Route::middleware('permission:approve-schedule|manage-schedules')->post('/admin/schedule-requests/{id}/approve', [ScheduleRequestController::class, 'approve']);
        Route::middleware('permission:approve-schedule|manage-schedules')->post('/admin/schedule-requests/{id}/reject', [ScheduleRequestController::class, 'reject']);
        Route::middleware('permission:approve-schedule|manage-schedules')->delete('/admin/schedule-requests/{id}', [ScheduleRequestController::class, 'destroy']);

        Route::middleware('permission:employees.view')->group(function () {
            Route::get('/admin/employees', [EmployeeController::class, 'index']);
            Route::get('/admin/employees/export/csv', [EmployeeController::class, 'exportAllCsv'])->middleware('permission:employees.export');
            Route::get('/admin/employees/{id}/qr', [EmployeeController::class, 'getQr']);
            Route::get('/admin/employees/{id}/face', [EmployeeController::class, 'getFace']);
            Route::get('/admin/employees/{userId}/skills', [AdminEmployeeSkillController::class, 'index']);
            Route::get('/admin/employees/{userId}/certifications', [\App\Http\Controllers\Admin\EmployeeCertificationController::class, 'index']);
            Route::get('/admin/employees/{userId}/government-id-documents', [\App\Http\Controllers\Admin\EmployeeGovernmentIdDocumentController::class, 'index']);
            Route::get('/admin/employees/{userId}/documents', [\App\Http\Controllers\Admin\EmployeeDocumentController::class, 'index']);
            Route::get('/admin/employees/{id}/profile', [EmployeeProfileController::class, 'showForViewer']);
            Route::get('/admin/employees/{id}/schedule-rate-preview', [EmployeeController::class, 'scheduleRatePreview']);
        });
        Route::middleware('permission:employees.create')->group(function () {
            Route::post('/admin/employees', [EmployeeController::class, 'store']);
            Route::post('/admin/employees/import/preview', [ImportEmployeeController::class, 'preview']);
            Route::post('/admin/employees/import', [ImportEmployeeController::class, 'import']);
        });
        Route::middleware('permission:employees.edit')->group(function () {
            Route::post('/admin/employees/{id}/qr/regenerate', [EmployeeController::class, 'regenerateQr']);
            Route::delete('/admin/employees/{id}/qr', [EmployeeController::class, 'clearQr']);
            Route::post('/admin/employees/{id}/signature', [EmployeeController::class, 'saveSignature']);
            Route::delete('/admin/employees/{id}/signature', [EmployeeController::class, 'clearSignature']);
            Route::post('/admin/employees/{id}/face/register', [EmployeeController::class, 'registerFace']);
            Route::get('/admin/employees/{id}/face/register/status/{trackId}', [EmployeeController::class, 'faceRegistrationStatus']);
            Route::patch('/admin/employees/{id}/face', [EmployeeController::class, 'updateFace']);
            Route::patch('/admin/employees/{id}/toggle-active', [EmployeeController::class, 'toggleActive']);
            Route::post('/admin/employees/{userId}/skills', [AdminEmployeeSkillController::class, 'store']);
            Route::patch('/admin/employees/{userId}/skills/{id}', [AdminEmployeeSkillController::class, 'update']);
            Route::delete('/admin/employees/{userId}/skills/{id}', [AdminEmployeeSkillController::class, 'destroy']);
            Route::post('/admin/employees/{userId}/certifications', [\App\Http\Controllers\Admin\EmployeeCertificationController::class, 'store']);
            Route::post('/admin/employees/{userId}/certifications/{id}', [\App\Http\Controllers\Admin\EmployeeCertificationController::class, 'update']);
            Route::delete('/admin/employees/{userId}/certifications/{id}', [\App\Http\Controllers\Admin\EmployeeCertificationController::class, 'destroy']);
            Route::post('/admin/employees/{userId}/certifications/{id}/verify', [\App\Http\Controllers\Admin\EmployeeCertificationController::class, 'verify']);
            Route::post('/admin/employees/{userId}/government-id-documents', [\App\Http\Controllers\Admin\EmployeeGovernmentIdDocumentController::class, 'store']);
            Route::post('/admin/employees/{userId}/government-id-documents/{id}', [\App\Http\Controllers\Admin\EmployeeGovernmentIdDocumentController::class, 'update']);
            Route::delete('/admin/employees/{userId}/government-id-documents/{id}', [\App\Http\Controllers\Admin\EmployeeGovernmentIdDocumentController::class, 'destroy']);
            Route::post('/admin/employees/{userId}/government-id-documents/{id}/verify', [\App\Http\Controllers\Admin\EmployeeGovernmentIdDocumentController::class, 'verify']);
            Route::post('/admin/employees/{userId}/documents', [\App\Http\Controllers\Admin\EmployeeDocumentController::class, 'store']);
            Route::post('/admin/employees/{userId}/documents/{id}', [\App\Http\Controllers\Admin\EmployeeDocumentController::class, 'update']);
            Route::delete('/admin/employees/{userId}/documents/{id}', [\App\Http\Controllers\Admin\EmployeeDocumentController::class, 'destroy']);
            Route::post('/admin/employees/{userId}/documents/{id}/review', [\App\Http\Controllers\Admin\EmployeeDocumentController::class, 'review']);
            Route::post('/admin/employees/{id}/leave-credits/adjust', [EmployeeController::class, 'adjustLeaveCredits']);
        });

        // Profile PATCH: HR often has `employees.edit` without `profile.edit`; allow either.
        Route::middleware('permission:employees.edit|profile.edit')->patch('/admin/employees/{id}', [EmployeeController::class, 'update']);
        Route::middleware('permission:employees.edit|profile.picture.edit')->post('/admin/employees/{id}/photo', [EmployeeController::class, 'uploadPhoto']);
        Route::middleware('permission:employees.edit|profile.picture.edit')->delete('/admin/employees/{id}/photo', [EmployeeController::class, 'removePhoto']);

        Route::middleware('permission:manage-schedules|schedule.assign|employees.edit')->patch('/admin/employees/{id}/schedule', [EmployeeController::class, 'updateSchedule']);
        Route::middleware('permission:employees.delete')->delete('/admin/employees/{id}', [EmployeeController::class, 'destroy']);
        Route::middleware('permission:employees.delete')->post('/admin/employees/import/rollback', [ImportEmployeeController::class, 'rollback']);
        Route::middleware('permission:employees.transfer')->post('/admin/employees/{id}/transfer', [EmployeeController::class, 'transfer']);
        Route::middleware('permission:employees.password_reset')->post('/admin/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword']);

        Route::middleware('permission:org.department.view')->get('/admin/departments', [DepartmentController::class, 'index']);
        Route::middleware('permission:org.department.manage')->post('/admin/departments', [DepartmentController::class, 'store']);
        Route::middleware('permission:org.department.view')->get('/admin/departments/{id}/employees', [DepartmentController::class, 'employees']);
        Route::middleware('permission:org.department.manage')->patch('/admin/departments/{id}', [DepartmentController::class, 'update']);
        Route::middleware('permission:org.department.manage')->delete('/admin/departments/{id}', [DepartmentController::class, 'destroy']);
        Route::middleware('permission:org.department.manage')->post('/admin/departments/{id}/assign-employees', [DepartmentController::class, 'assignEmployees']);
        Route::middleware('permission:org.department.manage')->post('/admin/departments/{id}/unassign-employees', [DepartmentController::class, 'unassignEmployees']);

        Route::middleware('permission:org.company.view')->get('/admin/companies', [CompanyController::class, 'index']);
        Route::middleware('permission:org.company.manage')->post('/admin/companies', [CompanyController::class, 'store']);
        Route::middleware('permission:org.company.view')->get('/admin/companies/{id}/branches', [CompanyController::class, 'branches']);
        Route::middleware('permission:org.company.view')->patch('/admin/companies/{id}/profile', [CompanyController::class, 'updateProfile']);
        Route::middleware('permission:org.company.manage')->patch('/admin/companies/{id}', [CompanyController::class, 'update']);
        Route::middleware('permission:org.company.manage')->delete('/admin/companies/{id}', [CompanyController::class, 'destroy']);

        Route::middleware('permission:org.branch.view')->get('/admin/branches', [BranchController::class, 'index']);
        Route::middleware('permission:org.branch.manage')->post('/admin/branches', [BranchController::class, 'store']);
        Route::middleware('permission:org.branch.view')->get('/admin/branches/{id}/departments', [BranchController::class, 'departments']);
        Route::middleware('permission:org.branch.manage')->patch('/admin/branches/{id}', [BranchController::class, 'update']);
        Route::middleware('permission:org.branch.manage')->delete('/admin/branches/{id}', [BranchController::class, 'destroy']);

        Route::middleware('permission:benefits.catalog')->group(function () {
            Route::get('/admin/benefit-catalogs', [BenefitCatalogController::class, 'index']);
            Route::post('/admin/benefit-catalogs', [BenefitCatalogController::class, 'store']);
            Route::patch('/admin/benefit-catalogs/{id}', [BenefitCatalogController::class, 'update']);
            Route::delete('/admin/benefit-catalogs/{id}', [BenefitCatalogController::class, 'destroy']);
        });
        Route::middleware('permission:benefits.assign')->group(function () {
            Route::get('/admin/employees/{userId}/benefits', [EmployeeBenefitController::class, 'index']);
            Route::post('/admin/employees/{userId}/benefits', [EmployeeBenefitController::class, 'store']);
            Route::patch('/admin/employees/{userId}/benefits/{id}', [EmployeeBenefitController::class, 'update']);
            Route::delete('/admin/employees/{userId}/benefits/{id}', [EmployeeBenefitController::class, 'destroy']);
        });

        Route::middleware('permission:leave.view')->get('/admin/leave', [LeaveController::class, 'index']);
        Route::middleware('permission:leave.approve')->get('/admin/leave/validate-range', [LeaveController::class, 'validateLeaveDateRange']);
        Route::middleware('permission:leave.approve')->post('/admin/leave', [LeaveController::class, 'store']);
        Route::middleware('permission:leave.approve')->group(function () {
            Route::post('/admin/leave/bulk-approve-preview', [LeaveController::class, 'bulkApprovePreview']);
            Route::post('/admin/leave/bulk-approve', [LeaveController::class, 'bulkApprove']);
            Route::post('/admin/leave/{id}/approve', [LeaveController::class, 'approve']);
            Route::post('/admin/leave/{id}/reject', [LeaveController::class, 'reject']);
            Route::post('/admin/leave/{id}/document', [LeaveController::class, 'uploadDocument']);
        });
        Route::middleware('permission:leave.view')->delete('/admin/leave/{id}', [LeaveController::class, 'destroy']);
        Route::middleware('permission:leave.notes')->patch('/admin/leave/{id}/notes', [LeaveController::class, 'updateNotes']);

        Route::middleware('permission:overtime.view')->group(function () {
            Route::get('/admin/overtime', [OvertimeController::class, 'index']);
            Route::get('/admin/overtime/{id}', [OvertimeController::class, 'show']);
        });
        Route::middleware('permission:overtime.export')->get('/admin/overtime/export', [OvertimeController::class, 'export']);
        Route::middleware('permission:overtime.approve')->post('/admin/overtime/bulk-approve-preview', [OvertimeController::class, 'bulkApprovePreview']);
        Route::middleware('permission:overtime.approve')->post('/admin/overtime/bulk-approve', [OvertimeController::class, 'bulkApprove']);
        Route::middleware('permission:overtime.approve')->patch('/admin/overtime/{id}/status', [OvertimeController::class, 'updateStatus']);
        Route::middleware('permission:overtime.edit_hours')->patch('/admin/overtime/{id}/hours', [OvertimeController::class, 'updateHours']);
        Route::middleware('permission:overtime.view')->delete('/admin/overtime/{id}', [OvertimeController::class, 'destroy']);

        Route::middleware('permission:payroll.view')->group(function () {
            Route::get('/admin/payroll/classify', [PayrollController::class, 'classify']);
            Route::get('/admin/payroll/preview', [PayrollController::class, 'preview']);
            Route::get('/admin/payroll/periods', [PayrollController::class, 'periods']);
            Route::get('/admin/payroll/periods/{id}', [PayrollController::class, 'showPeriod']);
            Route::get('/admin/payroll/daily-logs', [PayrollController::class, 'dailyLogs']);
            Route::get('/admin/payroll/policy-reference', [PayrollController::class, 'policyReference']);
        });
        Route::middleware('permission:payroll.compute')->post('/admin/payroll/compute', [PayrollController::class, 'compute']);
        /** Finalize payroll batch and send payslips. */
        Route::middleware('permission:payslip.finalize')->group(function () {
            Route::post('/admin/payroll/finalize/preview', [PayrollFinalizeController::class, 'preview']);
            Route::post('/admin/payroll/finalize/execute', [PayrollFinalizeController::class, 'execute']);
            Route::post('/admin/payroll/finalize/employee', [PayrollFinalizeController::class, 'finalizeEmployee']);
            Route::post('/admin/payroll/finalize/deliver-payslips', [PayrollFinalizeController::class, 'deliverPayslips']);
            Route::post('/admin/payroll-batches/{batchId}/bulk-send-payslips', [PayrollFinalizeController::class, 'bulkSendPayslips'])->whereNumber('batchId');
            Route::get('/admin/payroll/finalize/status/{batchRunId}', [PayrollFinalizeController::class, 'executeStatus']);
            Route::delete('/admin/payroll/finalize/batch/{batchRunId}', [PayrollFinalizeController::class, 'deleteBatch']);
        });
        /** Demote finalized payslips + unlock payroll_period rows (admin only; confirm required). */
        Route::post('/admin/payroll/unlock-period', [PayrollPeriodUnlockController::class, 'unlockPayWindow']);

        Route::middleware('permission:payslip.view')->group(function () {
            Route::get('/admin/payslips', [AdminPayslipController::class, 'index']);
            Route::get('/admin/payslips/recent-by-company', [AdminPayslipController::class, 'recentByCompany']);
            Route::get('/admin/payslips/preview-scope', [AdminPayslipController::class, 'previewScope']);
            Route::get('/admin/payslips/company-default-dates', [AdminPayslipController::class, 'companyDefaultDates']);
            Route::post('/admin/payslips/preview-sample', [AdminPayslipController::class, 'previewSample']);
            Route::post('/admin/payslips/preview-sample-data', [AdminPayslipController::class, 'previewSampleData']);
            Route::post('/admin/payslips/preview-employee', [AdminPayslipController::class, 'previewEmployee']);
            Route::post('/admin/payslips/preview-employee-data', [AdminPayslipController::class, 'previewEmployeeData']);
            Route::post('/admin/payslips/view-preview-data', [AdminPayslipController::class, 'viewPreviewData']);
            Route::get('/admin/payslips/{id}/data', [AdminPayslipController::class, 'showData'])->whereNumber('id');
            Route::get('/admin/payslips/{id}/view', [AdminPayslipController::class, 'viewData'])->whereNumber('id');
        });
        Route::middleware('permission:payslip.generate')->group(function () {
            Route::post('/admin/payslips/generate', [AdminPayslipController::class, 'generate']);
            Route::delete('/admin/payslips/batch/{id}', [AdminPayslipController::class, 'destroyDraftBatch'])->whereNumber('id');
        });
        Route::middleware('permission:payslip.download')->group(function () {
            Route::get('/admin/payslips/{id}/pdf', [AdminPayslipController::class, 'download'])->whereNumber('id');
            Route::post('/admin/payslips/zip', [AdminPayslipController::class, 'downloadZip']);
            Route::post('/admin/payroll-batches/{batchId}/bulk-download-pdf', [AdminPayslipController::class, 'bulkDownloadBatchPdf'])->whereNumber('batchId');
            Route::get('/admin/payslip-bulk-downloads/{id}/status', [AdminPayslipController::class, 'bulkDownloadStatus'])->whereNumber('id');
            Route::get('/admin/payslip-bulk-downloads/{id}/download', [AdminPayslipController::class, 'downloadBulkZip'])->whereNumber('id');
        });
        Route::middleware('permission:payroll.policies')->group(function () {
            Route::get('/admin/payroll/policies', [PayPolicyController::class, 'index']);
            Route::get('/admin/payroll/policies/companies', [PayPolicyController::class, 'companies']);
            Route::get('/admin/payroll/policies/condition-keys', [PayPolicyController::class, 'conditionKeys']);
            Route::get('/admin/payroll/policies/preview', [PayPolicyController::class, 'preview']);
            Route::get('/admin/payroll/policies/{id}', [PayPolicyController::class, 'show']);
            Route::post('/admin/payroll/policies', [PayPolicyController::class, 'store']);
            Route::put('/admin/payroll/policies/{id}', [PayPolicyController::class, 'update']);
            Route::post('/admin/payroll/policies/{id}/duplicate', [PayPolicyController::class, 'duplicate']);
            Route::delete('/admin/payroll/policies/{id}', [PayPolicyController::class, 'destroy']);
        });
        Route::middleware('permission:government_deductions.view|government_deductions.rates.view|government_deductions.audit|payroll.view')->group(function () {
            Route::get('/admin/payroll/statutory-rates', [GovernmentContributionController::class, 'rates']);
            Route::get('/admin/payroll/statutory-rates/history', [GovernmentContributionController::class, 'rateHistory']);
            Route::get('/admin/payroll/statutory/dashboard-summary', [GovernmentContributionController::class, 'dashboardSummary']);
            Route::get('/admin/payroll/remittances', [GovernmentContributionController::class, 'remittances']);
            Route::post('/admin/payroll/statutory/calculate', [GovernmentContributionController::class, 'calculate']);
            Route::post('/admin/payroll/withholding-tax/preview', [GovernmentContributionController::class, 'previewWithholdingTax']);
            Route::post('/admin/payroll/withholding-tax/classify-earnings', [GovernmentContributionController::class, 'classifyEarnings']);
            Route::post('/admin/payroll/withholding-tax/year-end-adjustment', [GovernmentContributionController::class, 'yearEndAdjustment']);
            Route::post('/admin/payroll/withholding-tax/retroactive-preview', [GovernmentContributionController::class, 'retroactiveTaxPreview']);
            Route::get('/admin/payroll/tax-tables', [GovernmentContributionController::class, 'taxTables']);
            Route::get('/admin/employees/{userId}/statutory-contributions', [GovernmentContributionController::class, 'history']);
            Route::get('/admin/employees/{userId}/tax-profile', [GovernmentContributionController::class, 'showEmployeeTaxProfile']);
        });
        Route::middleware('permission:government_deductions.manage|government_deductions.remittances.manage')->group(function () {
            Route::put('/admin/payroll/statutory-rates/{code}', [GovernmentContributionController::class, 'upsertRate']);
            Route::post('/admin/payroll/remittances/generate', [GovernmentContributionController::class, 'generateRemittance']);
            Route::put('/admin/employees/{userId}/tax-profile', [GovernmentContributionController::class, 'upsertEmployeeTaxProfile']);
        });
        Route::middleware('permission:compensation.view|compensation.employee_compensation.view')->group(function () {
            Route::get('/admin/pay-components', [PayComponentController::class, 'index']);
            Route::get('/admin/employee-compensation', [EmployeeCompensationController::class, 'index']);
            Route::get('/admin/pay-cycles', [PayCycleController::class, 'index']);
            Route::post('/admin/pay-cycles/preview', [PayCycleController::class, 'preview']);
            Route::get('/admin/deduction-schedule-settings', [DeductionScheduleController::class, 'index']);
            Route::get('/admin/deduction-schedule-settings/next-deduction-dates', [DeductionScheduleController::class, 'nextDeductionDates']);
        });
        Route::middleware('permission:compensation.pay_components.manage|compensation.pay_cycles.manage')->group(function () {
            Route::post('/admin/pay-components', [PayComponentController::class, 'store']);
            Route::patch('/admin/pay-components/{id}', [PayComponentController::class, 'update']);
            Route::delete('/admin/pay-components/{id}', [PayComponentController::class, 'destroy']);
            Route::post('/admin/pay-cycles', [PayCycleController::class, 'store']);
            Route::patch('/admin/pay-cycles/{id}', [PayCycleController::class, 'update']);
            Route::delete('/admin/pay-cycles/{id}', [PayCycleController::class, 'destroy']);
            Route::patch('/admin/deduction-schedule-settings', [DeductionScheduleController::class, 'update']);
            Route::post('/admin/deduction-schedule-settings/batch', [DeductionScheduleController::class, 'batchUpdate']);
        });
        Route::middleware('permission:compensation.employee_compensation.assign')->group(function () {
            Route::post('/admin/employee-compensation/assign', [EmployeeCompensationController::class, 'assign']);
            Route::patch('/admin/employees/{userId}/compensation/{id}', [EmployeeCompensationController::class, 'update']);
            Route::delete('/admin/employees/{userId}/compensation/{id}', [EmployeeCompensationController::class, 'destroy']);
        });

        Route::middleware('permission:loans.types.manage')->group(function () {
            Route::get('/admin/deduction-types', [DeductionTypeController::class, 'index']);
            Route::post('/admin/deduction-types', [DeductionTypeController::class, 'store']);
            Route::patch('/admin/deduction-types/{id}', [DeductionTypeController::class, 'update']);
        });
        Route::middleware('permission:loans.assign')->group(function () {
            Route::get('/admin/employees/{userId}/pay-deductions', [EmployeeDeductionController::class, 'index']);
            Route::post('/admin/employees/{userId}/pay-deductions', [EmployeeDeductionController::class, 'store']);
            Route::patch('/admin/employees/{userId}/pay-deductions/{id}', [EmployeeDeductionController::class, 'update']);
            Route::post('/admin/employees/{userId}/pay-deductions/{id}/early-payoff', [EmployeeDeductionController::class, 'earlyPayoff']);
            Route::patch('/admin/employees/{userId}/pay-deductions/{id}/balance', [EmployeeDeductionController::class, 'adjustBalance']);
            Route::get('/admin/employees/{userId}/pay-deductions/{id}/audit-logs', [EmployeeDeductionController::class, 'auditLogs']);
        });
        Route::middleware('permission:loans.view')->group(function () {
            Route::get('/admin/employee-deductions/active', [EmployeeDeductionController::class, 'activeInScope']);
            Route::get('/admin/loan-requests', [LoanRequestController::class, 'index']);
            Route::get('/admin/loan-requests/{id}', [LoanRequestController::class, 'show']);
        });
        Route::middleware('permission:loans.approve')->group(function () {
            Route::post('/admin/loan-requests/{id}/approve', [LoanRequestController::class, 'approve']);
            Route::post('/admin/loan-requests/{id}/reject', [LoanRequestController::class, 'reject']);
        });

        Route::middleware('permission:rbac.manage')->get('/admin/rbac/matrix', [RbacController::class, 'matrix']);
        Route::middleware('permission:rbac.audit')->get('/admin/rbac/audit', [RbacController::class, 'auditLog']);
        Route::middleware('permission:rbac.manage')->post('/admin/rbac/roles/{roleKey}/reset-defaults', [RbacController::class, 'resetRoleToDefaults']);
        Route::middleware('permission:rbac.manage')->put('/admin/rbac/roles/{roleKey}', [RbacController::class, 'syncRole']);

        Route::middleware('permission:users.view')->group(function () {
            Route::get('/admin/user-accounts', [AdminUserAccountController::class, 'index']);
            Route::get('/admin/user-accounts/{id}', [AdminUserAccountController::class, 'show']);
            Route::get('/admin/user-accounts/{id}/activity', [AdminUserAccountController::class, 'activity']);
        });
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/admin/user-accounts/bulk', [AdminUserAccountController::class, 'bulkUpdate']);
            Route::post('/admin/user-accounts', [AdminUserAccountController::class, 'store']);
            Route::patch('/admin/user-accounts/{id}', [AdminUserAccountController::class, 'update']);
            Route::post('/admin/user-accounts/{id}/reset-password', [AdminUserAccountController::class, 'resetPassword']);
        });

        // Employee status management and regularization approval (HR)
        Route::middleware('permission:employees.view')->group(function () {
            Route::get('/admin/employee-status/settings', [EmployeeStatusController::class, 'settings']);
            Route::get('/admin/employee-status/{userId}', [EmployeeStatusController::class, 'show']);
            Route::get('/admin/regularization/upcoming', [EmployeeStatusController::class, 'upcomingRegularizations']);
            Route::get('/admin/regularization/recommendations', [RegularizationApprovalController::class, 'index']);
        });
        Route::middleware('permission:employees.edit')->group(function () {
            Route::patch('/admin/employee-status/{userId}', [EmployeeStatusController::class, 'update']);
            Route::patch('/admin/employee-status/settings', [EmployeeStatusController::class, 'updateSettings']);
            Route::post('/admin/regularization/recommend', [RegularizationController::class, 'submitRecommendation']);
            Route::post('/admin/regularization/recommendations/{id}/approve', [RegularizationApprovalController::class, 'approve']);
            Route::post('/admin/regularization/recommendations/{id}/reject', [RegularizationApprovalController::class, 'reject']);
        });
    });
});
