<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Timezone for attendance (clock-in/out times and schedule comparison).
    | Clock-in times from the DB (stored in UTC) are converted to this zone so
    | noon-window rules match wall clock. Set APP_TIMEZONE or ATTENDANCE_TIMEZONE in .env
    | (e.g. Asia/Manila). Half Day clock-in window is configurable (default 12:00–13:00 local).
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
    | Clock-in/out liveness floor (identity verification path)
    |--------------------------------------------------------------------------
    | Additional guard for attendance scanFace endpoint. Keeps Amplify/Rekognition
    | mandatory and requires a stronger confidence floor before face matching.
    */
    'face_clock_min_liveness_score' => (float) env('ATTENDANCE_FACE_CLOCK_MIN_LIVENESS_SCORE', 0.60),

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
    | Registration liveness (Rekognition) — lighter floor than strict registration
    |--------------------------------------------------------------------------
    | When true, registration uses the same floor as clock-in/out (face_min_liveness_score only).
    | When false, max(face_min_liveness_score, face_registration_min_liveness_score) is used.
    |--------------------------------------------------------------------------
    */
    'face_registration_light_liveness' => filter_var(
        env('ATTENDANCE_FACE_REGISTRATION_LIGHT_LIVENESS', true),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Face match threshold (Euclidean distance, InsightFace 512D ArcFace).
    | InsightFace returns L2-normalized unit vectors, so Euclidean and cosine
    | are directly related: cos_sim=0.5 → euc≈1.0; cos_sim=0.3 → euc≈1.18.
    | Used only when cosine threshold is disabled (face_cosine_distance_threshold=null).
    |--------------------------------------------------------------------------
    */
    'face_match_threshold' => (float) env('ATTENDANCE_FACE_MATCH_THRESHOLD', 1.10),

    /*
    |--------------------------------------------------------------------------
    | Face similarity threshold (cosine distance 0–1). Primary matching mode.
    | InsightFace ArcFace: same-person cosine similarity ≈ 0.3–0.7 depending on
    | lighting/pose; different-person typically < 0.25.
    | Match when cosine_distance <= threshold (i.e. cosine_similarity >= 1 - threshold).
    | 0.55 ≈ similarity ≥ 0.45 — balanced for real-world HR kiosk conditions.
    |--------------------------------------------------------------------------
    */
    'face_cosine_distance_threshold' => (float) env('ATTENDANCE_FACE_COSINE_DISTANCE_THRESHOLD', 0.55),

    /*
    |--------------------------------------------------------------------------
    | Face matching safety checks (anti-misidentification)
    |--------------------------------------------------------------------------
    | Require both:
    | - minimum cosine similarity (0–1) — InsightFace ArcFace same-person ≈ 0.35–0.70
    | - a margin vs the second-best match (prevents "close call" misidentifications)
    */
    'face_min_similarity_score' => (float) env('ATTENDANCE_FACE_MIN_SIMILARITY_SCORE', 0.40),
    'face_min_similarity_margin' => (float) env('ATTENDANCE_FACE_MIN_SIMILARITY_MARGIN', 0.08),

    /*
    |--------------------------------------------------------------------------
    | Kiosk / clock-in-out: looser thresholds for faster, more reliable matching
    |--------------------------------------------------------------------------
    | Registration uses stricter duplicate thresholds (0.65+ cosine). Verification
    | (kiosk clock in/out) can afford slightly looser gates because Rekognition
    | liveness already authenticates a live person, and the ambiguity margin
    | still prevents misidentification between enrolled employees.
    |
    | Set to null to use the same thresholds as general face identification.
    */
    'face_kiosk_cosine_distance_threshold' => is_numeric(env('ATTENDANCE_FACE_KIOSK_COSINE_DISTANCE_THRESHOLD'))
        ? (float) env('ATTENDANCE_FACE_KIOSK_COSINE_DISTANCE_THRESHOLD')
        : 0.48,
    'face_kiosk_min_similarity_score' => is_numeric(env('ATTENDANCE_FACE_KIOSK_MIN_SIMILARITY_SCORE'))
        ? (float) env('ATTENDANCE_FACE_KIOSK_MIN_SIMILARITY_SCORE')
        : 0.45,
    'face_kiosk_match_threshold' => is_numeric(env('ATTENDANCE_FACE_KIOSK_MATCH_THRESHOLD'))
        ? (float) env('ATTENDANCE_FACE_KIOSK_MATCH_THRESHOLD')
        : 1.05,

    'face_kiosk_account_mismatch_min_similarity' => (float) env('ATTENDANCE_FACE_KIOSK_ACCOUNT_MISMATCH_MIN_SIMILARITY', 0.60),

    /*
    |--------------------------------------------------------------------------
    | Cross-camera accuracy mode (registration camera != kiosk camera)
    |--------------------------------------------------------------------------
    | Different devices can shift exposure, white balance, focal length, and compression.
    | When liveness confidence is very high, allow a small relaxed matching window while
    | still enforcing anti-ambiguity (stronger top-1 vs top-2 margin in matcher).
    */
    'face_cross_camera_relax_enabled' => filter_var(
        env('ATTENDANCE_FACE_CROSS_CAMERA_RELAX_ENABLED', true),
        FILTER_VALIDATE_BOOL
    ),
    'face_cross_camera_high_liveness_score' => (float) env('ATTENDANCE_FACE_CROSS_CAMERA_HIGH_LIVENESS', 0.90),
    'face_cross_camera_min_similarity_relax_delta' => (float) env('ATTENDANCE_FACE_CROSS_CAMERA_MIN_SIM_RELAX_DELTA', 0.03),
    'face_cross_camera_cosine_distance_relax_delta' => (float) env('ATTENDANCE_FACE_CROSS_CAMERA_COS_DIST_RELAX_DELTA', 0.02),
    'face_cross_camera_kiosk_min_similarity_floor' => (float) env('ATTENDANCE_FACE_CROSS_CAMERA_MIN_SIM_FLOOR', 0.40),
    'face_cross_camera_min_similarity_margin' => (float) env('ATTENDANCE_FACE_CROSS_CAMERA_MIN_MARGIN', 0.10),

    /*
    |--------------------------------------------------------------------------
    | Identity-bound face verification thresholds (specific employee only)
    |--------------------------------------------------------------------------
    | Used when attendance face verification is bound to a claimed employee
    | (e.g., kiosk login field). Prevents cross-employee matching.
    | InsightFace ArcFace: strict same-person cosine ≥ 0.50 and euc ≤ 1.0.
    */
    'face_identity_min_similarity_score' => (float) env('ATTENDANCE_FACE_IDENTITY_MIN_SIMILARITY_SCORE', 0.58),
    'face_identity_max_euclidean_distance' => (float) env('ATTENDANCE_FACE_IDENTITY_MAX_EUCLIDEAN_DISTANCE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Face failure lockout (temporary)
    |--------------------------------------------------------------------------
    */
    'face_failed_attempts_limit' => (int) env('ATTENDANCE_FACE_FAILED_ATTEMPTS_LIMIT', 5),
    'face_failed_attempts_window_minutes' => (int) env('ATTENDANCE_FACE_FAILED_ATTEMPTS_WINDOW_MINUTES', 10),

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
    | Compared against *other* users' stored samples + primary (+ optional mean row); scan is not limited by roster role.
    | Primary duplicate: cosine ≥ min_cosine OR raw Euclidean ≤ max_euclidean OR normalized Euclidean ≤ max_euclidean_normalized.
    | Optional dual-signal: cosine ≥ dual_min AND (raw Euclidean ≤ dual_max OR normalized Euclidean ≤ max_euclidean_normalized).
    | Registration compares every DB row when face_duplicate_registration_force_full_db_scan is true.
    |--------------------------------------------------------------------------
    */
    'face_duplicate_min_cosine_similarity' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM')
        : null,

    /** @deprecated Use face_duplicate_min_cosine_similarity; kept for backward-compatible env keys */
    'face_duplicate_min_best_cosine_similarity' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_BEST_SIM'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_BEST_SIM')
        : (is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_SIM')) ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_SIM') : 0.60),

    /**
     * When comparing to another user's *averaged* embedding (2+ samples), slightly lower cosine
     * than per-sample so the same identity still matches under lighting variance.
     */
    'face_duplicate_min_cosine_similarity_avg' => is_numeric(env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM_AVG'))
        ? (float) env('ATTENDANCE_FACE_DUPLICATE_MIN_COSINE_SIM_AVG')
        : null,

    /**
     * Euclidean distance on raw InsightFace 512-D unit vectors.
     * cos_sim=0.70 → euc≈0.77; cos_sim=0.65 → euc≈0.84.
     * Block duplicate when distance is at or below this value.
     */
    'face_duplicate_max_euclidean' => (float) env('ATTENDANCE_FACE_DUPLICATE_MAX_EUCLIDEAN', 0.80),

    /** Euclidean distance on L2-normalized 512-D vectors (same geometry as cosine; unit vectors ≈ same values). */
    'face_duplicate_max_euclidean_normalized' => (float) env('ATTENDANCE_FACE_DUPLICATE_MAX_EUCLIDEAN_NORM', 0.80),

    /** Block weak env values: effective min cosine for duplicate = max(resolved config, this floor). */
    'face_duplicate_enforce_registration_cosine_floor' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_ENFORCE_COSINE_FLOOR', true),
        FILTER_VALIDATE_BOOL
    ),
    'face_duplicate_registration_cosine_floor' => (float) env('ATTENDANCE_FACE_DUPLICATE_REGISTRATION_COSINE_FLOOR', 0.60),

    /** Extra OR-path for same-face pairs that miss both primary gates (see FaceVerificationService::duplicateRowMatchesIncoming). */
    'face_duplicate_dual_signal_enabled' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_DUAL_SIGNAL', true),
        FILTER_VALIDATE_BOOL
    ),
    'face_duplicate_dual_cosine_min' => (float) env('ATTENDANCE_FACE_DUPLICATE_DUAL_COSINE_MIN', 0.60),
    'face_duplicate_dual_max_euclidean' => (float) env('ATTENDANCE_FACE_DUPLICATE_DUAL_MAX_EUCLIDEAN', 0.85),

    /**
     * Aggregate best-of-all-samples: if the BEST cosine similarity across ALL stored vectors
     * for a single other user exceeds this threshold, flag as duplicate. Lower than per-row gate
     * because looking at the best sample pair is the most reliable single-signal check.
     * InsightFace ArcFace: 0.65 ≈ clearly the same person.
     */
    'face_duplicate_aggregate_best_cosine_min' => (float) env('ATTENDANCE_FACE_DUPLICATE_AGGREGATE_BEST_COSINE_MIN', 0.60),

    /**
     * Raw (un-normalized) cosine similarity threshold for duplicate detection.
     * InsightFace already returns normalized vectors, so raw ≈ normalized; set same as primary.
     */
    'face_duplicate_raw_cosine_min' => (float) env('ATTENDANCE_FACE_DUPLICATE_RAW_COSINE_MIN', 0.60),

    /** Registration job / admin descriptor: compare all rows in DB (recommended). Non-registration callers may use the embedding index when false. */
    'face_duplicate_registration_force_full_db_scan' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_REGISTRATION_FULL_SCAN', true),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Strict global registration gate (ProcessFaceRegistrationJob)
    |--------------------------------------------------------------------------
    | This gate runs ONCE per registration attempt inside a DB transaction with
    | a global lock.  It ALWAYS queries the live database (never uses the
    | embedding index cache) to ensure newly-registered faces are always seen.
    |
    | Cosine similarity (L2-normalised ArcFace vectors):
    |   • ≥ 0.88 → almost certainly the same person → block
    | Normalised Euclidean distance (same unit-vector geometry):
    |   • ≤ 0.50 → equivalent to cosine ≈ 0.875 → block
    |   (For unit vectors: euc = sqrt(2*(1-cos)), so euc=0.50 → cos=0.875)
    |
    | Raise ATTENDANCE_FACE_DUPLICATE_STRICT_COSINE to reduce false positives.
    | Lower it to catch more borderline same-person pairs (more aggressive).
    |--------------------------------------------------------------------------
    */
    'face_duplicate_strict_min_cosine_similarity' => (float) env(
        'ATTENDANCE_FACE_DUPLICATE_STRICT_COSINE',
        0.88
    ),
    'face_duplicate_strict_max_euclidean_normalized' => (float) env(
        'ATTENDANCE_FACE_DUPLICATE_STRICT_EUCLIDEAN_NORM',
        0.35
    ),

    /**
     * Near-miss logging for the strict gate: log matches in [near_miss, strict_min) so
     * administrators can tune the threshold without missing borderline cases.
     * InsightFace ArcFace: 0.72 is already very high for unrelated people.
     */
    'face_duplicate_strict_near_miss_cosine' => (float) env(
        'ATTENDANCE_FACE_DUPLICATE_STRICT_NEAR_MISS',
        0.72
    ),

    /** After exhaustive registration scan with no match, log best cosine / distance for debugging. */
    'face_duplicate_log_registration_scan_summary' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_LOG_SCAN_SUMMARY', true),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Log when best cosine to any other employee is in [near_miss_min, min_cosine) for debugging borderline cases.
     * InsightFace ArcFace: 0.55 is unusually high for unrelated people; log it for review.
     */
    'face_duplicate_near_miss_log_min_similarity' => (float) env('ATTENDANCE_FACE_DUPLICATE_NEAR_MISS_MIN_SIM', 0.55),

    'face_duplicate_log_near_misses' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_LOG_NEAR_MISS', true),
        FILTER_VALIDATE_BOOL
    ),

    /*
    |--------------------------------------------------------------------------
    | Duplicate embedding index (registration speed)
    |--------------------------------------------------------------------------
    | Precomputes flattened comparison rows (per-user samples + optional avg) in cache.
    | Version bumps on successful registration or face reset so the next check rebuilds from DB.
    |--------------------------------------------------------------------------
    */
    'face_duplicate_use_embedding_index_cache' => filter_var(
        env('ATTENDANCE_FACE_DUPLICATE_USE_INDEX_CACHE', true),
        FILTER_VALIDATE_BOOL
    ),
    'face_duplicate_embedding_index_ttl_seconds' => (int) env('ATTENDANCE_FACE_DUPLICATE_INDEX_TTL_SECONDS', 86400),

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
