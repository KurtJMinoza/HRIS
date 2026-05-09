# Flexible Pay Component Scheduling Implementation

## Overview
Implemented flexible per-employee pay component scheduling for Earnings and Allowances. Each employee's assigned pay component can now have its own schedule configuration, overriding the global default from Deduction Schedule Settings.

## Database Changes

### Migration: `2026_04_10_100000_add_schedule_override_to_employee_compensation_components.php`
- Added `schedule_override` column (nullable string, 32 chars) to `employee_compensation_components` table
- Stores employee-specific schedule: `null`, `first_run`, `second_run`, `split`, or `monthly`
- `null` or `'default'` = use global schedule from Deduction Schedule Settings

### Model: `EmployeeCompensationComponent`
- Added `schedule_override` to `$fillable` array

## Backend Changes

### DeductionScheduleService

#### Updated `resolveScheduleType()` method
- **New signature**: `resolveScheduleType(string $deductionKey, ?int $companyId, ?int $userId = null, ?int $payComponentId = null): string`
- **Priority logic**:
  1. Check employee-specific `schedule_override` from `employee_compensation_components` table
  2. If override exists and valid → use employee schedule
  3. Otherwise → fallback to global schedule from `deduction_schedule_settings` table
  4. Final fallback → `SCHEDULE_BOTH` (split)

#### Added `normalizeScheduleOverrideToScheduleType()` helper
- Converts `schedule_override` values to `DeductionScheduleSetting` constants:
  - `first_run` → `SCHEDULE_15TH`
  - `second_run` → `SCHEDULE_30TH`
  - `split` → `SCHEDULE_BOTH`
  - `monthly` → `SCHEDULE_BOTH`

#### Updated `summarizeForPayrollComputation()`
- Passes `userId` and `payComponentId` when resolving schedules for earnings and deductions
- Ensures employee-specific overrides are applied during payroll computation

#### Enhanced debug logging
- Added `employee_schedule_override` and `resolved_schedule_type` to `payroll.allowance_proration` logs
- Helps trace which schedule was used (employee override vs default)

### PayrollComputationService

#### Updated `computeEmployeePayroll()`
- Passes `userId` and `payComponentId` when resolving basic salary schedule
- Ensures employee-specific schedule overrides apply to all pay components

### EmployeeCompensationController

#### Updated validation rules
- **assign()**: Added `components.*.schedule_override` validation
  - Allowed values: `'default'`, `'first_run'`, `'second_run'`, `'split'`, `'monthly'`
- **update()**: Added `schedule_override` validation with same rules

#### Updated `storeAssignment()` method
- Handles `schedule_override` from component payload
- Stores `null` when value is `'default'` (uses global schedule)
- Stores override value otherwise

#### Updated `assignmentResponse()` method
- Includes `schedule_override` in API response

## Frontend Changes

### AdminEmployeeCompensationPage.jsx

#### Form state
- Added `schedule_override: 'default'` to `EMPTY_FORM`
- Tracks schedule selection in component assignment dialog

#### UI Components

**Schedule Dropdown** (in Add Component dialog):
```jsx
<Field label="Pay Component Schedule">
  <select value={draftForm.schedule_override} onChange={...}>
    <option value="default">Use Default Schedule</option>
    <option value="first_run">15th only / First run</option>
    <option value="second_run">30th only / Second run</option>
    <option value="split">Split / 15-30</option>
    <option value="monthly">Monthly / Full run</option>
  </select>
</Field>
```

**Schedule Column** (in Compensation table):
- Added "Schedule" column header
- Displays schedule badge with color coding:
  - Default: gray
  - 15th only: blue
  - 30th only: purple
  - Split: green
  - Monthly: amber

#### Helper functions
- `formatScheduleOverride(value)`: Converts schedule value to display label
- `getScheduleBadgeStyles(value)`: Returns Tailwind classes for schedule badge

#### Data flow
- `applyMasterComponent()`: Initializes `schedule_override` to `'default'`
- `addPendingAssignment()`: Includes schedule override in pending changes
- `savePendingAssignments()`: Sends schedule override to API (null if default)

