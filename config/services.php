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

    'semaphore' => [
        'key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME'),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    /*
    | Official Velocity Suites mobile app download destination (Play
    | Store listing, direct APK, etc). Left unset until a real link
    | exists - the Home page's Mobile App Promotion section checks this
    | and hides itself entirely rather than showing a placeholder/dead
    | download button when it's empty.
    */
    'mobile_app' => [
        'download_url' => env('MOBILE_APP_DOWNLOAD_URL'),

        /*
        | The Android APK offered in the Home page hero (App\Support\MobileApp).
        | ONE place to edit per release: drop the new APK into public/downloads/,
        | then change 'version' and 'file' here (and 'size_mb' only if the file
        | is ever missing - the real size is read from the file when it exists).
        | 'file' is relative to public/.
        */
        'version' => '1.55.0',
        'file' => 'downloads/velocity-suites-v1.55.0.apk',
        'size_mb' => 18.3,
        'min_android' => 10,
    ],

];
