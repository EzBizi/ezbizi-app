<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit retention (plan §10). Runs nightly; the command reports what it removed.
Schedule::command('ezbizi:expire-audit')->dailyAt('03:40');

// Everything else's retention (§10), read off each model's own declaration.
//
// Ten minutes after the audit sweep rather than beside it, so the two never
// contend for the same table locks on a busy night — and after, deliberately:
// audit events are the record of what the rest of the system did, so if only one
// of the two gets to finish it should be the one whose backlog grows fastest.
//
// It skips `audit_events` itself, which declares its retention as enforced by the
// command above. Two sweeps deleting from one table on different rules is a bug
// waiting for the night they disagree.
Schedule::command('ezbizi:expire-data')->dailyAt('03:50');

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

// Scheduled social posts. The composer writes a wall time and leaves the
// targets `scheduled`; this is the only thing that turns them into `queued`.
//
// Every minute, because a minute is the resolution the composer offers — a
// slower sweep would make "9:00" mean "some time after 9:00" and the person
// would have no way to know which. `withoutOverlapping` for the reason above
// it: two sweeps meeting on the same row would publish twice, and the claim
// update inside the command is the second line of defence rather than the
// first.
Schedule::command('ezbizi:publish-due-social-posts')->everyMinute()->withoutOverlapping(5);

// Watching the shared inboxes, so `email.received` can fire (§5.5).
//
// Every minute, and that is NOT the cadence. A mailbox is looked at every five
// minutes; this sweep runs every minute and polls only the ones that have come
// round, so a hundred inboxes are spread across the five rather than arriving as
// one spike of a hundred sign-ins. config/ezbizi.php carries the arithmetic and
// the argument for five — in one line, it is the IMAP side that decides it,
// because a poll there is a TCP connection and a TLS handshake against somebody
// else's mail server and connection churn is what those rate-limit first.
//
// The sweep itself is bounded twice, by mailboxes and by seconds, because one
// slow mail server must not be able to push a run past the next minute's tick.
//
// withoutOverlapping(5), not the default. Same reasoning as the outbox above and
// worth repeating for the reason that unit's own header gives: the default lock
// lasts 24 HOURS, so one non-graceful death would silently stop every inbox
// being watched for a day and the only symptom would be automations that stopped
// firing. Five minutes means a stranded lock costs one cycle per mailbox — which
// is exactly what a mailbox's own claim costs when a sweep dies mid-poll, so the
// two failure modes are the same size on purpose.
//
// It inserts outbox rows and dispatches nothing itself, for the reason set out
// above the sweep it feeds.
Schedule::command('ezbizi:watch-mailboxes')->everyMinute()->withoutOverlapping(5);

Schedule::command('ezbizi:dispatch-task-overdue')->hourly()->withoutOverlapping(10);
Schedule::command('ezbizi:dispatch-task-due-reminders')->hourly()->withoutOverlapping(10);
Schedule::command('ezbizi:dispatch-task-notification-intents')->everyMinute()->withoutOverlapping(5);
Schedule::command('ezbizi:expire-task-update-attachment-candidates')->hourly()->withoutOverlapping(10);
Schedule::command('ezbizi:expire-task-import-sessions')->hourly()->withoutOverlapping(10);

// Monitoring (2026-08-28-monitoring-pc-specs, task 6). Every five minutes so a
// device that stops reporting is caught soon after the default 180-second
// (monitoring.offline_after_seconds) threshold passes, without scanning on
// every request the way the heartbeat does.
Schedule::command('ezbizi:dispatch-monitoring-device-offline')->everyFiveMinutes()->withoutOverlapping(10);

// Backup health (§18 Phase 0 item 5). At 05:00, well after the 03:17 timer, so a
// slow night is not reported as a missing one.
//
// Hourly rather than daily would be noise; daily is enough because the alert is
// about a subsystem that stopped, not about an incident in progress. What makes
// it useful is that a MISSING verdict counts as a failure — see the command.
Schedule::command('ezbizi:check-backups --quiet-when-healthy')->dailyAt('05:00');

// Training pass expiry (2026-08-28-training-module, task 10). At 04:10 — after
// the data-expiry sweep at 03:50 and clear of the Twilio cost job at 04:20, so
// none of the three ever contend for the same minute.
//
// This is the sweep that makes the whole "a pass runs out" option worth
// having: it is the only thing that ever looks at `training_attempts.expires_on`
// again after LearnController::submit stamps it, and `training.expired` is
// what lets an organization write "re-assign the ladder guide when the pass
// lapses."
//
// It inserts outbox rows and dispatches nothing itself — same reasoning as
// the watch-mailboxes sweep above and StartUp.md §10: one automation rule
// failing while re-assigning one lapsed pass must never take the sweep down
// for everybody else's.
Schedule::command('ezbizi:expire-training-passes')->dailyAt('04:10');

// Per-company Twilio cost (§5). At 04:20 — after midnight in every US zone the
// platform serves, so "yesterday" is a day Twilio has finished totting up, and
// clear of the 03:17 backup timer and the 05:00 health check.
//
// Daily, and deliberately not more often: the figure only changes once a day.
// The reason to run it at all is that Twilio PRUNES usage records, so a night
// missed is a night nobody can ever get back — which is also why the command
// exits 0 when only some accounts fail. A nightly page over one suspended
// tenant gets muted, and a muted alarm is worse than none.
Schedule::command('ezbizi:phone-usage')->dailyAt('04:20')->withoutOverlapping(30);
