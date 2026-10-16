<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This application is an API-only backend for the SalaryNowNow SPA. There is
| intentionally no default Laravel welcome page or other web UI here.
|
*/

// Minimal health probe. Returns only a bare status flag — no environment,
// version, framework or configuration details are exposed.
Route::get('/up', function () {
    return response()->json(['status' => 'ok']);
})->name('health');
