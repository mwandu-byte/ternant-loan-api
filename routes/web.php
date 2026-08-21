<?php

use Illuminate\Support\Facades\Route;

// This is a JSON API for a mobile application — there is no compiled
// frontend (the Docker image doesn't build resources/ at all), so this
// route must not depend on the Vite manifest existing.
Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
        'docs' => url('/docs/api'),
    ]);
});
