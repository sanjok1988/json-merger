<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sort Object Keys
    |--------------------------------------------------------------------------
    |
    | When enabled, associative object keys are sorted alphabetically
    | (locale-independent, natural string order) recursively after merge
    | or check. Numeric arrays are never sorted.
    |
    */

    'sort_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | Allow File Overwrite
    |--------------------------------------------------------------------------
    |
    | When false (default), merge operations will not overwrite an existing
    | output file unless --force is passed to the Artisan command or
    | allowOverwrite is explicitly set to true in PHP.
    |
    */

    'allow_overwrite' => false,

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_TRANSLATION_MODEL', 'gpt-4o'),
        'endpoint' => env('OPENAI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'timeout' => (int) env('OPENAI_TRANSLATION_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The optional HTTP API accepts JSON payloads only. It never accepts
    | arbitrary server file paths. Disabled by default for safety.
    |
    */

    'api' => [
        'enabled' => false,
        'prefix' => 'api/locale-json',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Encoding Options
    |--------------------------------------------------------------------------
    |
    | Controls how merged locale files are written to disk.
    |
    */

    'json' => [
        'pretty_print' => true,
        'unescaped_unicode' => true,
        'unescaped_slashes' => true,
        'final_newline' => true,
    ],

];
