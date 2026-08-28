<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Dashboard Period
    |--------------------------------------------------------------------------
    |
    | When the dashboard is requested without an explicit from/to date range,
    | the trend/performance sections (period, loan_performance, charts) fall
    | back to this many trailing days ending today. The five original
    | headline summary blocks (customers/loans/financial/repayments/payments)
    | are unaffected by this default and remain all-time unless from/to are
    | explicitly supplied.
    |
    */

    'default_period_days' => env('DASHBOARD_DEFAULT_PERIOD_DAYS', 30),

];
