<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Timezone for attendance (clock-in/out times and schedule comparison).
    | Clock-in times from the DB (stored in UTC) are converted to this zone so
    | 1:00 PM local = Half Day. Set APP_TIMEZONE or ATTENDANCE_TIMEZONE in .env
    | (e.g. Asia/Manila). Default Asia/Manila so 1:33 PM = Half Day, not Late.
    |-----------------------------------------------------------------d--------
    */
    'timezone' => env('ATTENDANCE_TIMEZONE', env('APP_TIMEZONE', 'Asia/Manila')),

    /*
    |--------------------------------------------------------------------------
    | Grace period: minutes after scheduled start still counted as On Time.
    | E.g. shift 8:00 AM, grace 5 → 8:00–8:05 On Time; 8:06+ late (per buckets).
    | Overridden by each WorkingSchedule's grace_period_minutes when assigned.
    |--------------------------------------------------------------------------
    */
    'grace_period_minutes' => (int) env('ATTENDANCE_GRACE_PERIOD_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Earliest clock-in: how many minutes before scheduled start is clock-in allowed.
    | E.g. 60 with 8:00 AM start → clock-in allowed from 7:00 AM. Early time-in
    | is allowed; status (Present/Late) still uses schedule + grace (8:00–8:05 On Time).
    |--------------------------------------------------------------------------
    */
    'earliest_clockin_before_minutes' => (int) env('ATTENDANCE_EARLIEST_CLOCKIN_BEFORE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Half day: first clock-in at or after this hour (24h) is marked Half Day.
    | 12 = 12:00 PM (noon). Login between 12:00 PM and 1:00 PM (and after) = Half-Day.
    |--------------------------------------------------------------------------
    */
    'half_day_start_hour' => (int) env('ATTENDANCE_HALF_DAY_START_HOUR', 12),

    /*
    |--------------------------------------------------------------------------
    | Absent cutoff: user is marked Absent (today) only if not present by this time.
    | 17 = 5:00 PM. Use 24-hour format. After this time, no clock-in = absent.
    |--------------------------------------------------------------------------
    */
    'absent_cutoff_hour' => (int) env('ATTENDANCE_ABSENT_CUTOFF_HOUR', 17),
    'absent_cutoff_minute' => (int) env('ATTENDANCE_ABSENT_CUTOFF_MINUTE', 0),

    /*
    |--------------------------------------------------------------------------
    | Late threshold: minutes after scheduled start to count as late.
    | (Legacy; late is now computed via grace + 30-min buckets.)
    |--------------------------------------------------------------------------
    */
    'late_threshold_minutes' => (int) env('ATTENDANCE_LATE_THRESHOLD_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Under-time threshold: worked minutes less than required to count as under time.
    |--------------------------------------------------------------------------
    */
    'undertime_threshold_minutes' => (int) env('ATTENDANCE_UNDERTIME_THRESHOLD_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Overtime buffer: minutes after scheduled end before OT is counted.
    | E.g. 15 with 5:00 PM end → 5:00–5:15 valid time-out (no OT); after 5:15 OT starts.
    | Overridden by each WorkingSchedule's overtime_buffer_minutes when assigned.
    |--------------------------------------------------------------------------
    */
    'overtime_buffer_minutes' => (int) env('ATTENDANCE_OVERTIME_BUFFER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Face verification cooldown (after N failed attempts)
    |--------------------------------------------------------------------------
    */
    'face_cooldown_attempts' => (int) env('ATTENDANCE_FACE_COOLDOWN_ATTEMPTS', 3),
    'face_cooldown_seconds' => (int) env('ATTENDANCE_FACE_COOLDOWN_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Geo validation (optional). Set to enable radius check.
    |--------------------------------------------------------------------------
    */
    'geo_enabled' => (bool) env('ATTENDANCE_GEO_ENABLED', false),
    'office_latitude' => (float) env('ATTENDANCE_OFFICE_LAT', 0),
    'office_longitude' => (float) env('ATTENDANCE_OFFICE_LNG', 0),
    'office_radius_meters' => (float) env('ATTENDANCE_OFFICE_RADIUS_METERS', 100),

    /*
    |--------------------------------------------------------------------------
    | Max face descriptor samples per employee (averaged for matching).
    |--------------------------------------------------------------------------
    */
    'face_samples_max' => (int) env('ATTENDANCE_FACE_SAMPLES_MAX', 10),

    /*
    |--------------------------------------------------------------------------
    | Holidays (optional)
    |--------------------------------------------------------------------------
    | Used by leave validation (e.g. undertime leave cannot be filed on holidays).
    | Provide a list of YYYY-MM-DD strings via env or hardcode here.
    |
    | Example:
    | 'holidays' => ['2026-01-01', '2026-04-09'],
    */
    'holidays' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATTENDANCE_HOLIDAYS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Policy toggles
    |--------------------------------------------------------------------------
    | Whether undertime leave is allowed on rest days / holidays.
    */
    'allow_undertime_on_rest_day' => (bool) env('ALLOW_UNDERTIME_ON_REST_DAY', false),
    'allow_undertime_on_holiday' => (bool) env('ALLOW_UNDERTIME_ON_HOLIDAY', false),
];
