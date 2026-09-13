<?php

return [

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'firebase_project_id' => env('FIREBASE_PROJECT_ID', 'scrapify-auction'),
        'firebase_credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'otp_template_id' => env('MSG91_OTP_TEMPLATE_ID'),
        'sms_template_id' => env('MSG91_SMS_TEMPLATE_ID'),
        'sender_id' => env('MSG91_SENDER_ID', 'YOURBR'),
        'country_code' => env('MSG91_COUNTRY_CODE', '91'),
        'otp_test_mode' => (bool) env('OTP_TEST_MODE', false),
    ],

    'cashfree_secure_id' => [
        'enabled' => env('CASHFREE_SECURE_ID_ENABLED', false),
        'environment' => env('CASHFREE_SECURE_ID_ENV', 'sandbox'),
        'client_id' => env('CASHFREE_SECURE_ID_CLIENT_ID'),
        'client_secret' => env('CASHFREE_SECURE_ID_CLIENT_SECRET'),
        'base_url' => env('CASHFREE_SECURE_ID_BASE_URL', 'https://sandbox.cashfree.com/verification'),
        'timeout' => env('CASHFREE_SECURE_ID_TIMEOUT', 30),
    ],

    'sandbox_verification' => [
        'enabled' => env('SANDBOX_VERIFICATION_ENABLED', true),
        'environment' => env('SANDBOX_VERIFICATION_ENV', 'test'),
        'api_key' => env('SANDBOX_VERIFICATION_API_KEY'),
        'api_secret' => env('SANDBOX_VERIFICATION_API_SECRET'),
        'base_url' => env('SANDBOX_VERIFICATION_BASE_URL'),
        'api_version' => env('SANDBOX_VERIFICATION_API_VERSION', '1.0.0'),
        'timeout' => env('SANDBOX_VERIFICATION_TIMEOUT', 30),
    ],

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

];
