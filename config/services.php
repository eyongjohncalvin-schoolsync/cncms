<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Self-service Google sign-up/sign-in (Laravel Socialite) — see
    // .ai/skills/cncms/cncms-context/references/self-service-onboarding.md
    // section 7. Real credentials must be provisioned in Google Cloud
    // Console before this works end-to-end; not something an agent in this
    // environment can do.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Expo push notification service (mobile-push-notifications build
    // notes) — App\Services\ExpoPushService. `access_token` is OPTIONAL:
    // Expo's push send/getReceipts endpoints work unauthenticated; setting
    // this enables Expo's "Enhanced Security" mode (rejects push requests
    // not carrying this project's own access token), which is not required
    // for v1 to function. Left unset here on purpose.
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

];
