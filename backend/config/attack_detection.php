<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Attack Detection Enable/Disable
    |--------------------------------------------------------------------------
    */
    'enabled' => env('ATTACK_DETECTION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Detection Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        // Order Spoofing Detection
        'spoofing' => [
            'cancel_rate_threshold' => 0.7,           // 70% cancel rate triggers detection
            'min_orders_for_detection' => 5,          // Need at least 5 orders to calculate rate
            'quick_cancel_seconds' => 30,             // Orders cancelled within 30s are suspicious
            'large_order_multiplier' => 3.0,          // Orders 3x average size are "large"
            'lookback_minutes' => 60,                 // Look at last 60 minutes of activity
        ],

        // Wash Trading Detection
        'wash_trading' => [
            'same_ip_trade_threshold' => 3,           // 3+ trades between same-IP accounts
            'timing_window_seconds' => 60,            // Trades within 60s window
            'price_deviation_threshold' => 0.001,     // < 0.1% price difference is suspicious
            'lookback_hours' => 24,                   // Check last 24 hours
        ],

        // Layering Detection
        'layering' => [
            'min_orders_same_price' => 3,             // 3+ orders at same price level
            'batch_cancel_threshold' => 3,            // 3+ cancels within short window
            'batch_window_seconds' => 10,             // 10 second window for batch detection
            'price_level_tolerance' => 0.0001,        // 0.01% tolerance for "same" price
        ],

        // Price Manipulation Detection
        'price_manipulation' => [
            'deviation_from_market' => 0.05,          // 5% from current market price
            'extreme_deviation' => 0.20,              // 20% is extreme manipulation
            'market_impact_threshold' => 0.01,        // 1% potential market impact
        ],

        // Rapid-Fire Spam Detection
        'spam' => [
            'orders_per_minute' => 30,                // Max 30 orders per minute
            'orders_per_hour' => 500,                 // Max 500 orders per hour
            'cancels_per_minute' => 20,               // Max 20 cancels per minute
            'requests_per_second' => 5,               // Max 5 requests per second
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Score Settings
    |--------------------------------------------------------------------------
    */
    'risk_scoring' => [
        'auto_flag_threshold' => 50,                  // Auto-flag account at risk score 50
        'auto_suspend_threshold' => 80,               // Auto-suspend at risk score 80
        'decay_rate_per_day' => 5,                    // Risk score decays 5 points per day
        'max_score' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling Settings
    |--------------------------------------------------------------------------
    */
    'throttling' => [
        'enabled' => true,
        'delays' => [
            'low' => 500,           // 0.5 second
            'medium' => 2000,       // 2 seconds
            'high' => 5000,         // 5 seconds
            'critical' => 0,        // Block (no delay)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Settings
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'enabled' => env('SECURITY_ALERTS_ENABLED', true),
        'broadcast_channel' => 'security-alerts',
        'admin_user_ids' => array_map('intval', array_filter(explode(',', env('SECURITY_ADMIN_IDS', '')))),
        'cooldown_minutes' => 5,                      // Don't repeat same alert within 5 mins
        'batch_alerts' => true,                       // Batch similar alerts together
        'batch_window_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected Endpoints
    |--------------------------------------------------------------------------
    | Only these endpoints will be monitored for attacks.
    */
    'protected_endpoints' => [
        'POST' => [
            '/api/orders',
            '/api/orders/*/cancel',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelist (skip detection)
    |--------------------------------------------------------------------------
    */
    'ip_whitelist' => array_filter(explode(',', env('SECURITY_IP_WHITELIST', ''))),

    /*
    |--------------------------------------------------------------------------
    | User Whitelist (skip detection)
    |--------------------------------------------------------------------------
    */
    'user_whitelist' => array_map('intval', array_filter(explode(',', env('SECURITY_USER_WHITELIST', '')))),
];
