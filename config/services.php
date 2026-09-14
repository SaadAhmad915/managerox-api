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

    /*
     * Meta (Facebook/Instagram) Lead Ads.
     *
     * app_secret verifies the X-Hub-Signature-256 header on incoming webhooks;
     * without it anyone who learns the URL could post fake leads.
     * verify_token is the shared string Meta echoes back when you first
     * subscribe the webhook.
     * page_token is the long-lived Page access token used to read the lead
     * itself from the Graph API — the webhook only carries an id.
     */
    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_token' => env('META_PAGE_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        // Overridable so the integration can be exercised end to end against a
        // local stub, and so a regional Graph host can be used if ever needed.
        'graph_url' => rtrim(env('META_GRAPH_URL', 'https://graph.facebook.com'), '/'),
    ],

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
