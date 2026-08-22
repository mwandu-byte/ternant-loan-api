<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Phone Country Code
    |--------------------------------------------------------------------------
    |
    | Used to normalize customer phone numbers submitted in local format
    | (e.g. starting with a leading 0) into E.164-style international
    | format before they are stored and matched for uniqueness.
    |
    */

    'default_country_code' => env('CUSTOMER_PHONE_DEFAULT_COUNTRY_CODE', '255'),

    /*
    |--------------------------------------------------------------------------
    | Customer Photo Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk (see config/filesystems.php) used to store
    | uploaded customer photographs. Must be a disk capable of producing
    | a usable public URL via Storage::disk(...)->url(...).
    |
    */

    'photo_disk' => env('CUSTOMER_PHOTO_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Customer Photo Max Size (KB)
    |--------------------------------------------------------------------------
    */

    'photo_max_kb' => (int) env('CUSTOMER_PHOTO_MAX_KB', 2048),

];
