<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset Deep Link Scheme
    |--------------------------------------------------------------------------
    |
    | The mobile application's custom URL scheme, used to build the deep link
    | sent in the password reset email (e.g. "loanmanagement://reset-password
    | ?token=...&email=..."). The mobile app registers this scheme and opens
    | the reset-password screen when it receives a matching link.
    |
    */

    'url_scheme' => env('PASSWORD_RESET_URL_SCHEME', 'loanmanagement'),

];
