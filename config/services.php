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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'firebase' => [
        'credentials' => storage_path('app/firebase/palyvo-cc91c-firebase-adminsdk-fbsvc-e36e3ac9fb.json'),
    ],

    // Booking assistant (US-4.5). Without a key the rule-based parser answers alone.
    // "?:" so an empty value in the environment (e.g. GEMINI_TIMEOUT=) keeps the default.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite',
        'base_url' => env('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta',
        'timeout' => (int) (env('GEMINI_TIMEOUT') ?: 8),
    ],

    // Guidelines 2.4: assistant questions per player per hour.
    'assistant' => [
        'hourly_limit' => (int) (env('ASSISTANT_HOURLY_LIMIT') ?: 20),
        // Quick-reply taps (option_id without text) never reach Gemini, so they have a bucket of their own.
        'option_hourly_limit' => (int) (env('ASSISTANT_OPTION_HOURLY_LIMIT') ?: 60),
        // A conversation idle for longer starts afresh (assistant v2).
        'conversation_minutes' => (int) (env('ASSISTANT_CONVERSATION_MINUTES') ?: 30),
        // Ratings of the assistant per player per hour.
        'feedback_hourly_limit' => (int) (env('ASSISTANT_FEEDBACK_HOURLY_LIMIT') ?: 10),
    ],

    // DemoDataSeeder: the password of the demo venue owners. Empty: a random one, so they can't log in.
    'demo' => [
        'owner_password' => env('DEMO_OWNER_PASSWORD'),
    ],

];
