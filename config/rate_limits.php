<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Management Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed for each password-management endpoint.
    | Kept configurable via environment so limits can be tuned per
    | deployment without a code change.
    |
    */

    'forgot_password' => (int) env('RATE_LIMIT_FORGOT_PASSWORD', 3),

    'reset_password' => (int) env('RATE_LIMIT_RESET_PASSWORD', 5),

    'change_password' => (int) env('RATE_LIMIT_CHANGE_PASSWORD', 5),

];
