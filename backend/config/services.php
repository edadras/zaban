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


    /*
     * The voice behind the vocabulary cards. Reading a word aloud is the one
     * thing the books' own recordings cannot do - they are whole units - so
     * this is a separate account from the image generator, and its key lives
     * only in the environment.
     */
    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'voice' => env('ELEVENLABS_VOICE', 'Xb7hH8MSUJpSbSDYk0k2'),
        'model' => env('ELEVENLABS_MODEL', 'eleven_flash_v2_5'),
    ],

];
