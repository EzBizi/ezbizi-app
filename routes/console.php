<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit retention (plan §10). Runs nightly; the command reports what it removed.
/*
 * Websites that have stopped fetching their details.
 *
 * Hourly rather than daily, and that is not a contradiction of the day-long
 * threshold: the sweep asks whether a site has been silent for 24 hours, and
 * running it hourly only decides how soon after that somebody hears. Daily
 * would mean an average half-day of extra silence on a site that is no longer
 * collecting enquiries.
 *
 * Cheap to run often because it says each one ONCE — `went_quiet_at` is what
 * stops an hourly sweep becoming an hourly alert.
 */
Schedule::command('ezbizi:web-quiet-sweep')->hourly()->withoutOverlapping(30);

Schedule::command('ezbizi:expire-audit')->dailyAt('03:40');

// Yesterday's RingCentral call log into the Experimental report's cache, so a
// week or a month opens without waiting on RingCentral's rate limit.
Schedule::command('ezbizi:warm-call-log')->dailyAt('02:30')->withoutOverlapping(30);
Schedule::command('ezbizi:watch-missed-calls')->everyTwoMinutes()->withoutOverlapping(5);
// Yesterday's calls AI read as booked with no Workiz job (AiBookedNoJob).
// A-1's Airtable copied into EzBizi for the reports (A1 Leads takeover, 2026-10-16); about 8 s.
Schedule::command('ezbizi:airtable-snapshot')->hourlyAt(17)->withoutOverlapping(10);
// Financials tables linked to Airtable read that copy, ten minutes after it lands.
Schedule::command('ezbizi:financials-airtable-refresh')->hourlyAt(27)->withoutOverlapping(10);
Schedule::command('ezbizi:ai-booked-no-job')->dailyAt('07:40')->timezone('America/New_York')->withoutOverlapping(30);

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

// A sudden jump in errors, crashes or freezes from one app version (2026-10-15).
Schedule::command('ezbizi:check-client-issues')->everyTenMinutes()->withoutOverlapping(5);

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

// The clock half of the engine: rules that run on a schedule rather than on
// something happening. It only RAISES `automation.schedule_reached`; the sweep
// above is still what delivers it, so a scheduled rule and an event-driven one
// travel the same path from that point on.
//
// Every minute, because the finest schedule anyone can set is a minute, and the
// same short overlap lock for the same reason the line above gives — a stale
// 24-hour default lock would silently stop every scheduled rule in the product
// for a day, and nothing would report it.
Schedule::command('ezbizi:dispatch-due-automations')->everyMinute()->withoutOverlapping(5);

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

// Scheduled newsletters. The composer writes a wall time and leaves the send
// `queued`; this is the only thing that turns one into a job.
//
// Every minute, matching the resolution the composer offers. `withoutOverlapping`
// and the claim inside the command are two lines of defence against the same
// thing, and it is worth more here than anywhere else in the app: Mailchimp has
// no idempotency key, so a double release is twelve hundred people receiving the
// same mailing twice.
Schedule::command('ezbizi:send-due-newsletters')->everyMinute()->withoutOverlapping(5);

// Shifts past their scheduled end (design 2026-09-08 §8). The row is the
// state — Extend moves the end and the row stops being due — and this is the
// only thing that closes one without a person or a rule. Every minute
// because the reminder counts down to a minute the person was shown.
Schedule::command('ezbizi:close-ended-shifts')->everyMinute()->withoutOverlapping(5);

// The phone numbers Thumbtack matches calls against, kept level with the
// people actually assigned to each connected business.
//
// Nightly, and a sweep rather than a hook on "user saved", because Thumbtack
// holds its own copy: a hook keeps the two in step only while every write ever
// made succeeded, and one dropped request diverges them silently until a pro
// asks why their van phone shows as an unknown number. This reads their list
// and sends the difference, so it repairs drift instead of adding to it.
Schedule::command('ezbizi:sync-thumbtack-phone-numbers')->dailyAt('04:40')->withoutOverlapping(30);
// Each Thumbtack lead's price and refund state, for Reports' Thumbtack tab (2026-10-13).
Schedule::command('ezbizi:thumbtack-lead-costs')->dailyAt('04:50')->withoutOverlapping(30);

