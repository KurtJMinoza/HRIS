# Dashboard Scope Bug Fix

## Problem
Branch heads, department heads, and company heads were seeing incorrect employee counts on their dashboards. For example, a branch head with only 4 employees in their branch was seeing 40+ employees in the dashboard cards.

## Root Cause
The `companyAttendanceDistribution()` method in `DashboardController` was not properly filtering attendance logs by the scoped employee IDs. When querying `AttendanceLog` for clock-in records, the query was not restricting results to employees within the actor's scope (branch/department/company).

## Solution
Applied scope filtering to all dashboard data queries:

### 1. **computeDailyStats() - Line ~380**
- Added `->whereIn('user_id', $activeEmployeeIds)` to the `AttendanceLog` query
- Ensures only clocked-in employees within scope are counted for present/late/absent stats

### 2. **companyAttendanceDistribution() - Line ~1050**
- Added scope validation before filtering by company IDs
- Ensures company filter respects the actor's organizational scope
- Added `->whereIn('user_id', $activeEmployeeIds)` to the `AttendanceLog` query
- Filters attendance logs to only scoped employees

### 3. **departmentAttendanceDistribution() - Line ~1000**
- Already uses `restrictEmployeeQuery()` to scope employees
- Verified that present user IDs are filtered from scoped employees only

## Key Changes

### Before
```php
$firstClockIn = AttendanceLog::query()
    ->where('type', AttendanceLog::TYPE_CLOCK_IN)
    ->whereBetween('created_at', [$rangeStart, $rangeEnd])
    ->select('user_id', DB::raw('MIN(created_at) as first_at'))
    ->groupBy('user_id')
    ->get()
    ->keyBy('user_id');
```

### After
```php
$firstClockIn = AttendanceLog::query()
    ->where('type', AttendanceLog::TYPE_CLOCK_IN)
    ->whereBetween('created_at', [$rangeStart, $rangeEnd])
    ->whereIn('user_id', $activeEmployeeIds)  // ← Added scope filter
    ->select('user_id', DB::raw('MIN(created_at) as first_at'))
    ->groupBy('user_id')
    ->get()
    ->keyBy('user_id');
```

## Impact
- **Branch Heads**: Dashboard now shows only employees assigned to their branch
- **Department Heads**: Dashboard shows only employees in their department(s)
- **Company Heads**: Dashboard shows only employees in their company/companies
- **Real-time Updates**: All dashboard cards (present, late, absent, on leave) now reflect accurate scoped counts
- **Charts**: Weekly and monthly statistics now only include scoped employees

## Testing
To verify the fix:
1. Log in as a branch head with 4 employees
2. Check the dashboard cards - should show max 4 employees in "Total Employees"
3. Verify "Present Today", "Late Today", "Absent Today" counts don't exceed 4
4. Check company attendance distribution - should only show the branch's company
5. Verify all charts (weekly, monthly) only include the 4 employees

## Files Modified
- `backend/app/Http/Controllers/Admin/DashboardController.php`
  - `computeDailyStats()` method
  - `companyAttendanceDistribution()` method
  - `departmentAttendanceDistribution()` method (verified)
