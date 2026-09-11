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
        // The JWT flow for a server-side app. A Super Admin creates the app in
        // the RingCentral Developer Console with ReadCallLog and ReadAccounts.
        'server'        => env('RINGCENTRAL_SERVER', 'https://platform.ringcentral.com'),
        'client_id'     => env('RINGCENTRAL_CLIENT_ID'),
        'client_secret' => env('RINGCENTRAL_CLIENT_SECRET'),
        'jwt'           => env('RINGCENTRAL_JWT'),
    ],

    'workiz' => [
        // Workiz puts the token IN THE PATH rather than in a header, which is
        // why it must never be logged: a URL ends up in far more places than a
        // header does.
        'token' => env('WORKIZ_API_TOKEN'),
    ],

];
