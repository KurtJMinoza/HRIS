<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Employment Status Configuration
    |--------------------------------------------------------------------------
    | Configurable defaults for status options and regularization automation.
    | Admin-level runtime overrides are stored in employment_status_settings.
    */
    'statuses' => [
        'probationary',
        'regular',
        'contractual',
        'project_based',
        'separated',
    ],

    'regularization' => [
        'auto_months' => (int) env('EMPLOYMENT_AUTO_REGULARIZATION_MONTHS', 6),
        'early_months' => (int) env('EMPLOYMENT_EARLY_REGULARIZATION_MONTHS', 3),
        'dashboard_green_window_days' => (int) env('EMPLOYMENT_DASHBOARD_GREEN_WINDOW_DAYS', 30),
    ],
];
