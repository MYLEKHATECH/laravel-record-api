<?php

return [
    'response' => [
        'include_request_id' => true,
    ],
    'openapi' => [
        // default output path relative to storage
        'output' => 'openapi-schema.json',
    ],
    'auth' => [
        'guard' => env('RECORD_API_AUTH_GUARD', 'api'),
    ],
];
