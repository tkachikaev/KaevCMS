<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Leave this empty when the application receives requests directly. When
    | Cloudflare, a load balancer, or another reverse proxy is used, list its
    | IP addresses or CIDR ranges separated by commas. The special value "*"
    | is accepted but should only be used when the web server cannot be reached
    | directly from the internet.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
];
