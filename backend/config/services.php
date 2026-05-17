<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Legacy Twilio block (unused)
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID', env('TWILIO_MESSAGING_SID')),
    ],

    // PhilSMS v3 SMS API (used by SmsService)
    'philsms' => [
        'token' => env('PHILSMS_API_TOKEN'),
        'sender_id' => env('PHILSMS_SENDER_ID', 'PhilSMS'),
        'base_url' => env('PHILSMS_BASE_URL', 'https://dashboard.philsms.com/api/v3'),
    ],

    // Face verification (Python FastAPI: DeepFace embedding only; liveness via Amazon Rekognition)
    'face_verification' => [
        'url' => env('FACE_VERIFICATION_URL', 'http://127.0.0.1:5000'),
        // Performance defaults prioritize faster embedding generation for registration/login.
        'model_name' => env('FACE_VERIFICATION_MODEL_NAME', 'Facenet'),
        'detector_backend' => env('FACE_VERIFICATION_DETECTOR_BACKEND', 'mediapipe'),
        'enforce_detection' => filter_var(env('FACE_VERIFICATION_ENFORCE_DETECTION', true), FILTER_VALIDATE_BOOL),
        'align' => filter_var(env('FACE_VERIFICATION_ALIGN', true), FILTER_VALIDATE_BOOL),
        'input_width' => (int) env('FACE_VERIFICATION_INPUT_WIDTH', 640),
        'input_height' => (int) env('FACE_VERIFICATION_INPUT_HEIGHT', 480),
        'connect_timeout_seconds' => (int) env('FACE_VERIFICATION_CONNECT_TIMEOUT_SECONDS', 3),
        'embed_timeout_seconds' => (int) env('FACE_VERIFICATION_EMBED_TIMEOUT_SECONDS', 8),
        'verify_timeout_seconds' => (int) env('FACE_VERIFICATION_VERIFY_TIMEOUT_SECONDS', 10),
    ],

    // Amazon Rekognition Face Liveness (create session + get results; frontend uses Amplify FaceLivenessDetector)
    // Face Liveness is ONLY available in us-east-1, us-east-2. Other regions return AccessDeniedException.
    'rekognition' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('REKOGNITION_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'connect_timeout_seconds' => (int) env('REKOGNITION_CONNECT_TIMEOUT_SECONDS', 10),
        'timeout_seconds' => (int) env('REKOGNITION_TIMEOUT_SECONDS', 30),
    ],

    // Cognito Identity Pool for FaceLivenessDetector (frontend needs this to sign Rekognition requests)
    // MUST be in same region as Rekognition (us-east-1). ap-southeast-1 pool will cause SERVER_ERROR.
    'cognito' => [
        'identity_pool_id' => env('COGNITO_IDENTITY_POOL_ID'),
        'region' => env('COGNITO_REGION', 'us-east-1'),
    ],

    // Time and Date Holidays API (dev.timeanddate.com)
    // Get access + secret keys from https://dev.timeanddate.com/account/accesskey
    'timedate' => [
        'access_key' => trim(env('TIMEDATE_ACCESS_KEY', '') ?? ''),
        'secret_key' => trim(env('TIMEDATE_SECRET_KEY', '') ?? ''),
        'base_url' => 'https://api.xmltime.com',
    ],

    // Calendarific Holidays API (calendarific.com) - Admin Calendar module
    // Get API key from https://calendarific.com/ - free tier: 1,000 requests/day
    'calendarific' => [
        'api_key' => trim(env('CALENDARIFIC_API_KEY', '') ?? ''),
        'base_url' => env('CALENDARIFIC_BASE_URL', 'https://calendarific.com/api/v2'),
    ],

    // Browsershot / Chromium renderer for payslip PDFs
    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),
    ],

];
