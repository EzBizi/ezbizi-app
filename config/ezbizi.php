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
    'staff'    => env('EZBIZI_HOST_STAFF', 'https://dev.ezbizi.com'),
];

return [

    'hosts' => $hosts,

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
        'host'    => env('EZBIZI_TLS_HOST')
            ?: (parse_url($hosts['customer'], PHP_URL_HOST) ?: $hosts['customer']),
        'port'    => (int) env('EZBIZI_TLS_PORT', 443),

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
];