## Schedule Behavior

### Schedule Types

| Override Value | Display Label | Behavior |
|----------------|---------------|----------|
| `null` or `'default'` | Default | Uses global schedule from Deduction Schedule Settings |
| `first_run` | 15th only | Full amount on first payroll run, ₱0 on second run |
| `second_run` | 30th only | ₱0 on first payroll run, full amount on second run |
| `split` | Split | Amount ÷ 2 on both first and second runs |
| `monthly` | Monthly | Full amount when schedule allows (same as split) |

### Examples

**Employee A** (₱2,600 monthly allowance, schedule: `first_run`):
- First run (15th): ₱2,600
- Second run (30th): ₱0

**Employee B** (₱2,600 monthly allowance, schedule: `second_run`):
- First run (15th): ₱0
- Second run (30th): ₱2,600

**Employee C** (₱2,600 monthly allowance, schedule: `split`):
- First run (15th): ₱1,300
- Second run (30th): ₱1,300

**Employee D** (schedule: `default`):
- Uses global schedule from Deduction Schedule Settings
- If global is "Both" → split behavior
- If global is "15th" → first run only
- If global is "30th" → second run only

## Payroll Computation Flow

1. Get employee assigned pay component
2. Get component amount/value
3. **Check employee-specific `schedule_override`**
4. Get default schedule from Deduction Schedule Settings
5. **Resolve final schedule** (employee override takes priority)
6. Determine current payroll run type (first/second)
7. Compute attendance-based prorated amount (if applicable)
8. **Apply schedule adjustment**:
   - `first_run`: full amount × 1.0 on first run, × 0.0 on second
   - `second_run`: full amount × 0.0 on first run, × 1.0 on second
   - `split`: full amount × 0.5 on both runs
   - `monthly`: full amount × 0.5 on both runs (same as split)
9. Return final amount
10. Consistent across preview, generation, payslip, finalize, reports

## Debug Logs

Log key: `payroll.allowance_proration`

New fields:
- `employee_schedule_override`: Raw override value from database
- `resolved_schedule_type`: Final schedule after priority resolution
- `schedule_configuration`: Schedule type used for computation

Example log:
```json
{
  "employee_id": 123,
  "pay_component_id": 45,
  "code": "ALLOWANCE",
  "name": "Transportation Allowance",
  "employee_schedule_override": "first_run",
  "resolved_schedule_type": "15th",
  "schedule_configuration": "15th",
  "configured_monthly_amount": 2600.00,
  "schedule_factor": 1.0,
  "is_applicable_in_run": true,
  "final_allowance_after_schedule_adjustment": 2600.00
}
```

## Testing Checklist

- [ ] Run migration: `php artisan migrate`
- [ ] Assign pay component with default schedule → uses global schedule
- [ ] Assign pay component with "15th only" → appears only on first run
- [ ] Assign pay component with "30th only" → appears only on second run
- [ ] Assign pay component with "Split" → divided between both runs
- [ ] Verify payroll preview shows correct amounts
- [ ] Verify payslip shows correct amounts
- [ ] Verify finalized payroll matches preview
- [ ] Check debug logs for schedule resolution
- [ ] Test with attendance-prorated allowances
- [ ] Test with multiple employees having different schedules
- [ ] Verify UI displays schedule badges correctly
- [ ] Test editing existing component schedule

## Migration Path

1. Run database migration
2. Existing components have `schedule_override = null` → use default schedule (no behavior change)
3. HR can now assign employee-specific schedules via UI
4. Global Deduction Schedule Settings remain as default/fallback

## Notes

- Global Deduction Schedule Settings is NOT removed
- It serves as the default when `schedule_override` is `null` or `'default'`
- System supports both company-wide defaults and employee-specific overrides
- Schedule resolution is centralized in `DeductionScheduleService::resolveScheduleType()`
- All payroll paths (preview, generate, finalize, reports) use the same resolution logic
