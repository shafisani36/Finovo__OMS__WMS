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

    /*
    | Store platforms (Stores → Connect New Store)
    */

    /*
    | Store notifications (webhooks): the public HTTPS address stores send them to. Defaults to APP_URL.
    */
    'webhooks' => [
        'base_url' => env('WEBHOOK_BASE_URL', env('APP_URL')),
    ],

    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),
    ],

    'bigcommerce' => [
        'client_id' => env('BIGCOMMERCE_CLIENT_ID'),
        'client_secret' => env('BIGCOMMERCE_CLIENT_SECRET'),
        // Numeric app ID from the Developer Portal (Edit App URL: /apps/{APP_ID}/...). Enables one-click install.
        'app_id' => env('BIGCOMMERCE_APP_ID'),
    ],

    'ebay' => [
        'app_id' => env('EBAY_APP_ID'),
        'cert_id' => env('EBAY_CERT_ID'),
        'ru_name' => env('EBAY_RUNAME'),
        'environment' => env('EBAY_ENV', 'sandbox'),
    ],

    'wix' => [
        // Finovo app from the Wix app dashboard (More Actions → View ID & keys). Enables one-click install.
        'app_id' => env('WIX_APP_ID'),
        'app_secret' => env('WIX_APP_SECRET'),
        // Only while the app is unlisted: the ID at the end of the app's share install link.
        'share_url_id' => env('WIX_SHARE_URL_ID'),
        // Account API key for stores connected by site ID instead of the app.
        'api_key' => env('WIX_API_KEY'),
        // Public key Wix signs the app's notifications with (app dashboard → Webhooks); enables instant import.
        'webhook_public_key' => env('WIX_WEBHOOK_PUBLIC_KEY'),
    ],

    // Default MySQL login for OpenCart / osCommerce shop databases (a store can override each value)
    'external_database' => [
        'host' => env('DB_EXTERNAL_HOST', '127.0.0.1'),
        'port' => env('DB_EXTERNAL_PORT', 3306),
        'username' => env('DB_EXTERNAL_USER', 'root'),
        'password' => env('DB_EXTERNAL_PASSWORD', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
