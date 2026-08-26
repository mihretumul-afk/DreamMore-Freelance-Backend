<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    |
    | The payment provider driver to use. Supported: 'manual', 'chapa'.
    | Set via PAYMENT_PROVIDER env variable.
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Payment Environment
    |--------------------------------------------------------------------------
    |
    | The payment environment: 'sandbox' or 'production'.
    | Set via PAYMENT_ENVIRONMENT env variable.
    |
    */

    'environment' => env('PAYMENT_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Platform Fee Rate
    |--------------------------------------------------------------------------
    |
    | Platform fee as a decimal fraction. 0.05 = 5%.
    | This is charged on each milestone funding transaction.
    |
    */

    'platform_fee_rate' => (float) env('PLATFORM_FEE_RATE', 0.05),

    /*
    |--------------------------------------------------------------------------
    | Payment Processing Fee Rate
    |--------------------------------------------------------------------------
    |
    | Additional processing fee charged by the payment gateway.
    | 0.02 = 2%. This is added on top of the platform fee.
    |
    */

    'processing_fee_rate' => (float) env('PROCESSING_FEE_RATE', 0.02),

    /*
    |--------------------------------------------------------------------------
    | Minimum Withdrawal Amount
    |--------------------------------------------------------------------------
    |
    | The minimum amount a freelancer can withdraw in ETB.
    |
    */

    'min_withdrawal' => (float) env('MIN_WITHDRAWAL_AMOUNT', 100),

    /*
    |--------------------------------------------------------------------------
    | Withdrawal Fee
    |--------------------------------------------------------------------------
    |
    | Fee charged on each withdrawal as a percentage.
    | 0.01 = 1%.
    |
    */

    'withdrawal_fee_rate' => (float) env('WITHDRAWAL_FEE_RATE', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Chapa Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials and settings for the Chapa payment gateway.
    | Set via PAYMENT_PUBLIC_KEY, PAYMENT_SECRET_KEY, PAYMENT_WEBHOOK_SECRET env.
    |
    */

    'chapa' => [
        'public_key'     => env('PAYMENT_PUBLIC_KEY', ''),
        'secret_key'     => env('PAYMENT_SECRET_KEY', ''),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', ''),
        'base_url'       => env('PAYMENT_BASE_URL', 'https://api.chapa.co/v1'),
        'callback_url'   => env('PAYMENT_CALLBACK_URL', env('APP_URL') . '/api/v1/payments/webhook/chapa'),
        'return_url'     => env('PAYMENT_RETURN_URL', env('FRONTEND_URL', 'http://localhost:5173') . '/payment/result'),
    ],

];
