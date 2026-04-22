<?php
return [
    'enabled' => env('AWS_SECRETS_ENABLED', true),
    'check_latency' => env('AWS_SECRETS_CHECK_LATENCY', false),
    'region' => env('AWS_SECRETS_REGION', 'us-west-2'),
    'cache_ttl' => env('AWS_SECRETS_CACHE_TTL', 3600),
    'cache_store' => env('AWS_SECRETS_CACHE_STORE', 'redis'),
    'name' => env('AWS_SECRETS_NAME', ''),
    'log_channel' => env('AWS_SECRETS_LOG_CHANNEL', 'awssecrets'),
];
