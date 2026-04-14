<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default rest day when no schedule is assigned
    |--------------------------------------------------------------------------
    |
    | If an employee has no JSON schedule and no WorkingSchedule assignment, leave
    | validation and credit billing assume a standard week: every day except this
    | key is a working day. Key must be one of: sun, mon, tue, wed, thu, fri, sat.
    |
    */
    'default_rest_day_key' => strtolower((string) env('LEAVE_DEFAULT_REST_DAY', 'sun')),

    /*
    | Shown in APIs when the default template above is used (no HR schedule on file).
    |
    */
    'schedule_missing_warning' => env(
        'LEAVE_SCHEDULE_MISSING_WARNING',
        'No work schedule is assigned yet. Rest days are assumed using the company default (typically Sunday) until HR assigns your schedule.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Annual leave credit allocation
    |--------------------------------------------------------------------------
    |
    | Default paid-leave credits per calendar year for employees who are Regular and have at least
    | one year of service (hire-date based). Probationary employees have 0 pool credits; leave may
    | still be filed as unpaid. January 1 resets eligible balances to this value (unused credits do
    | not carry over). HR manual adjustments may change users.leave_credits when eligible.
    |
    */
    'annual_allocation' => (int) env('LEAVE_ANNUAL_CREDITS', 7),

    /*
    | When true, approving a contract renewal (non-probation regularization path) resets leave credits
    | to the annual allocation. Default false: only January 1 and eligibility rules apply.
    */
    'reset_on_contract_renewal' => (bool) env('LEAVE_RESET_ON_CONTRACT_RENEWAL', false),

];
