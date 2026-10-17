<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HR welcome letter notifications (in-app)
    |--------------------------------------------------------------------------
    | Matched employees receive one idempotent notification; opening it shows
    | the letter modal keyed by letter_key in notification data.
    */
    'letters' => [
        'coo_rocelyn_cortez' => [
            'match' => [
                'last_name' => 'Cortez',
                'first_name' => 'Rocelyn',
            ],
            'notification' => [
                'type' => 'welcome.coo_letter',
                'title' => 'A Warm Welcome to Our New Chief Operating Officer',
                'message' => 'Human Resources prepared a welcome letter for you. Open this notification to read it.',
                'module' => 'dashboard',
                'priority' => 'high',
                'data' => [
                    'letter_key' => 'coo_rocelyn_cortez',
                ],
            ],
        ],
    ],
];
