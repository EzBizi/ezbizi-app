<?php

/*
 * Bulk-read alert (App\Http\Middleware\DetectBulkReads).
 *
 * One actor — a person, or one API key (an MCP key is one) — reading an
 * unusual number of DISTINCT customer, lead or job records inside one window
 * writes a `security.bulk_read` audit row and tells the organization's
 * administrators once per window.
 *
 * Two counts per actor per window:
 *   records — distinct ids opened (GET customers|leads|jobs/{id} and its sub-paths)
 *   pages   — distinct list URLs asked (GET customers|leads|jobs?…): paging a whole list
 *
 * Roles are keyed by the role's name in snake case ("Remote Dispatcher" →
 * remote_dispatcher). An API key is measured against `api_key`, whatever its
 * owner's role. A role not listed uses `default`.
 *
 * MEASURED 2026-11-17, production, read only, 10-minute windows:
 *
 * People — nginx /var/log/nginx/app.ezbizi.com.access.log* (2026-11-03 → 11-17,
 * 14 days: all the box keeps), 2xx GETs, actor = IP + user agent, role taken
 * from auth.signed_in audit rows when every user signing in from that IP
 * shares one role. An office IP holds several people, so these are UPPER
 * bounds for one person.
 *     distinct records  p99.9 / max: all 39 / 60, Administrator 6 / 6,
 *                       Dispatcher 17 / 17, Remote Dispatcher 26 / 56,
 *                       shared-IP 54 / 60, unattributed 33 / 33
 *     distinct list URLs p99.9 / max: all 52 / 72, Administrator 61 / 72,
 *                       Dispatcher 5 / 5, Remote Dispatcher 11 / 17
 *
 * API keys — audit_events action api_key.request, 30 days (2026-10-20 → 11-17),
 * 2,890 rows, 5 keys. Only 6 windows read any record: 5 of them 1–11 records,
 * one (a Marketing-owned key, 2026-11-17 18:30 UTC) 283 lead records. That
 * one window is the pattern this alert exists for, so the key threshold sits
 * above the ordinary traffic, not above it. Distinct list URLs max 9.
 */
return [
    'window_minutes' => (int) env('BULK_READ_WINDOW_MINUTES', 10),

    'thresholds' => [
        'default' => ['records' => 150, 'pages' => 200],
        'administrator' => ['records' => 150, 'pages' => 200],
        'dispatcher' => ['records' => 150, 'pages' => 100],
        'remote_dispatcher' => ['records' => 150, 'pages' => 100],
        'api_key' => ['records' => 100, 'pages' => 50],
    ],
];
