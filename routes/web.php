<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Central (SaaS) routes — served on vetly-pos.test
|--------------------------------------------------------------------------
| The actual POS application (auth + all features) lives in routes/tenant.php
| and is served per-tenant on {tenant}.vetly-pos.test.
*/

Route::get('/', function () {
    // Tenant subdomains land here too (this route is registered last);
    // send them into the tenant app, keep the SaaS landing for central.
    $isCentral = in_array(request()->getHost(), config('tenancy.central_domains', []), true);

    if (! $isCentral) {
        return redirect('/login');
    }

    return Inertia::render('Welcome', [
        'canLogin' => false,
        'canRegister' => false,
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('central.home');

Route::get('/health', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
    'context' => 'central',
]));
