<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    | Default provider for processing payments. Use 'sandbox' for development.
    | Options: 'sandbox', 'telebirr', 'cbe', 'stripe'
    */
    'default' => env('PAYMENT_PROVIDER', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Platform Fee Rate
    |--------------------------------------------------------------------------
    | Percentage of payment amount charged as platform fee (0.08 = 8%).
    */
    'platform_fee_rate' => env('PLATFORM_FEE_RATE', 0.08),

    /*
    |--------------------------------------------------------------------------
    | Processing Fee Rate
    |--------------------------------------------------------------------------
    | Percentage of payment amount charged as processing fee (0.025 = 2.5%).
    */
    'processing_fee_rate' => env('PROCESSING_FEE_RATE', 0.025),

    /*
    |--------------------------------------------------------------------------
    | Withdrawal Fee Rate
    |--------------------------------------------------------------------------
    | Percentage of withdrawal amount charged as withdrawal fee (0.015 = 1.5%).
    */
    'withdrawal_fee_rate' => env('WITHDRAWAL_FEE_RATE', 0.015),

    /*
    |--------------------------------------------------------------------------
    | Clearance Period
    |--------------------------------------------------------------------------
    | Number of days before pending earnings become available for withdrawal.
    */
    'clearance_days' => env('EARNINGS_CLEARANCE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Minimum Withdrawal
    |--------------------------------------------------------------------------
    | Minimum amount that can be withdrawn.
    */
    'min_withdrawal' => env('MIN_WITHDRAWAL', 100),

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */
    'supported_currencies' => ['ETB', 'USD'],
];
