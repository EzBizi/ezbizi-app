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

        // Google Workspace Marketplace listing. When set, Email settings
        // offers Admin install instead of (or before) pasting DWD scopes.
        // Empty until the listing exists. Public + Admin-only. See MAIL-SETUP.md.
        'marketplace_listing_url' => env('GOOGLE_MAIL_MARKETPLACE_URL'),
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

    /*
    |--------------------------------------------------------------------------
    | Pinterest, for tenant pin accounts
    |--------------------------------------------------------------------------
    |
    | One OAuth app for the installation, never a per-tenant value. Redirect
    | URI defaults to this application's callback on APP_URL. PinterestOAuth
    | refuses anything that is not that path, so a Host header cannot move it.
    |
    | Trial access is what makes this the first network that can actually
    | connect. Ads scopes are not requested.
    |
    */
    'pinterest' => [
        'client_id'     => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect' => env('PINTEREST_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/pinterest/callback',
    ],

    /*
    | One Meta app for Facebook Pages, Instagram professional, and Threads.
    | Three redirect URIs, registered character for character. No ads, no Messenger.
    */
    'meta' => [
        'client_id'     => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
        'facebook_redirect' => env('META_FACEBOOK_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/facebook/callback',
        'instagram_redirect' => env('META_INSTAGRAM_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/instagram/callback',
        'threads_redirect' => env('META_THREADS_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/threads/callback',
    ],

    'linkedin' => [
        'client_id'     => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/linkedin/callback',
    ],

    'tiktok' => [
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/tiktok/callback',
    ],

    'youtube' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/youtube/callback',
    ],

    'x' => [
        'client_id'     => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/x/callback',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google, for EZ Leads
    |--------------------------------------------------------------------------
    |
    | Two registrations, not one, and deliberately not the Gmail client either.
    |
    | A company with Maps listings and no advertising connects only the first;
    | one whose agency runs its Local Services connects only the second. A
    | single client would ask every customer for both scopes, and `adwords` is
    | the one Google marks sensitive — so sharing would drag the Business
    | Profile half into a verification it does not need.
    |
    | Both live in Google Cloud project `ezbizi-506117`, alongside the Gmail and
    | YouTube registrations. DEPLOYMENT CONFIG (§2.1): one set of credentials
    | per installation, never per tenant — a tenant that could type its own
    | client id would be handing this installation the ability to mint tokens in
    | a project nobody here controls.
    |
    | ── What an owner has to create, because none of it is code ──
    |
    | For Business Profile: an OAuth client, and an approved Business Profile
    | API access application. The application cannot be filed until the
    | applicant has managed a verified profile for sixty days, and approval
    | shows up as quota — 0 QPM means refused, 300 means granted. Enabling the
    | APIs in the console does NOT grant quota.
    |
    | For Google Ads: an OAuth client, a Google Ads manager (MCC) account, and a
    | developer token issued by that manager account — not by the Cloud project,
    | which is the dependency most easily missed. A fresh token is Test level and
    | reaches test accounts only.
    |
    */
    'business_profile' => [
        'client_id'     => env('GBP_CLIENT_ID'),
        'client_secret' => env('GBP_CLIENT_SECRET'),
        'redirect' => env('GBP_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/business-profile/callback',
    ],

    'google_ads' => [
        'client_id'     => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_ADS_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/google-ads/callback',

        // Issued to the MCC, sent as a header on every call. Without it Google
        // answers nothing, which is why the driver refuses to connect rather
        // than letting a customer succeed at consent and then see an empty list.
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),

        // Google retires Ads API versions on a published schedule and a retired
        // one answers 404 to everything. Pinned here so the day it moves is an
        // env change on the box, not a release.
        'version' => env('GOOGLE_ADS_API_VERSION', 'v21'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio platform account (provisioned Phone subaccounts)
    |--------------------------------------------------------------------------
    |
    | One EzBizi Twilio account for the installation, never a per-tenant value.
    | Used only to create subaccounts + API keys when a tenant chooses
    | kind=subaccount. BYO connections use the tenant’s own SID and key.
    | Administered as development@ezbizi.com. Never commit real values.
    |
    */
    'twilio' => [
        'platform_account_sid' => env('TWILIO_PLATFORM_ACCOUNT_SID'),
        'platform_api_key_sid' => env('TWILIO_PLATFORM_API_KEY_SID'),
        'platform_api_secret'  => env('TWILIO_PLATFORM_API_SECRET'),
    ],

];
