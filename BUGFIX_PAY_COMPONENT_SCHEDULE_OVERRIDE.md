# Bug Fix: Pay Component Schedule Override

## Issue Summary
In Employee Compensation > Add Component modal, when selecting a Pay Component Schedule (15th, Split 15/30, or End of month), the payroll computation works correctly. However, in Admin > Edit Employee Profile > Salary & Contributions tab > Pay Components & Contributions, the same component displays the wrong schedule—it shows the global/default schedule from Deduction Schedule Settings instead of the employee-specific pay component schedule override.

## Root Cause
The `attachPayScheduleTypes` method in `PayrollCalculatorService.php` was calling `resolveScheduleType` without passing the `userId` and `payComponentId` parameters. This caused the method to always fall back to the global/default schedule instead of checking for employee-specific overrides.

## Solution

### Backend Changes

#### 1. PayrollCalculatorService.php
**File:** `backend/app/Services/PayrollCalculatorService.php`

**Method:** `attachPayScheduleTypes`

**Change:** Updated the method to pass `userId` and `payComponentId` to `resolveScheduleType` so it can check for employee-specific schedule overrides.

```php
private function attachPayScheduleTypes(User $user, array $lines): array
{
    $companyId = $user->getEffectiveCompanyId();
    $userId = (int) $user->id;
    $svc = app(DeductionScheduleService::class);

    return array_map(function (array $line) use ($svc, $companyId, $userId) {
        $pcId = $line['pay_component_id'] ?? null;
        $line['pay_schedule_type'] = $pcId
            ? $svc->resolveScheduleType('pay_component:'.((int) $pcId), $companyId, $userId, (int) $pcId)
            : null;

        return $line;
    }, $lines);
}
```

**Impact:** This ensures that when building the employee compensation summary (used by Admin Edit Employee Profile), the system correctly prioritizes:
1. Employee-specific pay component schedule override
2. Default/global schedule from Deduction Schedule Settings
3. System fallback/default

### Frontend Changes

#### 2. AdminDeductionScheduleSettingsPage.jsx
**File:** `frontend/src/pages/AdminDeductionScheduleSettingsPage.jsx`

**Change:** Updated the modal description text to clarify that employees may override the default schedule.

**Before:**
```jsx
Choose the default schedule for this {activeRow.type === 'Earning' ? 'earning/allowance' : 'deduction'}. Employees can still use their own schedule override in Employee Compensation.
```

**After:**
```jsx
Choose the default schedule for this {activeRow.type === 'Earning' ? 'earning/allowance' : 'deduction'}. Employees may override this schedule in Employee Compensation.
```

## Expected Behavior After Fix

### Employee Compensation > Pay Component Schedule = 15th
- ✅ Admin Edit Employee Profile displays: **15th**
- ✅ Payroll uses: **15th**
- ✅ Payslip reflects: **15th logic**

### Employee Compensation > Pay Component Schedule = Split 15/30
- ✅ Admin Edit Employee Profile displays: **Split 15/30**
- ✅ Payroll uses: **split logic**
- ✅ Payslip divides the allowance correctly

### Employee Compensation > Pay Component Schedule = End of month
- ✅ Admin Edit Employee Profile displays: **End of month**
- ✅ Payroll uses: **end-of-month logic**

### Employee Compensation > Pay Component Schedule = Use default
- ✅ Admin Edit Employee Profile displays: **the default schedule from Deduction Schedule Settings**
- ✅ Optional label: "Use default: Split 15/30"

## Affected Modules
- ✅ Employee Compensation > Add Component modal
- ✅ Employee Compensation > Pay Components list/table
- ✅ Admin > Edit Employee Profile > Salary & Contributions tab
- ✅ Admin > Pay Components & Contributions table
- ✅ Deduction Schedule Settings > Earnings & Allowances tab
- ✅ Payroll preview
- ✅ Payroll generation
- ✅ Payslip
- ✅ Finalized payroll
- ✅ Payroll reports

## Testing Recommendations

1. **Create a test employee** with a pay component that has a schedule override
2. **Set Employee Compensation schedule** to "15th" for a specific allowance
3. **Verify in Admin Edit Employee Profile** that the schedule shows "15th" (not the global default)
4. **Run payroll preview** and verify the allowance is applied on the 15th run only
5. **Generate payslip** and verify the schedule is correctly reflected
6. **Test all three schedule options**: 15th, Split 15/30, End of month
7. **Test "Use default"** option and verify it falls back to Deduction Schedule Settings

## Database Schema
The fix leverages the existing `schedule_override` column in the `employee_compensation_components` table (added by migration `2026_04_10_100000_add_schedule_override_to_employee_compensation_components.php`).

**Column:** `schedule_override` (nullable string, max 32 chars)

**Valid values:**
- `first_run` → maps to "15th"
- `second_run` → maps to "End of month"
- `split` → maps to "Split 15/30"
- `monthly` → maps to "Split 15/30"
- `null` or `'default'` → uses global default from Deduction Schedule Settings

## Priority Logic
The `resolveScheduleType` method in `DeductionScheduleService.php` already implements the correct priority:

1. **Employee-specific override** (from `employee_compensation_components.schedule_override`)
2. **Global/default schedule** (from `deduction_schedule_settings` table)
3. **System fallback** (defaults to "both" / Split 15/30)

The bug was that `attachPayScheduleTypes` wasn't passing the employee context, so the override check was never executed.

## Conclusion
This fix ensures that employee-specific pay component schedule overrides are correctly displayed and used throughout the system, while maintaining the global default schedule as a fallback for employees without custom overrides.
