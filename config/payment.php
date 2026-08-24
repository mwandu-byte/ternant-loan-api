<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Receipt Number Prefix
    |--------------------------------------------------------------------------
    |
    | Receipt numbers are formatted as {prefix}-{year}-{6-digit zero-padded
    | sequence}, e.g. RC-2026-000001. Mirrors config('loan.reference_prefix').
    |
    */

    'receipt_prefix' => env('RECEIPT_PREFIX', 'RC'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Payment Methods
    |--------------------------------------------------------------------------
    |
    | Used to validate `payment_method` on both repayments (money in) and
    | loan disbursements (money out). Extend this list as new payment
    | channels are supported operationally — no code change needed
    | elsewhere.
    |
    */

    'methods' => [
        'cash',
        'bank_transfer',
        'mobile_money',
        'cheque',
        'card',
    ],

];