// A Thumbtack grant left unused for ~15 days is refused; exchange it daily.
Schedule::command('ezbizi:thumbtack-keep-alive')->dailyAt('04:30')->withoutOverlapping(10);

// Media handed to Instagram, Threads or Facebook to fetch, and not cleaned up
// because the worker died between the fetch and the `finally` that deletes it.
//
// Hourly is deliberately slower than the hour a handoff is signed for: this
// only ever finds files that already stopped working, so racing it against a
// live post would be the only way to make it dangerous.
/*
 * Every minute - Jose, 2026-09-28: "set it to 1 minute then", matching A1
 * Leads, which polls LSA every minute since its own Standard Access.
 *
 * It was every five minutes while Google Ads Basic Access capped the whole
 * Cloud project at 15,000 operations a day: at one minute an account costs
 * 1,440 calls a day, so Basic could carry about ten. Standard Access (approved
 * 2026-09-26) has no daily ceiling; the per-second limits still apply, and the
 * command still stops a run after three quota refusals in a row.
 *
 * A run that outlasts its minute is not overlapped - the next one skips.
 */
Schedule::command('ezbizi:sync-leads')->everyMinute()->withoutOverlapping(10);

/*
 * EVERY MINUTE, because Jose asked for constant sync — and affordable only
 * because the window is small.
 *
 * Workiz refuses with 429 "Account reached Api Quotas". Measured on A-1's own
 * live sync 2026-09-23: 26 refusals in one day at a TWO-minute cadence with a
 * thirty-day window. The quota belongs to the Workiz ACCOUNT, so until A1
 * Tools' sync is switched off both systems spend the same allowance on the
 * same nine accounts.
 *
 * The command's default window is two days, which is usually one request per
 * branch per run. That is what makes a per-minute tick cost less than A1's
 * two-minute one, not more.
 */
// NOT runInBackground(): the scheduler is a systemd oneshot unit with
// KillMode=control-group, so a background child is KILLED the moment
// schedule:run exits. Measured 2026-09-28: with it, this died after three of
// nine accounts every run for 90 minutes.
Schedule::command('ezbizi:sync-workiz')->everyMinute()->withoutOverlapping(5);

/*
 * HOURLY, and deliberately not beside the job sweep.
 *
 * A roster changes when somebody is hired; a job status changes all day.
 * Reading nine accounts every minute for the roster would spend the same
 * Workiz quota that refuses the job sweep with 429. A1 Tools reached the same
 * split — their team sync is on a slower cron than their job sync.
 */
Schedule::command('ezbizi:sync-workiz-team')->hourly()->withoutOverlapping(10);

/*
 * Invoices, payments, estimates, time off, events, timesheets, custom fields
 * and the price book (v2, 2026-10-06). Every ten minutes: each run reads only
 * what changed, about eight calls a branch, well under the key's burst limit.
 */
Schedule::command('ezbizi:sync-workiz-records')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('ezbizi:sync-workiz-calls')->everyTenMinutes()->withoutOverlapping(30);
// Workiz's own Payments report, through each tenant's signed-in web session.
Schedule::command('ezbizi:workiz-web-payments')->everyThirtyMinutes()->withoutOverlapping(30);
// A newly connected Workiz account's whole history, paced and resumable.
Schedule::command('ezbizi:backfill-workiz --pending --since=2000-01-01 --pages=20 --pause=5')->everyFiveMinutes()->withoutOverlapping(60);

/*
 * Who booked each new job, which only v1 says (Reports, 2026-10-06): a few
 * v1 calls a branch every five minutes, for the jobs booked since.
 */
Schedule::command('ezbizi:workiz-fill-creators')->everyFiveMinutes()->withoutOverlapping(10);

// Each new Workiz client's v2 id, the only way v2 reads its notes: a page a branch.
Schedule::command('ezbizi:sync-workiz-client-ids')->everyTenMinutes()->withoutOverlapping(10);

/*
 * EVERY MINUTE - Jose, 2026-09-28: "make jobs and customer update every 1 min
 * as well, as well as leads". It was hourly, to spare the Workiz quota the job
 * sweep shares. Measured by the Workiz session since 0.0.0268: one 429 in
 * three days at four calls per account per minute; a run here adds one call
 * per branch (today's leads since the newest one held), and every 429 is
 * logged as "Workiz sync: ..." if that stops fitting.
 *
 * The FIRST run against a branch imports nothing and only marks where now was
 * (Jose, 2026-09-23: *"we don't need the past stuff, just start from now"*), so
 * turning this on does not pull thirty-one thousand historical rows.
 */
