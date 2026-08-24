<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reference Number Prefix
    |--------------------------------------------------------------------------
    |
    | Loan reference numbers are formatted as {prefix}-{year}-{6-digit
    | zero-padded sequence}, e.g. LN-2026-000001.
    |
    */

    'reference_prefix' => env('LOAN_REFERENCE_PREFIX', 'LN'),

];
