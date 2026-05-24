<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Philippines Labor Code — company rules engine (OT, ND, holidays, rest day)
    |--------------------------------------------------------------------------
    | Policy manual alignment: ordinary OT 1.25×; rest/special 1.30× / OT 1.69×; RH 2.00× / OT 2.60×;
    | RH+rest 2.60× / OT 3.38×; SH+rest 1.50× / OT 1.95×; ND +10% on applicable HWR (10PM–6AM).
    |
    | Implementing modules (same factors everywhere):
    | - PayrollRulesEngineService (rule codes), TimeSegmentationService (8h / OT / ND),
    |   PayrollComputationService (pay), PremiumReportService (reports),
    |   EmployeeOvertimeController (ph_ot_rule), Admin HolidayController (calendar + matrix).
    |
    | Canonical doc: backend/docs/PAYROLL_RULES_ENGINE.md
    | Admin JSON: GET /api/admin/payroll/policy-reference
    |
    | All values below are multipliers on hourly/daily rate unless noted.
    */

    'timezone' => env('PAYROLL_TIMEZONE', config('attendance.timezone', 'Asia/Manila')),

    /*
    |--------------------------------------------------------------------------
    | Daily computation audit (Admin UI)
    |--------------------------------------------------------------------------
    | Flag EXCESSIVE_OT when actual OT hours from time logs meets or exceeds this.
    */
    'audit_excessive_ot_hours' => (float) env('PAYROLL_AUDIT_EXCESSIVE_OT_HOURS', 8),

    /*
    |--------------------------------------------------------------------------
    | Night Differential (ND) Window
    |--------------------------------------------------------------------------
    | Work between 10:00 PM and 6:00 AM earns +10% premium.
    | Times are in 24-hour format (22 = 10PM, 6 = 6AM).
    */
    'night_differential' => [
        'start_hour' => (int) env('PAYROLL_ND_START_HOUR', 22), // 10:00 PM
        'end_hour' => (int) env('PAYROLL_ND_END_HOUR', 6),      // 6:00 AM (next day)
        'premium_multiplier' => (float) env('PAYROLL_ND_MULTIPLIER', 0.10), // +10%
    ],

    /*
    |--------------------------------------------------------------------------
    | Regular Workday Multipliers
    |--------------------------------------------------------------------------
    */
    'multipliers' => [
        // Regular workday overtime (beyond 8 hours): 125%
        'regular_overtime' => (float) env('PAYROLL_REGULAR_OT', 1.25),

        // Rest day work (first 8 hours): 130%
        'rest_day' => (float) env('PAYROLL_REST_DAY', 1.30),

        // Rest day overtime (beyond 8 hours): 130% × 130% = 169%
        'rest_day_overtime' => (float) env('PAYROLL_REST_DAY_OT', 1.69),

        // Special non-working day work: 130%
        'special_holiday' => (float) env('PAYROLL_SPECIAL_HOLIDAY', 1.30),

        // Special holiday overtime: 130% × 130% = 169%
        'special_holiday_overtime' => (float) env('PAYROLL_SPECIAL_HOLIDAY_OT', 1.69),

        // Regular holiday work (first 8 hours): 200%
        'regular_holiday' => (float) env('PAYROLL_REGULAR_HOLIDAY', 2.0),

        // Regular holiday overtime (beyond 8 hours): 200% × 130% = 260%
        'regular_holiday_overtime' => (float) env('PAYROLL_REGULAR_HOLIDAY_OT', 2.60),

        // Regular holiday on rest day (first 8 hours): 260%
        'regular_holiday_rest_day' => (float) env('PAYROLL_REGULAR_HOLIDAY_REST', 2.60),

        // Regular holiday on rest day overtime: 260% × 130% = 338%
        'regular_holiday_rest_day_overtime' => (float) env('PAYROLL_REGULAR_HOLIDAY_REST_OT', 3.38),

        // Double holiday work: 300%
        'double_holiday' => (float) env('PAYROLL_DOUBLE_HOLIDAY', 3.0),

        // Double holiday overtime: 300% × 130% = 390%
        'double_holiday_overtime' => (float) env('PAYROLL_DOUBLE_HOLIDAY_OT', 3.90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Regular Hours Threshold
    |--------------------------------------------------------------------------
    | Hours up to this count = regular; beyond = overtime. (Philippines: 8)
    */
    'regular_hours_threshold' => (int) env('PAYROLL_REGULAR_HOURS_THRESHOLD', 8),

    /*
    |--------------------------------------------------------------------------
    | Overtime — how "rendered OT" minutes are derived from time logs
    |--------------------------------------------------------------------------
    | schedule_end: OT = net work minutes at/after scheduled shift end (Labor Code: work beyond schedule;
    |   remaining net minutes before end are paid at the day rate, not as OT-by-8h).
    | eight_hour_net: legacy — OT = max(0, net_worked_minutes − 8h) (misleading vs schedule; avoid for new installs).
    */
    'ot_basis' => env('PAYROLL_OT_BASIS', 'schedule_end'),

    /*
    |--------------------------------------------------------------------------
    | Overtime payable basis (approved OT requests vs attendance-rendered OT)
    |--------------------------------------------------------------------------
    | approved — pay approved OT hours from the Overtime module (recommended).
    | rendered — pay only attendance-rendered OT minutes (legacy cap).
    | min — pay min(approved hours, rendered OT minutes).
    */
    'ot_payable_basis' => env('PAYROLL_OT_PAYABLE_BASIS', 'approved'),

    /*
    |--------------------------------------------------------------------------
    | Rules Table (Phase 2) – Config-driven multipliers
    |--------------------------------------------------------------------------
    | Aligns with PH Labor Code (Arts. 87, 93, 94) / DOLE Omnibus Rules: ordinary OT 1.25×;
    | rest day & special holiday 1.30× / OT 1.69×; regular holiday 2.00× / OT 2.60×;
    | RH+rest 2.60× / OT 3.38×; special+rest 1.50× / OT 1.95×; double holidays DH/DHRD when calendar has type "double".
    | nd_base = multiplier applied to HWR for ND on regular-segment night minutes (+10% on that product); OT night uses ot×.
    |
    | Engine Decision Logic:
    |   IF holiday=regular AND rest_day=true → RHRD
    |   ELSE IF holiday=regular → RH
    |   ELSE IF holiday=special AND rest_day=true → SHRD
    |   ELSE IF holiday=special → SH
    |   ELSE IF rest_day=true → RD
    |   ELSE → ORD
    */
    'rules' => [
        'ORD' => [
            'condition' => 'Normal',
            'first_8' => 1.00,
            'ot' => 1.25,
            'nd_base' => 1.00,
        ],
        'RD' => [
            'condition' => 'Rest Day',
            'first_8' => 1.30,
            'ot' => 1.69,
            'nd_base' => 1.30,
        ],
        'RH' => [
            'condition' => 'Regular Holiday',
            'first_8' => 2.00,
            'ot' => 2.60,
            'nd_base' => 2.00,
        ],
        'RHRD' => [
            'condition' => 'Holiday + Rest Day',
            'first_8' => 2.60,
            'ot' => 3.38,
            'nd_base' => 2.60,
        ],
        'SH' => [
            'condition' => 'Special Holiday',
            'first_8' => 1.30,
            'ot' => 1.69,
            'nd_base' => 1.30,
        ],
        'SHRD' => [
            'condition' => 'Special Holiday + Rest Day',
            'first_8' => 1.50,
            'ot' => 1.95,
            'nd_base' => 1.50,
        ],
        'DH' => [
            'condition' => 'Double Holiday',
            'first_8' => 3.00,
            'ot' => 3.90,
            'nd_base' => 3.00,
        ],
        'DHRD' => [
            'condition' => 'Double Holiday + Rest Day',
            'first_8' => 3.00,
            'ot' => 3.90,
            'nd_base' => 3.00,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ND Premium (Phase 3)
    |--------------------------------------------------------------------------
    | Night differential adds this percentage on top of base rate. (Philippines: 10%)
    */
    'nd_premium' => (float) env('PAYROLL_ND_PREMIUM', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Holiday Type Mapping
    |--------------------------------------------------------------------------
    | Maps database holiday.type values to payroll logic.
    | - regular: Regular holiday (e.g. Independence Day)
    | - special / special_non_working: Special non-working holiday (SNW)
    | - special_working: Worked: same premiums as SNW (SH). Absent (no punches / no approved correction): no pay (engine default).
    | - double: Double holiday (two regular holidays on same date)
    | - company: Company-specific (treated as special unless configured otherwise)
    */
    'holiday_types' => [
        'regular' => 'regular',
        'special' => 'special',
        'special_non_working' => 'special',
        'special_working' => 'special',
        'double' => 'double',
        'company' => 'special',
    ],

    /*
    |--------------------------------------------------------------------------
    | Working Days for Monthly Rate Conversion (fallback only)
    |--------------------------------------------------------------------------
    | ScheduleRateService derives divisors from the assigned Admin schedule (calendar
    | month workdays minus holidays, or annualized weekdays). This value is used only
    | when no schedule can be resolved and annualized days are zero.
    */
    'working_days_per_month' => (int) env('PAYROLL_WORKING_DAYS_MONTH', 22),

    /*
    |--------------------------------------------------------------------------
    | Phase 3 — Advanced deduction compliance controls
    |--------------------------------------------------------------------------
    | Priority is enforced in DeductionApplicationService:
    | 1) Statutory 2) WHT 3) Loans/advances 4) Benefits 5) Other authorized.
    | Non-statutory rows are reduced/blocked if minimum-wage protection would be breached.
    */
    'phase3' => [
        // Conservative baseline; set per deployment/region as needed.
        'minimum_daily_wage' => (float) env('PAYROLL_MINIMUM_DAILY_WAGE', 645),
        // Court-ordered garnishments cap (portion of disposable income).
        'garnishment_max_disposable_ratio' => (float) env('PAYROLL_GARNISHMENT_MAX_RATIO', 0.25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Orphan lock reconcile (no payroll_period_locks table)
    |--------------------------------------------------------------------------
    | Locks are finalized payslips + payroll_periods.status=locked. If batch runs are
    | deleted without clearing those rows, mutations are blocked. When true, lock checks
    | demote finalized payslips / unlock periods when no matching finalized batch exists.
    | Set false in production if you prefer manual repair only (see admin unlock-period).
    */
    'auto_reconcile_orphan_locks' => env('PAYROLL_AUTO_RECONCILE_ORPHAN_LOCKS', true),
];
