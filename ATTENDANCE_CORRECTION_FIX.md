# Attendance Correction Fix - Approved Corrections Not Showing in Kiosk

## Problem
After approving an attendance correction request (e.g., missing Clock In at 8:00 AM), the corrected entry does not appear in:
- Kiosk recent attendance (`/api/attendance/kiosk/recent`)
- Admin recent attendance view
- Only the later actual Clock In (e.g., 09:48 AM) shows

## Root Cause
The approval process in `PresenceFilingController::approve()` correctly calls `syncApprovedCorrectionToLogs()` which writes to `attendance_logs` table. However, the sync logic has a potential issue with the `issue_kind` parameter that determines which punch types to apply.

## Solution

### File 1: `backend/app/Http/Controllers/PresenceFilingController.php`

**Location:** Line ~520 in the `approve()` method

**Current Code:**
```php
$syncResult = $this->attendanceLogSyncService->syncApprovedCorrectionToLogs(
    $employee,
    $dateKey,
    $correction->time_in,
    $correction->time_out,
    $actor,
    $correction->id,
    $roleLabel,
    $issueKind
);
```

**Issue:** The `$issueKind` variable is normalized from the correction, but if the correction was created by admin (not via presence filing), it might be null or not properly set.

**Fix:** Ensure `issue_kind` is always properly set when approving:

```php
// Before the DB::transaction in approve() method, around line 480
// Normalize issue_kind to ensure sync applies the correct punch types
if (empty($correction->issue_kind)) {
    $issueKind = 'both'; // Default to both if not specified
    if ($correction->time_in && !$correction->time_out) {
        $issueKind = 'missing_in';
    } elseif (!$correction->time_in && $correction->time_out) {
        $issueKind = 'missing_out';
    }
} else {
    $issueKind = $this->normalizeIssueKind($correction);
}
```

### File 2: `backend/app/Services/PresenceFilingAttendanceLogSyncService.php`

**No changes needed** - The sync service is correctly implemented. It:
1. Creates/updates `AttendanceLog` entries with the corrected times
2. Sets `authentication_method = 'hr_approved_correction'`
3. Handles both `missing_in`, `missing_out`, and `both` issue kinds

### File 3: `backend/app/Http/Controllers/AttendanceController.php`

**Location:** `recentKiosk()` method, line ~2800

**Current Code is CORRECT** - It queries `attendance_logs` table which should include the synced corrections:

```php
$logEntries = AttendanceLog::query()
    ->with([...])
    ->orderByDesc('created_at')
    ->limit(40)
    ->get()
```

The issue is that it also includes manual corrections separately, which might cause duplicates or confusion.

**Improvement:** Remove the duplicate manual correction entries since they're already in attendance_logs:

```php
// REMOVE or comment out the manual corrections section (lines ~2850-2920)
// Manual corrections are already synced to attendance_logs, no need to fetch separately
```

## Implementation Steps

1. **Update PresenceFilingController.php** - Add issue_kind normalization before approval
2. **Verify AttendanceLog entries** - Check that approved corrections create logs with `authentication_method = 'hr_approved_correction'`
3. **Test the flow:**
   - Create a correction request for missing Clock In at 8:00 AM
   - Approve it through the workflow
   - Verify the 8:00 AM entry appears in kiosk recent attendance
   - Verify it shows in admin attendance monitoring

## Database Verification Query

```sql
-- Check if approved corrections are synced to attendance_logs
SELECT 
    al.id,
    al.user_id,
    al.type,
    al.created_at,
    al.authentication_method,
    ac.id as correction_id,
    ac.approved,
    ac.attendance_logs_synced_at
FROM attendance_logs al
LEFT JOIN attendance_corrections ac ON ac.user_id = al.user_id 
    AND DATE(ac.date) = DATE(al.created_at)
WHERE al.authentication_method = 'hr_approved_correction'
ORDER BY al.created_at DESC
LIMIT 20;
```

## Expected Behavior After Fix

1. **Approval Process:**
   - Admin approves correction for 8:00 AM Clock In
   - `syncApprovedCorrectionToLogs()` creates/updates `AttendanceLog` entry
   - Entry has `created_at = 2024-XX-XX 08:00:00 UTC` (converted from Manila time)
   - Entry has `authentication_method = 'hr_approved_correction'`

2. **Kiosk Recent Attendance:**
   - Shows 8:00 AM Clock In with employee name
   - Shows correct status (Present/Late based on schedule)
   - Original 09:48 AM entry is either replaced or both show (depending on issue_kind)

3. **Admin Attendance Monitoring:**
   - Shows corrected 8:00 AM time in DTR
   - Reflects in daily computation and payroll

## Additional Notes

- The sync service is idempotent - running it multiple times won't create duplicates
- The `issue_kind` parameter determines which punch types to apply:
  - `missing_in`: Only apply corrected Clock In, keep existing Clock Out
  - `missing_out`: Only apply corrected Clock Out, keep existing Clock In
  - `both`: Apply both corrected times
- If `issue_kind` is null/empty, the service falls back to applying whatever times are provided
