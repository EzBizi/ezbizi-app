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

// Recurring task instances (Tasks Phase 2 design §11), both modes. Its own
// overlap lock, keyed by this command's own signature same as the two
// above — distinct from both, so a stuck run of one never blocks another.
// `on_schedule` series are advanced directly here; `after_completion`
// series relay through the outbox (TaskWriter emits a cheap marker at
// completion — see TaskWriter::recordRecurrenceOnCompletion — and this
// command's other half, TaskRecurrenceWorker::relayPendingCompletions,
// does the actual lock-heavy instance creation, never inside the
// completion's own transaction).
Schedule::command('ezbizi:create-recurring-tasks')->hourly()->withoutOverlapping(10);
Schedule::command('ezbizi:dispatch-task-notification-intents')->everyMinute()->withoutOverlapping(5);
Schedule::command('ezbizi:expire-task-update-attachment-candidates')->hourly()->withoutOverlapping(10);
Schedule::command('ezbizi:expire-task-import-sessions')->hourly()->withoutOverlapping(10);

// Backup health (§18 Phase 0 item 5). At 05:00, well after the 03:17 timer, so a
// slow night is not reported as a missing one.
//
// Hourly rather than daily would be noise; daily is enough because the alert is
// about a subsystem that stopped, not about an incident in progress. What makes
// it useful is that a MISSING verdict counts as a failure — see the command.
Schedule::command('ezbizi:check-backups --quiet-when-healthy')->dailyAt('05:00');
