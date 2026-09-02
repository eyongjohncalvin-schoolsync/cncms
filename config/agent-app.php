<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Field Agent mobile app distribution
    |--------------------------------------------------------------------------
    |
    | Powers the in-app "Get the Agent App" page
    | (App\Http\Controllers\AgentAppController, /agent-app). CNCMS is not on
    | the Play Store / App Store — agents install a build directly. Point
    | `android_url` at wherever the current build lives:
    |
    |   - an Expo internal-distribution install URL from `eas build`
    |     (https://expo.dev/accounts/<acct>/projects/cncms-mobile/builds/<id>),
    |   - a direct .apk link (object storage, or a file dropped in
    |     public/downloads/ — NOT storage/app, which is wiped on Cloud
    |     redeploys), or
    |   - left null, which makes the page show "not available yet".
    |
    | `ios_url` stays null until there's an Apple Developer account +
    | TestFlight build; the page hides the iOS option while it's null.
    |
    */

    'android_url' => env('AGENT_APP_ANDROID_URL'),
    'ios_url' => env('AGENT_APP_IOS_URL'),

    // Shown on the page so agents can tell whether they're on the current
    // build. Keep in step with mobile/app.config.ts `version`.
    'version' => env('AGENT_APP_VERSION', '1.0.0'),

    // Optional ISO-8601 date string ("2026-09-02") for a "last updated" line.
    'updated_on' => env('AGENT_APP_UPDATED_ON'),

    // Minimum Android version the build supports, shown in the requirements
    // list. Expo SDK 54 targets Android 7.0+ by default.
    'android_min' => env('AGENT_APP_ANDROID_MIN', '7.0'),
];
