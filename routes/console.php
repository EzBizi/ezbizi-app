<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit retention (plan §10). Runs nightly; the command reports what it removed.
Schedule::command('ezbizi:expire-audit')->dailyAt('03:40');

// Close impersonation sessions past their expiry (§4.2). Every five minutes,
// because the value is a truthful record rather than cutting off access —
// access already stops by itself the moment the expiry passes.
Schedule::command('ezbizi:expire-impersonations')->everyFiveMinutes()->withoutOverlapping();
