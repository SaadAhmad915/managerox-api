<?php

/*
 * Cross-origin settings for the CRM at app.managerox.com.
 *
 * app. and api. are different ORIGINS (so CORS applies) but the same SITE (so a
 * Lax cookie on .managerox.com is still sent). Two rules follow:
 *
 *  - Origins must be listed explicitly. A wildcard with credentials is invalid
 *    per the CORS spec and browsers reject the response outright.
 *  - supports_credentials must be true, or the browser drops the session cookie.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
