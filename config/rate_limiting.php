<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines rate limiting settings for different
    | API endpoints and user types.
    |
    */

    'defaults' => [
        'authenticated' => [
            'max_attempts' => 200,
            'decay_minutes' => 1,
        ],
        'unauthenticated' => [
            'max_attempts' => 60,
            'decay_minutes' => 1,
        ],
    ],

    'endpoints' => [
        'auth' => [
            'login' => [
                'max_attempts' => 10,
                'decay_minutes' => 1,
                'lockout_duration' => 15, // minutes
            ],
            'register' => [
                'max_attempts' => 5,
                'decay_minutes' => 1,
            ],
            'password_reset' => [
                'max_attempts' => 3,
                'decay_minutes' => 1,
            ],
        ],
        'search' => [
            'max_attempts' => 100,
            'decay_minutes' => 1,
        ],
        'upload' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Type Specific Limits
    |--------------------------------------------------------------------------
    |
    | Different limits based on user types or subscription tiers
    |
    */
    'user_types' => [
        'free' => [
            'api_calls_per_hour' => 100,
            'api_calls_per_day' => 1000,
        ],
        'premium' => [
            'api_calls_per_hour' => 500,
            'api_calls_per_day' => 10000,
        ],
        'admin' => [
            'api_calls_per_hour' => 2000,
            'api_calls_per_day' => 50000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP-based Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limits for unauthenticated requests based on IP address
    |
    */
    'ip_limits' => [
        'max_attempts' => 60,
        'decay_minutes' => 1,
        'blocked_duration' => 60, // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Bypass Settings
    |--------------------------------------------------------------------------
    |
    | IPs or conditions that bypass rate limiting
    |
    */
    'bypass' => [
        'whitelist_ips' => [
            // '127.0.0.1',
            // '::1',
        ],
        'health_check_endpoints' => [
            '/api/health',
            '/health',
            '/up',
        ],
    ],
];