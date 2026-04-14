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
    | When true, payroll paid-regular minutes are bumped to full scheduled net required
    | minutes if: status is Present (within grace), clock-in is after scheduled start but
    | within grace, no undertime, and segmented regular is within grace slack of required.
    | Prevents 8:01 → 7.98h paid regular when policy treats 8:00–8:05 as full day pay.
    | Set false to pay strictly on segmented minutes during grace.
    |--------------------------------------------------------------------------
    */
    'grace_period_full_regular_pay' => filter_var(
        env('ATTENDANCE_GRACE_PERIOD_FULL_REGULAR_PAY', true),
        FILTER_VALIDATE_BOOL
    ),

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
    | Half day: first clock-in in [half_day_start_hour, half_day_end_hour) is Half Day
    | (e.g. start 12, end 13 → 12:00 PM–12:59 PM). At or after end hour, normal tardiness bands apply.
    |--------------------------------------------------------------------------
    */
    'half_day_start_hour' => (int) env('ATTENDANCE_HALF_DAY_START_HOUR', 12),

    /*
    |--------------------------------------------------------------------------
    | Exclusive end of half-day clock-in window (24h clock). 13 = 1:00 PM (13:00 not included).
    |--------------------------------------------------------------------------
    */
    'half_day_end_hour' => (int) env('ATTENDANCE_HALF_DAY_END_HOUR', 13),
    'half_day_end_minute' => (int) env('ATTENDANCE_HALF_DAY_END_MINUTE', 0),

    /*
    |--------------------------------------------------------------------------
    | Paid regular minutes cap when status is Half Day (default 4h).
    |--------------------------------------------------------------------------
    */
    'half_day_regular_minutes' => (int) env('ATTENDANCE_HALF_DAY_REGULAR_MINUTES', 240),

    /*
    |--------------------------------------------------------------------------
    | Half day (late threshold): if late by this many minutes or more, mark Half Day.
    | 240 = 4 hours. E.g. clock-in at 1:47 PM (5h 47m late) on 8:00 shift => Half Day.
    | Works alongside half_day_start_hour so extremely late clock-ins always get badge.
    |--------------------------------------------------------------------------
    */
    'half_day_late_minutes_threshold' => (int) env('ATTENDANCE_HALF_DAY_LATE_MINUTES_THRESHOLD', 240),

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
    | Latest clock-out after scheduled end (generic cap)
    |--------------------------------------------------------------------------
    | Without approved OT, clock-out is rejected this many minutes after shift end.
    | Approve OT extends this using expected_end_time (see below).
    |--------------------------------------------------------------------------
    */
    'latest_clockout_after_minutes' => (int) env('ATTENDANCE_LATEST_CLOCKOUT_AFTER_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Late overtime filing window (calendar days after the OT date)
    |--------------------------------------------------------------------------
    | Past-date OT is allowed only if clock-in and clock-out exist for that date
    | and the request is filed within this many days (diff from OT date to today).
    |--------------------------------------------------------------------------
    */
    'overtime_filing_window_days' => (int) env('OVERTIME_FILING_WINDOW_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Extra minutes after approved OT expected end to allow final clock-out
    |--------------------------------------------------------------------------
    */
    'overtime_approved_clockout_grace_minutes' => (int) env('OVERTIME_APPROVED_CLOCKOUT_GRACE_MINUTES', 30),

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
    | Face Liveness: Amazon Rekognition Face Liveness (Amplify UI FaceLivenessDetector)
    |--------------------------------------------------------------------------
    | Frontend uses Amplify FaceLivenessDetector with session from Laravel; backend
    | calls GetFaceLivenessSessionResults and uses reference image for embedding/match.
    | No local anti-spoof (MiniFASNet) – Rekognition is the official liveness engine.
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Minimum liveness confidence to allow clock-in/clock-out (PASS threshold).
    | Rekognition returns 0–100; we allow when confidence >= this * 100.
    | Default 0.52 ≈ 52% — balances spoof resistance vs false rejects (raise to 0.65+ if needed).
    | Backend validates and returns PASS/FAIL; React never calls Rekognition directly.
    |--------------------------------------------------------------------------
    */
    'face_min_liveness_score' => (float) env('ATTENDANCE_FACE_MIN_LIVENESS_SCORE', 0.52),

    /*
    |--------------------------------------------------------------------------
    | Stricter liveness for face *registration* (Amplify + Rekognition)
    |--------------------------------------------------------------------------
    | Applied only when enrolling a new face template. Higher = fewer spoofs, more retakes.
    |--------------------------------------------------------------------------
    */
    'face_registration_min_liveness_score' => (float) env('ATTENDANCE_FACE_REGISTRATION_MIN_LIVENESS_SCORE', 0.62),

    /*
    |--------------------------------------------------------------------------
    | Face match threshold (Euclidean distance). Login only allowed if similarity
    | passes: distance <= threshold. Facenet same-person distance often 0.5–1.0.
    | 0.45 = very strict (many false rejections), 0.75 = balanced, 0.9–1.0 = lenient.
    | Use 0.9 default to reduce false rejections for registered users.
    |--------------------------------------------------------------------------
    */
    // Euclidean mode only (when cosine threshold is disabled). Higher = fewer false rejects at kiosk.
    'face_match_threshold' => (float) env('ATTENDANCE_FACE_MATCH_THRESHOLD', 0.78),

    /*
    |--------------------------------------------------------------------------
    | Face similarity threshold (cosine distance 0–1). Alternative to Euclidean.
    | Match when cosine_distance <= threshold. E.g. 0.6–0.8 = similarity 0.2–0.4.
    | Set to null to use face_match_threshold (Euclidean) only.
    |--------------------------------------------------------------------------
    */
    // Cosine distance threshold (0–1). When non-null, cosine distance is used instead of Euclidean.
    // Larger = more tolerant (fewer false rejects). ~0.35 ≈ similarity ≥ 0.65 for a pass when paired with min_similarity.
    'face_cosine_distance_threshold' => (float) env('ATTENDANCE_FACE_COSINE_DISTANCE_THRESHOLD', 0.35),

    /*
    |--------------------------------------------------------------------------
    | Face matching safety checks (anti-misidentification)
    |--------------------------------------------------------------------------
    | Require both:
    | - minimum cosine similarity (0–1)
    | - a margin vs the second-best match (prevents "close call" misidentifications)
    */
    'face_min_similarity_score' => (float) env('ATTENDANCE_FACE_MIN_SIMILARITY_SCORE', 0.65),
    'face_min_similarity_margin' => (float) env('ATTENDANCE_FACE_MIN_SIMILARITY_MARGIN', 0.04),

    /*
    |--------------------------------------------------------------------------
    | Identification debug logging (kiosk / face login)
    |--------------------------------------------------------------------------
    | On a failed match, log best near-miss cosine similarity and thresholds (no PII by default).
    | Set ATTENDANCE_FACE_LOG_ID_USER_IDS=true to include nearest user id for support debugging.
    |--------------------------------------------------------------------------
    */
    'face_log_identification_misses' => filter_var(
        env('ATTENDANCE_FACE_LOG_ID_MISSES', true),
        FILTER_VALIDATE_BOOL
    ),
    'face_log_identification_user_ids' => filter_var(
        env('ATTENDANCE_FACE_LOG_ID_USER_IDS', false),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Face duplicate detection (registration only, cross-account)
    |--------------------------------------------------------------------------
    | Compared only against *other* employees' stored samples + primary (+ optional mean row).
    | A duplicate is declared when cosine similarity ≥ min_cosine OR Euclidean ≤ max_euclidean.
    | Previous defaults (~0.50 cosine) caused false "already registered" for different people.
    |--------------------------------------------------------------------------
    */
    'face_duplicate_min_cosine_similarity' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM')
        : null,

    /** @deprecated Use face_duplicate_min_cosine_similarity; kept for backward-compatible env keys */
    'face_duplicate_min_best_cosine_similarity' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_BEST_SIM'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_BEST_SIM')
        : (is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_SIM')) ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_SIM') : 0.85),

    /**
     * When comparing to another user's *averaged* embedding (2+ samples), slightly lower cosine
     * than per-sample so the same identity still matches under lighting variance.
     */
    'face_duplicate_min_cosine_similarity_avg' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM_AVG'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM_AVG')
        : null,

    /** Euclidean distance on raw 128-D vectors; Facenet same-person often below ~0.9, different people often higher. */
    'face_duplicate_max_euclidean' => (float) env('ATTENDANCE_FACE_DUPLICATE_MAX_EUCLIDEAN', 0.4),

    /**
     * Log when best cosine to any other employee is in [near_miss_min, min_cosine) for debugging borderline cases.
     */
    'face_duplicate_near_miss_log_min_similarity' => (float) env('ATTENDANCE_FACE_DUPLICATE_NEAR_MISS_MIN_SIM', 0.72),

    'face_duplicate_log_near_misses' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_LOG_NEAR_MISS', true),
        FILTER_VALIDATE_BOOL
    ),

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

    /*
    |--------------------------------------------------------------------------
    | Employee phone number requirement
    |--------------------------------------------------------------------------
    | When true, phone_number is required for new employees (admin and self-signup).
    | When false, phone_number is optional everywhere but still validated if present.
    */
    'employee_phone_required' => (bool) env('EMPLOYEE_PHONE_REQUIRED', true),

    /*
    |--------------------------------------------------------------------------
    | Same-day emergency leave filing
    |--------------------------------------------------------------------------
    | When true, emergency leave may be filed for today's date even after the
    | scheduled shift start time. Other full-day leave types remain blocked once
    | the shift has started and no attendance exists.
    */
    'allow_same_day_emergency_leave' => (bool) env('ALLOW_SAME_DAY_EMERGENCY_LEAVE', true),
];
