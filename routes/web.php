<?php

use App\Http\Controllers\QrRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Production nginx serves Flutter at /. This identifies the local API server.
    return response()->json(['service' => 'EzBizi API']);
});

// What a printed QR code opens (Marketing > QR Codes). Public, tenant-less,
// and kept off the session middleware: a scan is a stranger, not a visitor.
Route::get('/q/{code}', QrRedirectController::class)
    ->where('code', '[A-Za-z0-9]{4,16}')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    ]);
