<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Secret Key
    |--------------------------------------------------------------------------
    |
    | The secret key used to sign JWT tokens. This should be a long, random
    | string that is kept secret and secure.
    |
    */
    'secret_key' => env('JWT_SECRET_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | The algorithm used to sign JWT tokens. Supported algorithms:
    | HS256, HS384, HS512, RS256, RS384, RS512, ES256, ES384, ES512
    |
    */
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Access Token TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | The time in seconds that an access token is valid for.
    | Default: 900 seconds (15 minutes)
    |
    */
    'access_token_ttl' => env('JWT_ACCESS_TOKEN_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Refresh Token TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | The time in seconds that a refresh token is valid for.
    | Default: 604800 seconds (7 days)
    |
    */
    'refresh_token_ttl' => env('JWT_REFRESH_TOKEN_TTL', 604800),

    /*
    |--------------------------------------------------------------------------
    | JWT Issuer
    |--------------------------------------------------------------------------
    |
    | The issuer of the JWT tokens. Usually your application name.
    |
    */
    'issuer' => env('JWT_ISSUER', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | JWT Leeway
    |--------------------------------------------------------------------------
    |
    | This property gives the jwt timestamp claims some "leeway".
    | Meaning that if you have a JWT that is expired by 30 seconds,
    | it will still be valid. This is to account for clock skew.
    |
    */
    'leeway' => env('JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Blacklist Grace Period
    |--------------------------------------------------------------------------
    |
    | When multiple concurrent requests are made with the same JWT,
    | it is possible that some of them fail. This grace period allows
    | a JWT to be used multiple times within the specified time period.
    |
    */
    'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    /*
    |--------------------------------------------------------------------------
    | Required Claims
    |--------------------------------------------------------------------------
    |
    | The minimum claims that must be present in any JWT token.
    |
    */
    'required_claims' => [
        'iss',
        'iat',
        'exp',
        'nbf',
        'sub',
        'jti',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Claims
    |--------------------------------------------------------------------------
    |
    | These claims will persist when refreshing a token.
    |
    */
    'persistent_claims' => [
        // Add custom claims that should persist through refresh
    ],

    /*
    |--------------------------------------------------------------------------
    | Lock Subject
    |--------------------------------------------------------------------------
    |
    | This will determine whether a `prv` claim is automatically added to
    | the token. The purpose of this is to ensure that if you have multiple
    | authentication models (user types) you can tell the difference
    | between them in the token.
    |
    */
    'lock_subject' => true,

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Specify the various providers used throughout the package.
    |
    */
    'providers' => [
        /*
        |--------------------------------------------------------------------------
        | User Provider
        |--------------------------------------------------------------------------
        |
        | Specify the provider that is used to find the user
        |
        */
        'user' => \App\Models\User::class,
    ],
];