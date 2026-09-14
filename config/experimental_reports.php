<?php

/*
 * TEMPORARY. This whole file goes when the Experimental report does.
 *
 * Its own file rather than a block in `config/services.php` for two reasons:
 * that file is contested and edited one provider block per module, and this
 * one is meant to be deletable in a single `rm` when EzBizi's own Phone module
 * answers the question RingCentral is being asked here.
 *
 * Nothing is defaulted to a working value. An absent credential must read as
 * "not connected" on the screen rather than as an empty report, because an
 * empty report and a missing connection look identical and mean opposite
 * things — the same failure the Leads module was built to avoid.
 */
return [

    'ringcentral' => [
        // JWT flow, server-side app. Created 2026-09-14 on the A-1 Chimney
        // account as "EzBizi Reports": private, production, scope Read Call Log
        // ONLY. Values in H:/Development/Credentials/, never in git.
        'server'        => env('RINGCENTRAL_SERVER', 'https://platform.ringcentral.com'),
        'client_id'     => env('RINGCENTRAL_CLIENT_ID'),
        'client_secret' => env('RINGCENTRAL_CLIENT_SECRET'),
        'jwt'           => env('RINGCENTRAL_JWT'),
    ],

    /*
     * ── WORKIZ IS ONE TOKEN PER ACCOUNT, NOT ONE TOKEN ──────────────
     *
     * Jose raised it and Workiz says so on its own Developer page: "Each key is
     * specific to a Workiz account, not an individual user." A-1 Chimney runs a
     * main account, "A1 Chimney - Nationwide", plus nine sub-accounts — CT, DMV,
     * GA, IL, MA, NJ, OH, TX, WA — which read as one per state and line up with
     * EzBizi's franchises.
     *
     * So a single token answers for at most one of them, and a report built on
     * one would be silently partial: it returns rows, they are all real, and
     * eight states are missing with nothing saying so. That is the "too small
     * to notice" failure this module keeps meeting.
     *
     * UNTESTED AND WORTH TESTING FIRST: whether the Nationwide key returns
     * sub-account data. If it does, one entry here is enough. If it does not,
     * every state needs its own key. One call answers it, and it should be
     * answered before anybody reads a number off this report.
     *
     * Shape: `WORKIZ_API_TOKENS="Nationwide=abc|CT=def|NJ=ghi"`. One entry is
     * valid. An env var cannot hold an array, and a map keyed by the account's
     * own name means a partial answer can at least SAY which accounts it covers.
     *
     * ── A WORKIZ KEY *CAN* BE SCOPED, DESPITE WHAT THE PAGE SAYS ────
     *
     * CORRECTION, and I had it wrong here for an hour. The Developer page says
     * these credentials give "full access to create, view, update, and delete
     * most data in this account", and I wrote that down as "there is no
     * read-only option". That blurb describes the DEFAULT, not the ceiling: the
     * key-creation wizard has a Permissions step — "Choose full access or set
     * permissions per resource" — with a row per resource (Search, Jobs, Leads,
     * Clients, …) each defaulting to **No access**.
     *
     * So the key this report uses should grant **Jobs, read only**, and nothing
     * else. That is all `WorkizJobs` touches: `job/all`, a GET. "Existing
     * client" is derived from job history rather than from a client lookup, so
     * even Clients is unnecessary.
     *
     * The lesson is the one this module keeps relearning: a page's own summary
     * of what it does is a claim, not a reading. I quoted it as a measurement.
     *
     * The CREDENTIAL is what an attacker gets, not the code that used it — so
     * scoping the key matters even though nothing here writes.
     */
    'workiz' => [
        'accounts' => collect(explode('|', (string) env('WORKIZ_API_TOKENS', '')))
            ->filter()
            ->mapWithKeys(function (string $pair) {
                [$label, $token] = array_pad(explode('=', $pair, 2), 2, null);

                return [trim((string) $label) => trim((string) $token)];
            })
            ->filter()
            ->all(),
    ],

];
