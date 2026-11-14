<?php

return [
    'enabled' => (bool) env('MP_SANDBOX_ENABLED', false),
    'access_token' => env('MP_TEST_ACCESS_TOKEN'),
    'collector_id' => env('MP_TEST_COLLECTOR_ID'),
    'webhook_secret' => env('MP_WEBHOOK_SECRET'),
    'notification_url' => env('MP_WEBHOOK_URL'),
    'return_origin' => env('MP_RETURN_ORIGIN'),
    'queue_connection' => env('PAYMENT_QUEUE_CONNECTION', 'redis'),
];
