<?php

/**
 * Deployment configuration — §2.1 category 2.
 *
 * Explicitly NOT tenant settings and NOT release flags: these are facts about
 * where this copy of the software is installed, which is why they live in env
 * rather than in the database. §2.1 is emphatic that the six categories never
 * get merged, and the test for this one is "would a second installation need a
 * different value" — every entry here would.
 *
 * The standing rule is that nothing is hardcoded. Hosts were, in
 * PasswordResetLink, which meant a staging copy of this app would have emailed
 * production links to real people.
 */
/*
 * The two faces of the same API (§4.2, revised).
 *
 * A reset link has to land somebody on the sign-in page that is theirs. Both
 * hosts serve the same API, so the wrong one would still work — it would just
 * drop a customer into the control plane's login, which is the kind of thing
 * that gets reported as a security incident.
 *
 * Bound to a variable rather than written straight into the array so the TLS
 * check below can default to it. Repeating the literal there would be a second
 * copy of a value that must not drift.
 */
$hosts = [
    'customer' => env('EZBIZI_HOST_CUSTOMER', 'https://app.ezbizi.com'),
    'staff' => env('EZBIZI_HOST_STAFF', 'https://dev.ezbizi.com'),
];

return [

    'hosts' => $hosts,

    /*
     * Self-serve signup. Off until mail can verify an address (TODO #1 / #22).
     * An open front door with no mailbox check is a way to fill the database
     * from a script; invitations remain the way in.
     *
     * Tests turn it on in phpunit.xml.
     */
    'signup' => [
        'enabled' => filter_var(env('EZBIZI_SIGNUP_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
     * The certificate, as served — not as stored.
     *
     * nginx reads its certificates once and keeps them in memory for the life of
     * the master process, so "certbot renewed the file" and "visitors are getting
     * a valid certificate" are two different facts, and only the second one is
     * the product's problem. This names the host to ask.
     *
     * One certificate covers both hostnames, so asking either answers for both;
     * the customer host is the default because it is the one whose outage is a
     * customer's outage.
     */
    'tls' => [
        // `?: $hosts['customer']` on the end, because parse_url returns null for
        // a schemeless value — and a null host builds `ssl://:443`, which fails
        // to connect and reports a permanently red certificate with a message
        // blaming the certificate rather than the configuration.
        'host' => env('EZBIZI_TLS_HOST')
            ?: (parse_url($hosts['customer'], PHP_URL_HOST) ?: $hosts['customer']),
        'port' => (int) env('EZBIZI_TLS_PORT', 443),

        // Short on purpose: this runs inside a staff page request, and a health
        // screen that hangs waiting for a socket is reporting nothing while
        // looking like it is reporting something.
        'timeout' => (int) env('EZBIZI_TLS_TIMEOUT_SECONDS', 5),

        // certbot starts renewing at 30 days out and retries twice a day, so a
        // healthy installation never gets near this. Below it means either the
        // renewal or the nginx reload after it has been failing for over a week,
        // which is still a fortnight of warning before anything breaks.
        'min_days' => (int) env('EZBIZI_TLS_MIN_DAYS', 21),
    ],

    /*
     * What a tenant may attach to a record (§9, §11).
     *
     * Category 2 rather than a tenant setting, and the test §2.1 gives decides
     * it: would a second installation need a different value? Yes — the ceiling
     * has to sit under this box's `post_max_size`, and that differs between the
     * app server and a laptop. An organization does not get to raise it, because
     * raising it past what PHP will carry produces a refusal nobody can read.
     * See App\Support\Files\UploadLimits, which clamps this to what PHP will
     * really accept and quotes the resulting number in the refusal.
     */
    'attachments' => [
        // 20MB. Big enough for a scanned multi-page contract or a photograph
        // straight off a phone, small enough that one upload is not an outage.
        'max_kilobytes' => (int) env('EZBIZI_ATTACHMENT_MAX_KB', 20480),

        /*
         * Extensions, checked against the DETECTED content type by the `mimes`
         * rule — so this is not a list a filename can talk its way past.
         *
         * Deliberately no `svg`, and no `html`. Both are documents a browser
         * executes script from, and the one thing an attachment endpoint must
         * never become is a way to serve attacker-authored script from this
         * app's own origin. FileStore sends `nosniff` and `Content-Disposition:
         * attachment` as well; this is the layer that means neither has to hold
         * alone.
         */
        'extensions' => array_map('trim', explode(',', (string) env(
            'EZBIZI_ATTACHMENT_EXTENSIONS',
            'pdf,jpg,jpeg,png,webp,heic,heif,doc,docx,xls,xlsx,csv,txt,rtf',
        ))),
    ],

    /*
     * Where the nightly backup leaves its verdict.
     *
     * Read by the scheduler so a failed or stale backup becomes a notification
     * inside the product. The lesson that produced this: for seven hours every
     * check I ran was a status code, and not one of them touched a row.
     */
    'backups' => [
        'status_file' => env('EZBIZI_BACKUP_STATUS', storage_path('app/backup-status.json')),

        // Older than this and the backup is treated as failed, whatever the file
        // says. A job that stops running writes nothing at all, so "the last
        // result was a success" is exactly what a broken timer looks like.
        'max_age_hours' => (int) env('EZBIZI_BACKUP_MAX_AGE_HOURS', 30),

        // Who hears about it. Empty means nobody is emailed and the alert lives
        // only in the audit log and the Development screen — which is better
        // than nothing and worse than an email.
        'alert_to' => env('EZBIZI_BACKUP_ALERT_TO'),
    ],

    /*
     * The other box, watching this one (TODO #23).
     *
     * `/up` is nginx + PHP + MySQL. It is not backups, mail or TLS — those stay
     * on the staff Health screen. A missing verdict is a failure: a timer that
     * has stopped writes nothing, which is exactly what a dead peer looks like.
     *
     * No default URL. `www.ezbizi.com` is THIS box (100.24.188.241), so a
     * fallback that named it made the watchdog ask itself whether it was alive.
     * Production sets `EZBIZI_PEER_URL=https://panel.triplexhost.net`. An unset
     * value is reported as missing, the same way MailStatus names an empty key.
     */
    /*
     * Where scripts/lib/release-status.sh appends a release's progress, one
     * JSON line per stage boundary, over the deploy's own ssh. Outside storage/
     * because the deploy user writes it and www-data owns storage. Read by the
     * Development tab's Releases screen (StaffReleaseStatusController).
     */
    'release_status_file' => env('EZBIZI_RELEASE_STATUS_FILE', '/var/www/ezbizi/release-status.jsonl'),

    'peer' => [
        'status_file' => env('EZBIZI_PEER_STATUS', storage_path('app/peer-status.json')),
        'url' => env('EZBIZI_PEER_URL'),
        'max_age_minutes' => (int) env('EZBIZI_PEER_MAX_AGE_MINUTES', 15),
    ],

    /*
     * ─────────────────────────────────────────────────────────────
     *  WATCHING THE SHARED INBOXES (§5.5's `email.received`)
     * ─────────────────────────────────────────────────────────────
     *
     * ── WHY THIS IS CATEGORY 2 AND NOT AN ORGANIZATION SETTING ───
     *
     * §2.1's test is "would a second installation need a different value", and
     * for `enabled` the answer is not merely yes — a wrong answer is dangerous.
     *
     * A sandbox is built by `scripts/sandbox.sh` from a COPY of the production
     * database, mailbox rows and encrypted credentials included. If watching
     * were a tenant setting it would be copied in that dump, switched on, and
     * the sandbox would start opening real customers' mail on a timer — raising
     * events in a database nobody reads, spending the customer's API quota, and
     * hammering their mail server from a second address. That is the exact shape
     * of the two incidents that script's header records, and a deployment
     * setting is immune to it: it comes from env, and a sandbox has its own.
     *
     * The person who needs to switch it OFF is also not a tenant administrator.
     * It is whoever is on call when a mail provider starts rate-limiting the
     * whole installation, and they need one lever that stops all of it at once
     * without touching anybody's data.
     *
     * ── THE TENANT'S OWN OFF SWITCH ALREADY EXISTS ───────────────
     *
     * And it is a better one than a checkbox in settings would be: an
     * organization with no live automation on `email.received` is not polled at
     * all. Switching the rule off stops the polling, switching it on starts it,
     * and there is no second control that can disagree with the first about
     * whether anything is happening. See App\Console\Commands\WatchMailboxes.
     */
    'mail_watch' => [
        /*
         * On by default, because a feature that has to be discovered before it
         * works is one most installations never turn on — and this is the
         * trigger the Email module exists for. A sandbox switches it off; see
         * above for why that is where the decision belongs.
         */
        'enabled' => filter_var(env('EZBIZI_MAIL_WATCH', true), FILTER_VALIDATE_BOOL),

        /*
         * How often ONE mailbox is looked at. Five minutes.
         *
         * The arithmetic that decides it is the IMAP side, not the Gmail one. A
         * Gmail poll is one HTTPS request to a service built for it. An IMAP
         * poll is a TCP connection, a TLS handshake, a LOGIN, an EXAMINE and a
         * LOGOUT against a mail server that is very often a small box run by
         * somebody's hosting provider, and connection churn is what those
         * rate-limit first — Dovecot ships with a per-IP connection limit in
         * single figures.
         *
         * At a hundred mailboxes: every minute would be a hundred sign-ins a
         * minute, 1.7 a second, for ever, from one address. Five minutes makes
         * it twenty a minute — one every three seconds — spread across whichever
         * mailboxes happen to be due, because the sweep runs every minute and
         * polls only what has come round. Nothing arrives as a five-minute
         * spike.
         *
         * What it costs is latency: a message is announced up to five minutes
         * after it lands, plus up to a minute in the outbox. Against the rules
         * anybody actually writes — tell the branch a customer has written in,
         * chase a thread nobody has answered in two days — six minutes is
         * invisible. Against the alternative it is cheap: being TOLD instead of
         * asking means a public webhook endpoint and a subscription that expires
         * and has to be renewed, which is infrastructure rather than a setting.
         */
        'interval_seconds' => (int) env('EZBIZI_MAIL_WATCH_INTERVAL', 300),

        /*
         * How many mailboxes one sweep will take, and for how long.
         *
         * The limit bounds the work; the deadline bounds the WAIT, which is the
         * one that matters — twenty-five mailboxes are quick unless one of them
         * is a mail server that answers slowly, and twenty-five slow ones in
         * series would run past the next minute's tick and into the overlap
         * lock. So a sweep stops STARTING mailboxes after this many seconds and
         * leaves the rest to the next run, which loses nothing: they are still
         * due, and they are ordered by how overdue they are.
         *
         * Twenty-five per minute drains a hundred due mailboxes in four minutes,
         * which is inside one cadence — so a hundred is comfortably within what
         * this configuration serves without any mailbox slipping behind.
         */
        'per_sweep' => (int) env('EZBIZI_MAIL_WATCH_PER_SWEEP', 25),

        'sweep_seconds' => (int) env('EZBIZI_MAIL_WATCH_SWEEP_SECONDS', 45),

        /*
         * How many arrivals one poll of one mailbox will announce.
         *
         * A ceiling on the batch rather than on the day: what is not announced
         * now is announced by the next poll, because the mark advances only past
         * what was announced. Fifty means a mailbox that has taken a two hundred
         * message mailshot is caught up within four cadences rather than
         * inserting two hundred outbox rows in one transaction.
         *
         * Gmail honours this loosely — its position can only be taken where its
         * history paging stops, and App\Support\Email\GmailMailboxReader says why.
         */
        'per_poll' => (int) env('EZBIZI_MAIL_WATCH_PER_POLL', 50),

        /*
         * How long a position may go unconfirmed before it is abandoned.
         *
         * Twenty-four hours. Below that, a gap is an outage — a box rebooted, a
         * deploy, a scheduler that stopped for an afternoon — and the mail that
         * arrived meanwhile really did arrive and is worth announcing late.
         * Beyond it, the position is describing a day nobody was watching, and
         * announcing a day of mail at once as "just arrived" would be false in
         * the one way this feature cannot afford: every rule would fire on
         * everything at once, hours after anybody could act on it.
         *
         * So a stale mark is dropped and a new one taken in silence — the same
         * thing that happens on the first sight of a mailbox, for the same
         * reason. It is stated in the log so a gap is never invisible.
         */
        'stale_after_seconds' => (int) env('EZBIZI_MAIL_WATCH_STALE_AFTER', 86400),

        /*
         * The longest the backoff will wait after repeated failures.
         *
         * Six hours. The failures that reach the backoff are the ones that do
         * NOT mean the mailbox is broken — a rate limit, a mail server having a
         * bad afternoon — so waiting is exactly the right response and there is
         * no person to tell. A failure that DOES mean broken takes the mailbox
         * out of the sweep entirely and raises `email.mailbox_broken`, which is
         * the path that reaches somebody.
         */
        'max_backoff_seconds' => (int) env('EZBIZI_MAIL_WATCH_MAX_BACKOFF', 21600),
    ],

    /*
     * The robot check on every website form and chat start (Jose, 2026-10-06:
     * "Activate captcha on all websites for anti spam web forms"). Always on
     * in production; the test suite turns it off so a test that posts a form
     * is not also a test of the puzzle, and WebFormOptionsTest turns it back
     * on to test exactly that.
     */
    // Leads read by Grok (sorting, filling a missing name/ZIP, suggested
    // replies). Off until /privacy/ names Leads text going to xAI.
    'leads_ai_grok' => filter_var(env('LEADS_AI_GROK', false), FILTER_VALIDATE_BOOL),

    'web_form_captcha' => filter_var(env('EZBIZI_WEB_FORM_CAPTCHA', true), FILTER_VALIDATE_BOOL),

    /*
     * Cloudflare Turnstile, the box a visitor ticks (Jose, 2026-10-08: "an
     * actual captcha", the proof of work alone is one any AI clicks through).
     * Cloudflare account Development@ezbizi.com; keys in Credentials/
     * "Cloudflare Turnstile.txt". The free plan takes 10 hostnames a widget and
     * a root domain covers its subdomains, hence four. A site whose domain is in
     * neither keeps the proof of work alone (Turnstile::for).
     */
    'turnstile' => [
        ['site' => env('TURNSTILE_SITE_1', ''), 'secret' => env('TURNSTILE_SECRET_1', ''), 'domains' => [
            'a-1chimney.com', 'a-1chimneysweep.com', 'a-1garagedoorrepair.com', 'air75hvac.com', 'angisweep.com',
            'calla1.com', 'chimneystar.com', 'crestln.com', 'everdoors.com', 'premiergaragerepair.net',
        ]],
        ['site' => env('TURNSTILE_SITE_2', ''), 'secret' => env('TURNSTILE_SECRET_2', ''), 'domains' => [
            'roofirst.com', 'speedychimneysweep.com', 'speedygaragedoors.com', 'us-garagedoorrepair.com', 'usgaragerepair.net',
            'aircooless.com', 'ace-airduct.com', 'a1acrepair.com', 'a1airduct.com', 'mallocksmith.com',
        ]],
        // The sites paired on 2026-10-08 (Jose: "yes finish the captcha coverage").
        ['site' => env('TURNSTILE_SITE_3', ''), 'secret' => env('TURNSTILE_SECRET_3', ''), 'domains' => [
            'socosmeticsusa.com', 'energysavesolutionstx.com', 'acefireplaceservices.com', 'chimneysweepclevelandoh.us', 'calla-1.com',
            'acegarage-doors.com', 'a1garagedoors-ga.com', 'emergencyplumbingcorp.com', 'topuproofing.com', 'locksmithpatrol.com',
        ]],
        ['site' => env('TURNSTILE_SITE_4', ''), 'secret' => env('TURNSTILE_SECRET_4', ''), 'domains' => [
            'a1-kitchens.com', 'achdutisraeldallas.org', 'leshemshamayim.org', 'americanfriendsofleshemshamaim.org',
        ]],
    ],

    // Who public lookup services (OpenStreetMap, Census) can contact about our traffic.
    'contact_email' => env('EZBIZI_CONTACT_EMAIL', 'development@ezbizi.com'),
];
