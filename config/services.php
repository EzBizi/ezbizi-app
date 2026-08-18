<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google, for shared Gmail inboxes
    |--------------------------------------------------------------------------
    |
    | DEPLOYMENT CONFIG (§2.1), and not a setting. There is ONE OAuth client for
    | the whole installation — EzBizi's own registration with Google — and every
    | organization on the box signs in through it. It is not a per-tenant value
    | and must never become one: an organization that could type its own client
    | id and secret would be handing this installation the ability to mint
    | tokens in a Google project nobody here controls, and a support call about
    | a lapsed registration would have as many answers as there are customers.
    |
    | ── This block being present does NOT mean Google works here ──
    |
    | `env()` answers null for a variable nobody has set, and
    | App\Support\Email\GmailMailboxReader::notReadyBecause() reads these keys
    | with `is_string()` — so an installation that has not registered with
    | Google still says so, in a sentence, on the screen where somebody would
    | otherwise be invited to sign in. Adding this block changes nothing about
    | that; it changes where the values are read from when they exist.
    |
    | WHAT AN OWNER HAS TO CREATE, because none of it is code: a Google Cloud
    | project with the Gmail API enabled, an OAuth client of type "Web
    | application", a consent screen, and the redirect URI below registered on
    | that client — Google matches it character for character and refuses
    | anything else.
    |
    | ── The redirect URI is DERIVED, so it cannot be reflected ────
    |
    | It defaults to this application's own callback path on APP_URL rather than
    | being assembled from whatever host a request happens to arrive with.
    | That distinction is the whole of the defence: an OAuth callback whose
    | redirect_uri comes from the request is an open redirector, and an open
    | redirector in a callback is how authorization codes leak.
    |
    | GOOGLE_MAIL_REDIRECT_URI overrides it for an installation whose public
    | origin is not APP_URL — behind a proxy, or on a separate API hostname.
    | App\Support\Email\GoogleOAuth checks whatever comes out of here against
    | its own callback path and refuses to start a sign-in it does not
    | recognise, so a typo here cannot become a redirect to somewhere else.
    |
    */
    'google_mail' => [
        'client_id'     => env('GOOGLE_MAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),

        'redirect' => env('GOOGLE_MAIL_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/email/google/callback',

        // Service account for domain-wide delegation. Impersonates listed
        // Workspace mailboxes. Lives in .env, never on a tenant row.
        'workspace_client_email' => env('GOOGLE_MAIL_WORKSPACE_CLIENT_EMAIL'),
        'workspace_private_key'  => env('GOOGLE_MAIL_WORKSPACE_PRIVATE_KEY'),
        'workspace_client_id'    => env('GOOGLE_MAIL_WORKSPACE_CLIENT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft 365, for shared Outlook inboxes
    |--------------------------------------------------------------------------
    |
    | Same rules as google_mail: one OAuth client for the installation, never a
    | per-tenant value. Redirect URI defaults to this application's callback on
    | APP_URL and MicrosoftOAuth refuses anything that is not that path.
    |
    */
    'microsoft_mail' => [
        'client_id'     => env('MICROSOFT_MAIL_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_MAIL_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/email/microsoft/callback',
    ],

];
