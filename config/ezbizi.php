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
return [

    /*
     * The two faces of the same API (§4.2, revised).
     *
     * A reset link has to land somebody on the sign-in page that is theirs.
     * Both hosts serve the same API, so the wrong one would still work — it
     * would just drop a customer into the control plane's login, which is the
     * kind of thing that gets reported as a security incident.
     */
    'hosts' => [
        'customer' => env('EZBIZI_HOST_CUSTOMER', 'https://app.ezbizi.com'),
        'staff'    => env('EZBIZI_HOST_STAFF', 'https://dev.ezbizi.com'),
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