Schedule::command('ezbizi:import-workiz-leads')->everyMinute()->withoutOverlapping(10);

/*
 * THE ONLY WORKIZ SCHEDULE THAT SPENDS NO QUOTA, which is why it may run often.
 *
 * Three entries above this one call Workiz: the job sweep every minute, the
 * team roster hourly, the lead import hourly. They share ONE allowance, because
 * the 429 is `"Account reached Api Quotas"` and the account is the Workiz
 * account — and A1 Tools is still spending the same allowance on the same nine
 * accounts until its own sync is switched off.
 *
 * This one touches no API at all. The mirror is already local, and a Workiz
 * client is derived from the contact block on a job rather than fetched,
 * because Workiz has no client endpoint. So the cost here is a walk of our own
 * tables and nothing else.
 *
 * EVERY MINUTE, Jose, 2026-09-28: "make jobs and customer update every 1 min
 * as well". Affordable because a pass reads only the mirror rows written since
 * the last one; walking all of them took most of an 80-second pass.
 *
 * It only ever INSERTS. Re-running is safe by construction - a client that
 * already names a customer and a job that already names a job are skipped - so
 * the link is the record of what has been projected, and nothing a person
 * corrected in EzBizi is ever reverted.
 */
// NOT runInBackground(), for the same reason: killed with schedule:run, it
// never finished a pass and its own lock stayed held for an hour each time.
Schedule::command('ezbizi:project-workiz')->everyMinute()->withoutOverlapping(10);

/*
 * New zips from new bookings (Jose, 2026-09-28): a job booked in Workiz for
 * Tampa at a zip Tampa has never had puts that zip on Tampa, as A1 Tools' sync
 * does. Reads only our own tables, so it spends no Workiz quota. It adds zips
 * and never moves one a person set, and it never creates a service area --
 * A1 did, and Jose called that a flaw. See SchedulingBuildZipMap.
 */
Schedule::command('scheduling:build-zip-map')->everyFiveMinutes()->withoutOverlapping(10);

/*
 * The overbooking warning (Jose, 2026-09-29): each morning, raise
 * `scheduling.overbooking_found` where a service area is overbooked or fully
 * booked in the next 7 days. The email itself is an automation on it.
 */
Schedule::command('scheduling:warn-overbooking')->dailyAt('07:00')->timezone('America/New_York');
// job.estimate_aging, once a day (Jose's Project Management rule 2, 2026-10-21).
Schedule::command('jobs:emit-estimate-aging')->dailyAt('08:10')->timezone('America/New_York')->withoutOverlapping(60);

// A month before a membership year ends (Customers › Memberships).
Schedule::command('memberships:remind-renewals')->dailyAt('09:00')->timezone('America/New_York');

/*
 * Scheduled weekly patterns become the area's pattern on the day they start
 * (Eve, 2026-09-29). Hourly, so a date that turns at midnight in any
 * organization's clock is picked up within the hour; availability already
 * reads the version before this runs. No Workiz calls.
 */
Schedule::command('scheduling:apply-scheduled-changes')->hourly()->withoutOverlapping(10);

Schedule::command('ezbizi:expire-social-handoffs')->hourly()->withoutOverlapping(10);

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

// Monitoring (2026-08-28-monitoring-pc-specs, task 6). Every five minutes so a
// device that stops reporting is caught soon after the default 180-second
// (monitoring.offline_after_seconds) threshold passes, without scanning on
// every request the way the heartbeat does.
Schedule::command('ezbizi:dispatch-monitoring-device-offline')->everyFiveMinutes()->withoutOverlapping(10);

// Screenshot retention (MonitoringScreenshot::RETENTION_DAYS). Its own
// command rather than a `window()` policy read by ezbizi:expire-data above,
// because that command deletes with a raw query that never fires the model
// event a JPEG's removal depends on — see MonitoringScreenshot::retention()
// and ScreenshotPruner. At 04:10: after ezbizi:expire-data (03:50) so the two
// nightly jobs don't contend for the same monitoring tables' locks.
Schedule::command('ezbizi:prune-screenshots')->dailyAt('04:10')->withoutOverlapping();

