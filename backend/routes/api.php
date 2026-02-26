<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\AttendanceMonitoringController;
use App\Http\Controllers\Admin\AttendanceCorrectionController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\LeaveController;
use App\Http\Controllers\Admin\OvertimeController;
use App\Http\Controllers\EmployeeLeaveController;
use App\Http\Controllers\EmployeeOvertimeController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Unified scan: optional auth (kiosk = no token, employee = Bearer). Real-time flow: decode QR → validate → record → JSON.
Route::post('/attendance/scan', [AttendanceController::class, 'scan']);

// Kiosk: QR-only attendance (no login required) — kept for backward compatibility
Route::post('/attendance/kiosk', [AttendanceController::class, 'recordKiosk']);
Route::get('/attendance/kiosk/recent', [AttendanceController::class, 'recentKiosk']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/auth/verify-qr', [AuthController::class, 'verifyQr']);

    Route::patch('/profile', [\App\Http\Controllers\ProfileController::class, 'update']);
    Route::post('/profile/photo', [\App\Http\Controllers\ProfileController::class, 'uploadPhoto']);
    Route::delete('/profile/photo', [\App\Http\Controllers\ProfileController::class, 'removePhoto']);

    Route::post('/attendance', [AttendanceController::class, 'record']);
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
    Route::get('/leave/my', [EmployeeLeaveController::class, 'my']);
    Route::post('/leave', [EmployeeLeaveController::class, 'apply']);
    Route::post('/leave/{id}/document', [EmployeeLeaveController::class, 'uploadDocument']);
    Route::post('/overtime/request', [EmployeeOvertimeController::class, 'store']);

    Route::middleware('admin')->group(function () {
        Route::get('/admin/dashboard', [DashboardController::class, 'index']);
        Route::get('/admin/attendance', [AttendanceMonitoringController::class, 'index']);
        Route::post('/admin/attendance/corrections', [AttendanceCorrectionController::class, 'store']);
        Route::delete('/admin/attendance/corrections/{id}', [AttendanceCorrectionController::class, 'destroy']);
        Route::get('/admin/reports/summary', [ReportsController::class, 'summary']);
        Route::get('/admin/reports/detailed', [ReportsController::class, 'detailed']);
        Route::get('/admin/schedules', [ScheduleController::class, 'index']);
        Route::post('/admin/schedules', [ScheduleController::class, 'store']);
        Route::patch('/admin/schedules/{id}', [ScheduleController::class, 'update']);
        Route::delete('/admin/schedules/{id}', [ScheduleController::class, 'destroy']);
        Route::post('/admin/schedules/{id}/assign', [ScheduleController::class, 'assign']);
        Route::get('/admin/employees', [EmployeeController::class, 'index']);
        Route::post('/admin/employees', [EmployeeController::class, 'store']);
        Route::get('/admin/employees/{id}/qr', [EmployeeController::class, 'getQr']);
        Route::post('/admin/employees/{id}/qr/regenerate', [EmployeeController::class, 'regenerateQr']);
        Route::delete('/admin/employees/{id}/qr', [EmployeeController::class, 'clearQr']);
        Route::patch('/admin/employees/{id}/schedule', [EmployeeController::class, 'updateSchedule']);
        Route::patch('/admin/employees/{id}/toggle-active', [EmployeeController::class, 'toggleActive']);
        Route::post('/admin/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword']);
        Route::get('/admin/departments', [DepartmentController::class, 'index']);
        Route::post('/admin/departments', [DepartmentController::class, 'store']);
        Route::get('/admin/departments/{id}/employees', [DepartmentController::class, 'employees']);
        Route::patch('/admin/departments/{id}', [DepartmentController::class, 'update']);
        Route::delete('/admin/departments/{id}', [DepartmentController::class, 'destroy']);
        Route::post('/admin/departments/{id}/assign-employees', [DepartmentController::class, 'assignEmployees']);
        Route::post('/admin/departments/{id}/unassign-employees', [DepartmentController::class, 'unassignEmployees']);
        Route::get('/admin/leave', [LeaveController::class, 'index']);
        Route::post('/admin/leave', [LeaveController::class, 'store']);
        Route::post('/admin/leave/{id}/approve', [LeaveController::class, 'approve']);
        Route::post('/admin/leave/{id}/reject', [LeaveController::class, 'reject']);
        Route::patch('/admin/leave/{id}/notes', [LeaveController::class, 'updateNotes']);
        Route::get('/admin/overtime', [OvertimeController::class, 'index']);
        Route::get('/admin/overtime/export', [OvertimeController::class, 'export']);
        Route::get('/admin/overtime/{id}', [OvertimeController::class, 'show']);
        Route::patch('/admin/overtime/{id}/status', [OvertimeController::class, 'updateStatus']);
        Route::patch('/admin/overtime/{id}/hours', [OvertimeController::class, 'updateHours']);
    });
});
