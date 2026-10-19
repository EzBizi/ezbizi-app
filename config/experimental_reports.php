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
        // The company whose account this is: only a view covering it reads
        // these calls (2026-10-19). Unset, only the organization view does.
        'company_id' => env('REPORT_RINGCENTRAL_COMPANY'),
    ],

    // Transcribing the call report's calls (ezbizi:transcribe-report-calls).
    // OFF until the privacy page says EzBizi transcribes calls - a published
    // privacy claim must be true before the processing it describes starts
    // (deploy desk, 2026-10-06). Turn on with REPORT_CALL_TRANSCRIPTS=true.
    'transcribe' => (bool) env('REPORT_CALL_TRANSCRIPTS', false),

    // AI coaching scores for dispatchers' calls (Jose, 2026-10-12). OFF until
    // the privacy page says so (ac795a4e, published) and dispatchers have been
    // told (Jose: contract clause + one notice). ezbizi:score-report-calls.
    'scoring' => (bool) env('REPORT_CALL_SCORING', false),

    // Labelling transcribed calls with AI, Claude and Grok side by side
    // (Jose, 2026-10-06: "build for both, so i can try both"). OFF until the
    // privacy page names Anthropic and xAI. Each provider runs only with its key;
    // the models are settings so either can change without code.
    'tagging' => [
        'enabled' => (bool) env('REPORT_CALL_TAGGING', false),
        'claude' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('REPORT_TAG_CLAUDE_MODEL', 'claude-opus-5-5'),
        ],
        'grok' => [
            'key' => env('XAI_API_KEY'),
            'model' => env('REPORT_TAG_GROK_MODEL', 'grok-4.6'),
            // Through the CLI instead of the API when services.grok_cli.home
            // is set (App\Support\Ai\GrokCli).
        ],
    ],

    /*
     * ── WORKIZ IS NOT HERE ANY MORE, AND THAT IS THE POINT ──────────
     *
     * It used to be `WORKIZ_API_TOKENS="Nationwide=abc|CT=def"`. Jose ruled it
     * out: "connect them to each of their designated franchises." A label in an
     * env string cannot say which branch a token belongs to, so the report
     * could only ever be organization-wide while the tokens lived here.
     *
     * They are now `social_connections` rows with `scope_type = franchise`,
     * connected per branch through `WorkizConnectController` and read by
     * `WorkizJobs`. Same store Google Ads and GBP use. Deliberately NOT
     * `leads_franchise_id` — that answers where a connection's ENQUIRIES get
     * assigned, which a read-only jobs report never asks.
     *
     * Nothing is left behind as a fallback ON PURPOSE. An env token and a
     * connected branch would be two sources of truth for one question, and the
     * one that wins would be whichever the code happened to check first.
     *
     * What this leaves in the world, for whoever removes the report: ten Workiz
     * v1 account tokens in encrypted `credentials` columns. They cannot be
     * scoped read-only — measured, the per-resource permissions wizard belongs
     * to Workiz's v2 system, whose key is refused by the v1 endpoint this uses —
     * so each of them can delete that branch's jobs. Disconnect them when the
     * report goes.
     */

];
