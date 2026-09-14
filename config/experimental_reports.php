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
        'server' => env('RINGCENTRAL_SERVER', 'https://platform.ringcentral.com'),
        'client_id' => env('RINGCENTRAL_CLIENT_ID'),
        'client_secret' => env('RINGCENTRAL_CLIENT_SECRET'),
        'jwt' => env('RINGCENTRAL_JWT'),
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
     * MEASURED 2026-09-14, and the answer is the bad one: THE ACCOUNTS ARE
     * DISJOINT. Asked with the NJ sub-account's own v1 token against the
     * Nationwide one, both directions, `job/get/{uuid}` answers 204 No Content
     * for the other account's job — while NJ answering 200 for its OWN job is
     * the control that makes those 204s readable as "not in this account"
     * rather than "this token reads nothing". The two books share zero UUIDs.
     *
     * So Nationwide is not a parent view; it is a tenth book. One entry here
     * covers one account and NINE ARE MISSING, which is exactly the partial
     * answer that looks complete. Hence `accounts()` returning names.
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
     * ...AND THAT CORRECTION WAS ITSELF HALF WRONG. Measured 2026-09-14:
     *
     *     v2 key  -> api/v1/{key}/job/all/   403
     *     v1 token-> api/v1/{tok}/job/all/   200
     *
     * The Permissions step belongs to the **v2** wizard. The tokens this report
     * actually runs on are **v1** — a pre-existing Token/Secret pair per
     * account, on a tab that offers no permissions control at all, only
     * "Request New Credentials". So "grant Jobs, read only" is not something
     * that can be done to the credentials in use; it describes a different
     * credential system that returns 403 against the endpoint here.
     *
     * A v2 jobs endpoint does exist — `api/v2/job/all/` answers with JSON
     * (`{"flag":false,"msg":"Invalid Auth"}`) rather than the HTML sign-in page
     * that every other guessed v2 path returns — but the v2 key was refused
     * there in all four auth shapes tried (in the path, Bearer, x-api-key,
     * apikey). So whether a scoped key can drive this report is UNKNOWN, not
     * no; finding out means finding v2's auth scheme, and v2 is alpha.
     *
     * Until then the honest statement is: these tokens are unscoped account
     * credentials, and that is a property of Workiz's v1 system rather than a
     * choice anybody made.
     *
     * The lesson is the one this module keeps relearning, and it survived being
     * learned once: a page's own summary is a claim, not a reading. I quoted
     * the blurb as a measurement, corrected it with a reading of the wizard,
     * and the correction was still about the wrong credential system.
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
