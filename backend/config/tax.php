<?php

/**
 * Philippine withholding & income tax defaults (TRAIN / RA 10963).
 * Replace rates via `tax_tables` when BIR issues updates; this file holds safe defaults & MWE hints.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Wage Earner (MWE) — simplified ceiling
    |--------------------------------------------------------------------------
    |
    | When `is_mwe` is set on the employee tax profile and monthly taxable compensation
    | does not exceed this amount, withholding is treated as ₱0 (full exemption path).
    | Set to null to require an explicit `mwe_monthly_ceiling` on each employee instead.
    | Actual MW varies by region — HR must align with DOLE/BIR rules.
    |
    */
    'mwe_default_monthly_ceiling' => env('TAX_MWE_DEFAULT_MONTHLY_CEILING'),

    /*
    |--------------------------------------------------------------------------
    | 13th month & similar benefits
    |--------------------------------------------------------------------------
    */
    'thirteenth_month_exempt_annual' => 90000.0,

    /*
    |--------------------------------------------------------------------------
    | Company default withholding (fallback when employee has no tax profile)
    |--------------------------------------------------------------------------
    */
    'company_default_withholding_method' => env('TAX_DEFAULT_WITHHOLDING_METHOD', 'annualized'),
    'company_default_period_type' => env('TAX_DEFAULT_PERIOD_TYPE', 'monthly'),

];
