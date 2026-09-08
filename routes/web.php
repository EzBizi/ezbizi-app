<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Production nginx serves Flutter at /. This identifies the local API server.
    return response()->json(['service' => 'EzBizi API']);
});