// Stale-device retention (DevicePruner::RETENTION_DAYS). monitored_devices is
// one-current-row-per-device, never a log, so nothing trims it on its own —
// every retired machine and one-off browser session leaves a row forever. This
// sweeps a device silent past the window, taking its screenshots (files THEN
// rows), its live frame and its activity with it. At 04:30: after
// ezbizi:prune-screenshots (04:10) and clear of ezbizi:phone-usage (04:20), so
// the two monitoring prunes never contend for the same tables' locks.
Schedule::command('ezbizi:prune-devices')->dailyAt('04:30')->withoutOverlapping();

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

// Field: lost-signal alerts (the one fact no incoming point can announce) and
// geocoding the next days' job addresses, so arrival checks never wait on Google.
// NOT runInBackground(): the scheduler is a systemd oneshot unit with
// KillMode=control-group, so a background child is killed the moment
// schedule:run exits, and a lost-signal alert could die mid-sweep (2026-09-28).
Schedule::command('ezbizi:field-sweep')->everyMinute()->withoutOverlapping(5);

// Every Google Ads account's Local Services certification (licence, insurance,
// background check), once a morning before the day's calls - an account that
// lapses stops showing ads and nobody is told.
Schedule::command('ezbizi:ads-certifications')->dailyAt('07:45')->timezone('America/New_York')->withoutOverlapping(30);
// Every enabled account under a connected Google Ads manager connected, and
// which rows are managers, kept true daily (Jose, 2026-10-12: the Ads report
// and Leads should agree as accounts are added to the managers).
Schedule::command('leads:adopt-manager-clients')->dailyAt('07:30')->timezone('America/New_York')->withoutOverlapping(30);

