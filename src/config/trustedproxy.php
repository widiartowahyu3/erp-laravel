<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The IP addresses or CIDR blocks of trusted reverse proxies / load balancers.
    | When empty or null, no proxies are trusted and client IPs are resolved
    | directly from REMOTE_ADDR, preventing X-Forwarded-For spoofing attacks.
    |
    */

    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))) ?: null,
];
