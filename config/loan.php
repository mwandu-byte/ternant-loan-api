<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interest Brackets
    |--------------------------------------------------------------------------
    |
    | Ordered list of principal-amount brackets used to determine the
    | interest rate applied to a new loan. Each bracket declares its
    | upper boundary as either `max_exclusive` (principal must be
    | strictly less than this value) or `max_inclusive` (principal may
    | equal this value). Brackets are evaluated in order; a principal
    | that does not match any bracket is rejected via
    | LoanAmountOutOfRangeException.
    |
    */

    'interest_brackets' => [
        ['max_exclusive' => 500000, 'rate' => 30.00],
        ['max_inclusive' => 4000000, 'rate' => 22.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Repayment Frequencies
    |--------------------------------------------------------------------------
    |
    | Allowed values for a loan's repayment_frequency field. Extend this
    | list, plus the matching branch in LoanService::calculateDueDate(),
    | to support additional frequencies (e.g. 'weekly').
    |
    */

    'repayment_frequencies' => [
        'monthly',
    ],

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
