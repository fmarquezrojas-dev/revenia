<?php

$endpoints = json_decode((string) env('ODOO_ENDPOINTS', '{}'), true);

return [
    'enabled' => (bool) env('ODOO_ENABLED', false),
    'endpoints' => is_array($endpoints) ? array_filter($endpoints, static fn ($value, $key) => is_string($key) && is_array($value) && is_string($value['url'] ?? null) && is_string($value['database'] ?? null), ARRAY_FILTER_USE_BOTH) : [],
];
