# Holiday Pay Policy

Holiday Pay Policy is part of the existing versioned `Policy` record and is edited only through Admin → Payroll → Policy Settings. There is no separate Holiday Policy module.

`HolidayPayEvaluationService` is the payroll-facing decision layer. It evaluates every covered holiday in the payroll period even when no attendance row exists. `HolidayPayPolicyService` resolves attendance and leave qualification, while `PayrollComputationService` remains the earnings source of truth and reads the existing `policy_multipliers` rows for RH, RHRD, SH, SHRD, DH, and DHRD.

## Consumers

- Payroll daily computation
- Admin attendance rows and persisted attendance summaries
- Employee attendance calendar/dashboard
- Holiday module policy snapshot
- Holiday report endpoint
- Leave range validation context

## Rules

- Regular holiday: 100% when unworked and eligible; 200% when worked; 260% when worked on a rest day.
- Special non-working holiday: no work, no pay by default; 130% when worked; 150% when worked on a rest day.
- Overtime on a holiday uses the applicable holiday/rest-day rate plus 30%.
- Night differential adds at least 10% on the applicable hourly rate.
- Company multipliers are clamped to statutory minimums and may only be more favorable.
- The immediately preceding working day accepts attendance or approved paid leave. Rest/non-working days are skipped.
- Successive regular holidays inherit the condition before the first holiday; working the first restores eligibility for the next.
- When a special non-working holiday immediately precedes a regular holiday, working the special holiday qualifies unworked pay on the regular holiday; an unworked special holiday blocks inheritance from an earlier regular holiday.
- Policies may apply to all current employment types or a selected set. `EmploymentTypeResolver` derives the same normalized type for the UI and payroll evaluation from current HRIS employee data.
- Holiday coverage configured in the Holiday module remains authoritative.
- Eligible unworked lines use `REGULAR_HOLIDAY_UNWORKED_PAY` and `SPECIAL_HOLIDAY_UNWORKED_PAY` and are generated without requiring a holiday attendance log.

## API

Holiday settings are returned and saved in the `holiday_policy` property of the existing policy endpoints:

- `GET /api/admin/payroll/policies/{id}`
- `POST /api/admin/payroll/policies`
- `PUT /api/admin/payroll/policies/{id}`
- `GET /api/admin/payroll/policies/employment-types`

## Cache

Resolved company policy settings use a 30-minute TTL:

- `policy:holiday:{company_id}`

Policy saves and holiday changes invalidate the holiday-policy cache together with relevant attendance, dashboard, report, and payroll caches.