// The nightly Same-Day Reports on WhatsApp (docs/REPLACING-RODRIGO-BOT.md),
// after Workiz's evening sync; the second run catches a branch that failed.
Schedule::command('ezbizi:whatsapp-same-day-reports')->dailyAt('21:05')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('ezbizi:whatsapp-same-day-reports')->dailyAt('21:35')->timezone('America/New_York')->withoutOverlapping(30);
// Weekly (Wednesday, once the bookkeeper's P&L week is in) and monthly (the
// 2nd) PDFs go to the approver first; the noon run retries a failed one.
Schedule::command('ezbizi:whatsapp-period-reports week')->weeklyOn(3, '08:00')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('ezbizi:whatsapp-period-reports week')->weeklyOn(3, '12:00')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('ezbizi:whatsapp-period-reports month')->monthlyOn(2, '09:00')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('ezbizi:whatsapp-period-reports month')->monthlyOn(2, '13:00')->timezone('America/New_York')->withoutOverlapping(30);
// Facebook Ads spend and results for Reports' Meta tab; the last 3 days, which
// Meta still revises. A new connection backfills itself.
Schedule::command('ezbizi:meta-ads-sync')->dailyAt('05:30')->timezone('America/New_York')->withoutOverlapping(120);
// AI suggests each new campaign's category (SuggestMetaCategories); a person confirms.
Schedule::command('ezbizi:suggest-meta-categories')->dailyAt('06:15')->timezone('America/New_York')->withoutOverlapping(30);
// Grok reads new leads: sorting, and a name/ZIP a call gave (Jose, 2026-10-09).
Schedule::command('ezbizi:leads-read')->everyTwoMinutes()->withoutOverlapping(15);
// RingCentral calls to the business lines into Leads, never an LS call twice (Jose, 2026-10-15).
Schedule::command('ezbizi:leads-ringcentral')->everyTwoMinutes()->withoutOverlapping(15);
// Calls and texts to EzBizi's own lead lines: EZ Calls and EZ Texts (Jose, 2026-10-16).
Schedule::command('ezbizi:leads-ez')->everyMinute()->withoutOverlapping(10);
// A Google call filed under another person's message lead, moved to its own (2026-10-16).
Schedule::command('ezbizi:leads-call-owner')->everyFifteenMinutes()->withoutOverlapping(15);
// Local Services accounts to their Business Profile listing; renamed when it moves (2026-10-19).
Schedule::command('ezbizi:leads-link-profiles')->dailyAt('05:40')->timezone('America/New_York')->withoutOverlapping(30);
// Business Profile reads (GbpSync); A-1's on Rodrigo's interim grant (2026-10-15). No writes.
Schedule::command('ezbizi:gbp-sync')->dailyAt('05:10')->timezone('America/New_York')->withoutOverlapping(60);
Schedule::command('ezbizi:gbp-sync --reviews')->cron('40 */6 * * *')->withoutOverlapping(120);
// Complaints from bad reviews, Bad Ratings and complaint calls (2026-10-20).
Schedule::command('ezbizi:complaints-intake')->everyMinute()->withoutOverlapping(5);
// Google's daily counts per listing, the last ten days re-read (2026-10-20).
Schedule::command('ezbizi:gbp-performance-sync')->dailyAt('05:40')->timezone('America/New_York')->withoutOverlapping(60);
// The day on Google, for each organization's rules (A1 Leads takeover, 2026-10-19).
Schedule::command('ezbizi:gbp-daily-digest')->dailyAt('07:00')->timezone('America/New_York')->withoutOverlapping(30);
// One batch of 4 side by side per run: the scheduler runs a minute's
// commands in sequence, so a long run would hold the others behind it.
Schedule::command('ezbizi:label-call-transcripts --limit=4')->everyTwoMinutes()->withoutOverlapping(15);
// Last week in plain English from Grok (AiWeeklySummary); Automations sends it.
Schedule::command('ezbizi:ai-weekly-summary')->weeklyOn(1, '07:00')->timezone('America/New_York')->withoutOverlapping(60);
// Coaching scores for answered calls (ScoreReportCalls); off until REPORT_CALL_SCORING.
Schedule::command('ezbizi:score-report-calls')->everyTenMinutes()->withoutOverlapping(15);
// Grok's daily and weekly read per employee for the Scoring tab (AiPersonReviews).
Schedule::command('ezbizi:ai-person-reviews daily')->dailyAt('06:30')->timezone('America/New_York')->withoutOverlapping(30);
Schedule::command('ezbizi:ai-person-reviews weekly')->weeklyOn(1, '06:45')->timezone('America/New_York')->withoutOverlapping(30);
// Grok failing for 30 minutes mails the ops address (CheckGrokHealth).
Schedule::command('ezbizi:check-grok')->everyFifteenMinutes();
// Rodrigo's 06:30 Daily Account Review (docs/REPLACING-RODRIGO-BOT.md row D).
Schedule::command('ezbizi:ads-daily-review')->dailyAt('06:30')->timezone('America/New_York')->withoutOverlapping(30);

// Which job each lead became, by the customer's phone - Workiz jobs arrive
// every minute; a quarter-hour is soon enough for a revenue figure.
Schedule::command('ezbizi:match-leads-to-jobs')->everyFifteenMinutes()->withoutOverlapping(30);

// Call transcription is NOT scheduled here: a run takes minutes and schedule:run
// runs a minute's commands in sequence, so it would hold the lead sync behind
// it. deploy/ezbizi-transcribe.timer runs `ezbizi:transcribe-lead-calls` instead.

// Is every Local Services campaign still showing? Hourly and read-only; a stop
// raises ads.campaign_stopped with who paused it.
Schedule::command('ezbizi:ads-campaign-watch')->hourlyAt(20)->withoutOverlapping(30);

// A customer left waiting 10 minutes for an answer raises lead.reply_overdue,
// once per message, so a rule can warn the dispatchers.
Schedule::command('ezbizi:leads-reply-overdue')->everyMinute()->withoutOverlapping(5);

// Task Board: a task past its due date raises task.overdue, once per task.
Schedule::command('ezbizi:tasks-overdue')->everyFifteenMinutes()->withoutOverlapping(10);

// A customer who went quiet after the automatic first reply raises
// lead.follow_up_due once, so a rule can send the one follow-up.
Schedule::command('ezbizi:leads-follow-up-due')->everyFiveMinutes()->withoutOverlapping(5);

// Yesterday's leads in numbers, for a morning WhatsApp or email rule.
Schedule::command('ezbizi:leads-daily-digest')->dailyAt('12:00')->withoutOverlapping(10);
