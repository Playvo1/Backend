<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated dashboard origins in staging/production (TEAM_GUIDELINES 2.4:
    // never a wildcard there). Unset locally, so any origin is allowed during development.
    // Entries are trimmed and empty ones dropped, so "a.com, b.com," lists exactly two;
    // an origin must match exactly, so a stray space would otherwise block it.
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))),
        fn (string $origin) => $origin !== '',
    )),

    // Comma-separated PHP regexes (delimiters included) for origins that can't be listed
    // one by one, such as the dashboard's Vercel preview deployments, whose host changes
    // with every branch and commit. The default accepts only this team's previews of the
    // "frontend" projects (https only); set the variable to an empty value to allow none.
    // A comma can't be used inside a pattern, as it separates them.
    'allowed_origins_patterns' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '#^https://frontend[a-z0-9-]*-playvo\.vercel\.app$#'))),
        fn (string $pattern) => $pattern !== '',
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
