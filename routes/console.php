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
Schedule::command('ezbizi:expire-impersonations')->everyFiveMinutes()->withoutOverlapping(10);

// The reader half of the automation outbox (5.5). Every minute: the write
// path only ever inserts a row, because dispatching a job from inside the
// business transaction is the race the outbox exists to avoid.
// withoutOverlapping(5), not the default. The default lock lasts 24 HOURS, so
// one non-graceful death of schedule:run while it is held would silently disable
// the whole outbox for a day — the exact failure mode the scheduler unit's own
// header warns about. Five minutes means a stale lock costs one skipped sweep.
Schedule::command('ezbizi:dispatch-automation-events')->everyMinute()->withoutOverlapping(5);

// Backup health (§18 Phase 0 item 5). At 05:00, well after the 03:17 timer, so a
// slow night is not reported as a missing one.
//
// Hourly rather than daily would be noise; daily is enough because the alert is
// about a subsystem that stopped, not about an incident in progress. What makes
// it useful is that a MISSING verdict counts as a failure — see the command.
Schedule::command('ezbizi:check-backups --quiet-when-healthy')->dailyAt('05:00');
