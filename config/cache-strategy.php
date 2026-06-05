<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Mode
    |--------------------------------------------------------------------------
    | 'event'     — DigitalOcean, Redis, short TTLs, lock-based stampede protection
    | 'offseason' — HostPapa, DB cache, long TTLs, jitter-based stampede protection
    */
    'mode' => env('CACHE_MODE', 'offseason'),

    /*
    |--------------------------------------------------------------------------
    | TTLs (seconds) per resource per mode
    |--------------------------------------------------------------------------
    */
    'ttl' => [
        'event' => [
            'event_list' => 30,
            'event_detail' => 20,
            'results' => 15,   // Most volatile — live lap data
            'classes' => 60,
            'schedule' => 30,
            'app_events' => 30,
            'current_event' => 20,
        ],
        'offseason' => [
            'event_list' => 3600,
            'event_detail' => 7200,
            'results' => 1800,
            'classes' => 86400,
            'schedule' => 3600,
            'app_events' => 3600,
            'current_event' => 7200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stampede Protection
    |--------------------------------------------------------------------------
    */
    'stampede_lock_ttl' => 5,   // Redis: lock TTL in seconds
    'redis_jitter_max' => 3,   // Redis: small positive jitter to spread expiry bursts
    'db_jitter_max' => 5,   // DB cache: larger jitter to reduce write contention

    /*
    |--------------------------------------------------------------------------
    | Version Stamp TTL
    |--------------------------------------------------------------------------
    | How long version stamps are kept. Long enough that they outlive the cached
    | data they guard, but not forever. 7 days is safe for all configured TTLs.
    */
    'version_stamp_ttl' => 86400 * 7,

];
