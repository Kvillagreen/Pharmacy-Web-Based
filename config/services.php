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

    'mysmsgate_sms' => [
        'base_url' => env('SMS_BASE_API_URL', 'https://mysmsgate.net/api/v1'),
        'proxy_url' => env('SMS_PROXY_URL'),
        'api_token' => env('SMS_API_KEY'),
        'sender_name' => env('SMS_SENDER_NAME', env('FORTMED_SMS_SENDER_NAME', env('APP_NAME', 'Pharmacy Web'))),
        'from_number' => env('SMS_FROM_NUMBER', env('FORTMED_SMS_FROM_NUMBER')),
        'slot' => env('SMS_SLOT', 0),
        'user_agent' => env('SMS_REQUEST_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ],

];
