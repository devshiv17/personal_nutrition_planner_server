<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Configure HSTS settings to force HTTPS connections and prevent
    | protocol downgrade attacks.
    |
    */

    'hsts' => [
        'max_age' => env('HSTS_MAX_AGE', 31536000), // 1 year
        'include_subdomains' => env('HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => env('HSTS_PRELOAD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy (CSP)
    |--------------------------------------------------------------------------
    |
    | Configure CSP settings to prevent XSS and other code injection attacks.
    |
    */

    'csp' => [
        'report_only' => env('CSP_REPORT_ONLY', false),
        'report_uri' => env('CSP_REPORT_URI', null),
        'upgrade_insecure_requests' => env('CSP_UPGRADE_INSECURE_REQUESTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Security Settings
    |--------------------------------------------------------------------------
    |
    | Configure advanced session security settings.
    |
    */

    'session' => [
        'strict_ip_check' => env('SESSION_STRICT_IP_CHECK', false),
        'max_concurrent_sessions' => env('SESSION_MAX_CONCURRENT', 5),
        'rotation_threshold' => env('SESSION_ROTATION_THRESHOLD', 900), // 15 minutes
        'max_lifetime' => env('SESSION_MAX_LIFETIME', 43200), // 12 hours
        'cleanup_probability' => env('SESSION_CLEANUP_PROBABILITY', 2), // 2%
        'device_fingerprinting' => env('SESSION_DEVICE_FINGERPRINTING', true),
        'suspicious_activity_threshold' => env('SESSION_SUSPICIOUS_THRESHOLD', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting settings for various endpoints.
    |
    */

    'rate_limiting' => [
        'login' => [
            'attempts' => env('LOGIN_RATE_LIMIT_ATTEMPTS', 5),
            'decay_minutes' => env('LOGIN_RATE_LIMIT_DECAY', 15),
        ],
        'api' => [
            'requests_per_minute' => env('API_RATE_LIMIT_RPM', 60),
        ],
        'password_reset' => [
            'attempts' => env('PASSWORD_RESET_RATE_LIMIT', 3),
            'decay_minutes' => env('PASSWORD_RESET_DECAY', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Security
    |--------------------------------------------------------------------------
    |
    | Configure password security requirements and validation.
    |
    */

    'password' => [
        'min_length' => env('PASSWORD_MIN_LENGTH', 8),
        'require_mixed_case' => env('PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', true),
        'prevent_common' => env('PASSWORD_PREVENT_COMMON', true),
        'prevent_user_info' => env('PASSWORD_PREVENT_USER_INFO', true),
        'history_count' => env('PASSWORD_HISTORY_COUNT', 5), // Remember last 5 passwords
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Settings
    |--------------------------------------------------------------------------
    |
    | Advanced encryption settings for sensitive data.
    |
    */

    'encryption' => [
        'sensitive_fields' => [
            'user_health_metrics' => ['weight', 'height', 'medical_conditions'],
            'user_sessions' => ['location_info'],
        ],
        'key_rotation' => [
            'enabled' => env('ENCRYPTION_KEY_ROTATION', false),
            'frequency_days' => env('ENCRYPTION_KEY_ROTATION_DAYS', 90),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    |
    | Configure security audit logging settings.
    |
    */

    'audit' => [
        'enabled' => env('SECURITY_AUDIT_ENABLED', true),
        'log_failed_logins' => env('AUDIT_LOG_FAILED_LOGINS', true),
        'log_password_changes' => env('AUDIT_LOG_PASSWORD_CHANGES', true),
        'log_session_events' => env('AUDIT_LOG_SESSION_EVENTS', true),
        'log_suspicious_activity' => env('AUDIT_LOG_SUSPICIOUS_ACTIVITY', true),
        'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist/Blacklist
    |--------------------------------------------------------------------------
    |
    | Configure IP-based access control.
    |
    */

    'ip_control' => [
        'whitelist_enabled' => env('IP_WHITELIST_ENABLED', false),
        'whitelist' => array_filter(explode(',', env('IP_WHITELIST', ''))),
        'blacklist_enabled' => env('IP_BLACKLIST_ENABLED', true),
        'blacklist' => array_filter(explode(',', env('IP_BLACKLIST', ''))),
        'auto_block_suspicious' => env('IP_AUTO_BLOCK_SUSPICIOUS', false),
        'block_duration_minutes' => env('IP_BLOCK_DURATION', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | Configure 2FA settings (for future implementation).
    |
    */

    '2fa' => [
        'enabled' => env('TWO_FACTOR_AUTH_ENABLED', false),
        'required_for_admin' => env('TWO_FACTOR_REQUIRED_ADMIN', false),
        'backup_codes_count' => env('TWO_FACTOR_BACKUP_CODES', 8),
        'totp_window' => env('TWO_FACTOR_TOTP_WINDOW', 1), // 30 second window
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure security monitoring and alerting.
    |
    */

    'monitoring' => [
        'failed_login_threshold' => env('MONITOR_FAILED_LOGIN_THRESHOLD', 10),
        'suspicious_activity_threshold' => env('MONITOR_SUSPICIOUS_THRESHOLD', 5),
        'alert_email' => env('SECURITY_ALERT_EMAIL', null),
        'webhook_url' => env('SECURITY_WEBHOOK_URL', null),
        'slack_webhook' => env('SECURITY_SLACK_WEBHOOK', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Security
    |--------------------------------------------------------------------------
    |
    | Configure database security settings.
    |
    */

    'database' => [
        'encrypt_sensitive_fields' => env('DB_ENCRYPT_SENSITIVE', true),
        'query_logging' => env('DB_QUERY_LOGGING', false),
        'slow_query_threshold' => env('DB_SLOW_QUERY_THRESHOLD', 2000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | API Security
    |--------------------------------------------------------------------------
    |
    | Configure API-specific security settings.
    |
    */

    'api' => [
        'version_enforcement' => env('API_VERSION_ENFORCEMENT', true),
        'deprecation_warnings' => env('API_DEPRECATION_WARNINGS', true),
        'request_signature' => env('API_REQUEST_SIGNATURE', false),
        'timestamp_validation' => env('API_TIMESTAMP_VALIDATION', false),
        'timestamp_tolerance' => env('API_TIMESTAMP_TOLERANCE', 300), // 5 minutes
    ],

];