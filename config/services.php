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
    | Amazon Bedrock, for what a model can say about a lead (Leads §AI).
    | Off until all three are set: the same AWS keys as SES, and the model id
    | exactly as the Bedrock console shows it for Claude in this region.
    */
    'bedrock' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'model' => env('BEDROCK_MODEL', ''),
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
        'client_id' => env('GOOGLE_MAIL_CLIENT_ID'),
        'client_secret' => env('GOOGLE_MAIL_CLIENT_SECRET'),

        'redirect' => env('GOOGLE_MAIL_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/email/google/callback',

        // Service account for domain-wide delegation. Impersonates listed
        // Workspace mailboxes. Lives in .env, never on a tenant row.
        'workspace_client_email' => env('GOOGLE_MAIL_WORKSPACE_CLIENT_EMAIL'),
        'workspace_private_key' => env('GOOGLE_MAIL_WORKSPACE_PRIVATE_KEY'),
        'workspace_client_id' => env('GOOGLE_MAIL_WORKSPACE_CLIENT_ID'),

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
        'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
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
        'client_id' => env('PINTEREST_CLIENT_ID'),
        'client_secret' => env('PINTEREST_CLIENT_SECRET'),
        'redirect' => env('PINTEREST_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/pinterest/callback',
        // Trial access only: Pinterest refuses Pins on api.pinterest.com until
        // Standard access, and its sandbox takes only the sandbox token the
        // portal issues to the app owner. Set both to post there (the review
        // demo); remove both once Standard access is granted.
        'sandbox_token' => env('PINTEREST_SANDBOX_TOKEN'),
        'sandbox_board' => env('PINTEREST_SANDBOX_BOARD'),
    ],

    /*
    | One Meta app for Facebook Pages, Instagram professional, and Threads.
    | Three redirect URIs, registered character for character. No ads, no Messenger.
    */
    'meta' => [
        'client_id' => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
        'facebook_redirect' => env('META_FACEBOOK_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/facebook/callback',
        // Facebook Ads (ads_read), its own connect and its own registered URI.
        'facebook_ads_redirect' => env('META_FACEBOOK_ADS_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/facebook-ads/callback',
        // WhatsApp Embedded Signup: a tenant connects its OWN number. The
        // Login for Business configuration carries the scopes (whatsapp_
        // business_management + _messaging) and a never-expiring business
        // system user token, so the dialog takes config_id and no scope.
        'whatsapp_signup_config' => env('META_WHATSAPP_SIGNUP_CONFIG_ID', '1772377890501449'),
        'whatsapp_redirect' => env('META_WHATSAPP_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/whatsapp/callback',
        /*
        | Instagram is its own app inside the same Meta app too - the console
        | calls it EzBizi-IG and gives it an Instagram app id and secret
        | separate from the Facebook ones. Same story as Threads below.
        */
        'instagram_id' => env('META_INSTAGRAM_APP_ID'),
        'instagram_secret' => env('META_INSTAGRAM_APP_SECRET'),
        'instagram_redirect' => env('META_INSTAGRAM_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/instagram/callback',
        /*
        | Threads is its OWN app inside the same Meta app, with its own id and
        | its own secret - the console shows both side by side. Signing a
        | Threads request with the Facebook pair is rejected, so these are
        | separate keys rather than a reuse of the two above.
        */
        /*
        | WhatsApp Business Platform — the Cloud API, on the same Meta app.
        |
        | The access token is a SYSTEM USER token, not the temporary one the
        | dashboard shows next to the test number: that one expires in 24 hours
        | and its expiry looks exactly like Meta refusing the message.
        |
        | The verify token is ours and arbitrary — we invent it, type it into
        | the dashboard, and Meta hands it back on the subscription handshake.
        | It authenticates nothing afterwards; the POST signature does that,
        | using META_APP_SECRET above.
        */
        'whatsapp_version' => env('META_WHATSAPP_VERSION', 'v21.0'),
        'whatsapp_token' => env('META_WHATSAPP_TOKEN'),
        'whatsapp_verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),

        // Messenger and Instagram Direct share one webhook and therefore one
        // verify token. Separate from WhatsApp's on purpose: rotating one
        // subscription must not silently break the other.
        'messages_verify_token' => env('META_MESSAGES_VERIFY_TOKEN'),

        // The ONE WhatsApp number the staff bot answers on (a phone_number_id).
        // Unset means the bot answers nothing - the safe way to fail, since the
        // alternative is a robot replying on lines where a person is expected.
        'whatsapp_bot_number_id' => env('META_WHATSAPP_BOT_NUMBER_ID'),

        'threads_id' => env('META_THREADS_APP_ID'),
        'threads_secret' => env('META_THREADS_APP_SECRET'),
        'threads_redirect' => env('META_THREADS_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/threads/callback',
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/linkedin/callback',
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/tiktok/callback',

        /*
         * Whether THIS EzBizi installation's TikTok app has passed their audit.
         *
         * ── The deadlock this exists to break ────────────────────
         *
         * TikTok has two ways in. Direct Post puts the video on the profile and
         * requires the app to have passed audit. The inbox puts it in the
         * creator's drafts and needs only `video.upload`, from day one.
         *
         * Until 2026-08-28 the composer always took Direct Post, so every
         * publish came back "Please review our integration guidelines at
         * ..." — which names no field and reads like the video was rejected.
         * It was not: the app is not audited, so that endpoint was never going
         * to answer anything else.
         *
         * That is circular, and the circle is the point. Direct Post needs the
         * audit; the audit submission needs a filmed demo of a working publish;
         * the publish cannot work. Defaulting to the inbox breaks it — the
         * demo films a real post reaching a real account, which is what the
         * audit is asking to see.
         *
         * §2.1 category 2: this is a fact about where this copy is installed
         * and how far its app registration has got, not a tenant setting. No
         * organization may answer it differently, and it is not a customer need
         * being answered by a console command — it is our own paperwork. Flip
         * it the day the audit clears; no code change, no release.
         */
        'direct_post' => (bool) env('TIKTOK_DIRECT_POST', false),
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/youtube/callback',
    ],

    /*
    | Thumbtack, for EZ Leads. Endpoints have NO defaults on purpose: their
    | Partner API is gated and the documentation arrives with the credentials.
    | A plausible-looking guessed URL would compile and fail against the real
    | service, which is worse than an empty value that says it does not know.
    | Application PINT-4039.
    */
    /*
     * A1 Leads - the Phase 1 read replica, and a temporary one.
     *
     * A-1 Chimney's Google Business Profile still lives in Rodrigo's A1 Leads
     * while EzBizi has no Business Profile API access of its own (refused
     * 2026-09-22 on the 60-day rule; earliest re-file 26 October). Until then
     * the A1Leads GBP tab reads that system's partner API.
     *
     * LOOPBACK BY DESIGN. A1 Leads runs on this same box bound to 127.0.0.1 and
     * its own capability manifest sets `maySelectInfrastructureAddress: false`,
     * so the base URL is configuration rather than something a tenant or a
     * screen can point somewhere else.
     *
     * The key is a partner token for the identity `phase1:ezbizi`, scoped to
     * `leads:read` and `digest:read` over exactly five GET routes. It is sent
     * as a header, never as `?key=`, because a secret in a query string reaches
     * access logs, Referer headers and exception reports.
     *
     * `receipt` is a root-published, non-secret file naming the installed
     * snapshot generation and when it was captured. Reading it is how the tab
     * can say how old the data is without A1 Leads growing a route for it.
     */
    'a1leads' => [
        'base_url' => env('A1LEADS_BASE_URL', 'http://127.0.0.1:3456'),
        'api_key' => env('A1LEADS_API_KEY'),
        'receipt' => env('A1LEADS_RECEIPT', '/var/lib/a1leads-receipt/installed-receipt.json'),

        /*
         * WHOSE Business Profile this is, and the only organization allowed to
         * read it.
         *
         * A1 Leads holds one business's reviews. The module that shows them is
         * available to every organization by default - a module with no
         * platform flag fails open - so without this an administrator at
         * another tenant could grant themselves the permission from their own
         * Roles screen. Unset means nobody reads it, which is the right answer
         * to "nobody has said whose data this is".
         */
        'organization_id' => env('A1LEADS_ORGANIZATION_ID'),
        // Separate, reply-only live tunnel. Never reuse the snapshot reader key.
        'live_reply_url' => env('A1LEADS_LIVE_REPLY_URL'),
        'live_reply_key' => env('A1LEADS_LIVE_REPLY_KEY'),
        // The COMPANY the Business Profile belongs to (A-1 Chimney). It gates
        // reading the tab as well as replying: Jose, 2026-09-24, "this belongs
        // to a-1chimney" - not to every company in its organization.
        'company_id' => env('A1LEADS_COMPANY_ID'),
        // Review operations (match, draft, workflow card, report) on the LIVE
        // A1 Leads, with their own identity `ezbizi:review-ops` - Rodrigo,
        // 2026-09-25. Neither the read key nor the reply key can call these.
        'review_ops_url' => env('A1LEADS_REVIEW_OPS_URL'),
        'review_ops_key' => env('A1LEADS_REVIEW_OPS_KEY'),

        // The snapshot is refreshed daily at 05:00 New York. Rodrigo's number:
        // warn after thirty hours without a successful installation, which is
        // one missed run plus room for a late one rather than an alarm every
        // time a job runs six minutes late.
        'stale_after_hours' => (int) env('A1LEADS_STALE_AFTER_HOURS', 30),

        // Short. It is a loopback call to a read-only SQLite service; if it is
        // not answering in seconds it is not answering, and the screen has a
        // better story to tell than a spinner.
        'timeout' => (int) env('A1LEADS_TIMEOUT', 15),
    ],

    'thumbtack' => [
        'client_id' => env('THUMBTACK_CLIENT_ID'),
        'client_secret' => env('THUMBTACK_CLIENT_SECRET'),

        // Named outright rather than derived from APP_URL, so staging and
        // production can hold different credentials at the same time -
        // Thumbtack issues staging keys against their own redirect URI.
        'redirect' => env('THUMBTACK_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/thumbtack/callback',

        /*
         * Published values, at last. These were deliberately empty from
         * 2026-08-30 until Thumbtack issued credentials on 2026-09-03, because
         * a plausible-looking guess would have compiled, passed a test against
         * a fake, and failed against Thumbtack. They now come from their own
         * OpenAPI document (api.thumbtack.com/docs/thumbtack_api_latest.json,
         * "HydraOAuth"), not from inference.
         *
         * Still env-overridable, because "Hydra" is ORY Hydra and a partner
         * tenant can be moved.
         */
        'authorize_url' => env('THUMBTACK_AUTHORIZE_URL', 'https://auth.thumbtack.com/oauth2/auth'),
        'token_url' => env('THUMBTACK_TOKEN_URL', 'https://auth.thumbtack.com/oauth2/token'),

        /*
         * The API host, which is NOT the auth host and switches per
         * environment. Staging credentials against the production host fail as
         * an authorisation error rather than as a wrong-host error, so getting
         * this wrong reads like bad keys.
         */
        'api_base' => rtrim((string) env('THUMBTACK_API_BASE', 'https://api.thumbtack.com/api'), '/'),

        /*
         * Only what EzBizi actually calls, and every one of these has code
         * behind it. Asking for a scope you do not use is exactly the
         * over-scoping that had to be unpicked from the Meta submission the
         * same week, so this list and the classes are kept level deliberately.
         *
         * WEBHOOKS ARE NOT OPTIONAL. Thumbtack's supply API can fetch a
         * negotiation by id and has no endpoint that lists them, so a webhook
         * is the only way EzBizi ever learns a lead exists. Drop those two
         * scopes and lead reading does not degrade — it receives nothing.
         *
         * Absent on purpose: `supply::businesses.create` (EzBizi does not make
         * Thumbtack profiles) and `supply::businesses/reviews.*` (Reviews is a
         * different module and connects its own sources).
         *
         * Two things Thumbtack's own scope vocabulary lists that CANNOT be
         * used by anybody: `businesses/media.write` and
         * `businesses/categories.write` are declared and no endpoint in their
         * published OpenAPI document references them. Editing a profile has no
         * endpoint at all — only create. That is why the Help Center article
         * does not claim either.
         */
        /*
         * `supply::users.read` was here and is gone (2026-09-08). It backed
         * ThumbtackAccount::self(), which nothing ever called — the "view your
         * business profile and account details" the Help Center article
         * promises is served by businesses.list, in identify(). A scope with no
         * caller is the Meta over-scoping again, in miniature.
         *
         * `supply::negotiations.write` STAYS despite having no caller either,
         * and the difference is worth stating. It backs setJobStatus(), which
         * is written, tested, and claimed in Thumbtack's published article. It
         * cannot be wired yet for a reason outside this integration: nothing in
         * EzBizi turns a lead into a job, so there is no job whose status could
         * be reported. `lead.converted` says the same thing in the module
         * registry — declared, and marked not raised. Dropping the scope now
         * would mean re-consenting every connected business the day that lands.
         */
        'scopes' => env('THUMBTACK_SCOPES', implode(',', [
            'offline_access',
            'supply::users.disconnect',
            'supply::businesses.list',
            'supply::businesses/associate-phone-numbers.read',
            'supply::businesses/associate-phone-numbers.write',
            'supply::negotiations.read',
            'supply::negotiations.write',
            'supply::messages.read',
            'supply::messages.write',
            'supply::webhooks.read',
            'supply::webhooks.write',
        ])),

        // ORY Hydra supports PKCE and their spec does not mark it required, so
        // this stays off until a live authorize call says otherwise.
        'pkce' => env('THUMBTACK_PKCE', false),
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
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
        'client_id' => env('GBP_CLIENT_ID'),
        'client_secret' => env('GBP_CLIENT_SECRET'),
        'redirect' => env('GBP_REDIRECT_URI')
            ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/v1/social/business-profile/callback',
    ],

    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
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
        'version' => env('GOOGLE_ADS_API_VERSION', 'v24'),
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
        'platform_api_secret' => env('TWILIO_PLATFORM_API_SECRET'),

        // The parent account's own auth token.
        //
        // An API key — even a Main one — is not enough to PROVISION with.
        // Twilio withholds `auth_token` from an account resource fetched or
        // created with key auth, so creating a subaccount that way returns a
        // real account with no token, and §5 needs that token to validate the
        // connection's webhooks. The same restriction is why key auth cannot
        // read into a subaccount, delete a key in one, or move a number out.
        'platform_auth_token' => env('TWILIO_PLATFORM_AUTH_TOKEN'),

        // The number EzBizi ITSELF texts from: verification codes to our own
        // users, on the platform account rather than any tenant's subaccount.
        //
        // Deliberately separate from every number in the Phone module. Those
        // belong to a customer's business and carry that business's carrier
        // registration — A-1 Chimney's toll-free is verified as A-1 Chimney,
        // for A-1 Chimney's customer-care traffic, and sending EzBizi sign-in
        // codes from it is traffic matching no field of its filing. Carriers
        // filter on exactly that, and the penalty is losing the verification.
        //
        // Unset until a number is bought and registered as EzBizi, and the
        // feature is OFF rather than broken while it is unset — see
        // PlatformSms::configured().
        'platform_sms_from' => env('TWILIO_PLATFORM_SMS_FROM'),

        // WHICH ACCOUNT THAT NUMBER LIVES ON, when it is not the parent.
        //
        // +18777373929 moved to the EzBizi SUBACCOUNT on 2026-09-16 at Jose's
        // word, because the number is EzBizi-the-business's and the parent is
        // the platform. A Twilio number can only be a `From` for the account
        // that holds it, so a send addressed to the parent would be refused
        // with 21606 — and 21606 reads like a bad number rather than a number
        // on the wrong account, which is an hour nobody gets back.
        //
        // Authentication is unaffected: the parent's SID and token still sign
        // the request, and Twilio lets a parent act on its subaccounts. This
        // is the account in the URL, not the credentials.
        //
        // Empty falls back to the parent, which is right for an installation
        // whose platform number was never moved.
        'platform_sms_account_sid' => env('TWILIO_PLATFORM_SMS_ACCOUNT_SID'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Firebase Cloud Messaging — push to a closed app (plan §12)
    |---------------------------------------------------------------------------
    |
    | The project is `ezbizi-506117`, registered against applicationId
    | `com.ezbizi.app` by the Android module; `client/android/app/google-
    | services.json` is committed and is how the DEVICE registers. It cannot
    | send anything — the server needs its own credential, which is the service
    | account below.
    |
    | A PATH rather than the JSON in the environment, because the private key
    | inside it is multi-line and a .env carrying one is how a key arrives with
    | its newlines eaten. The file is never committed: it is on the box, and
    | `PushSender::configured()` answers false wherever it is absent, so a
    | developer machine without it simply has no push channel rather than a
    | broken one.
    |
    */
    /*
    |---------------------------------------------------------------------------
    | APNs — push to a closed iPhone (plan §12)
    |---------------------------------------------------------------------------
    |
    | A team-scoped .p8 auth key rather than a per-app certificate: it does not
    | expire annually and covers sandbox and production, so nobody is paged in a
    | year for a lapsed cert. The file is on the box and never committed.
    |
    | `topic` is the BUNDLE ID, measured by the Apple module off the shipped
    | artefact — not the Kotlin-style namespace that resembles it. The wrong
    | value returns 400 BadDeviceToken, which reads like a bad token.
    |
    */
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'key' => env('APNS_KEY_FILE'),
        'topic' => env('APNS_TOPIC', 'com.ezbizi.app'),
        'production' => env('APNS_PRODUCTION', true),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID', 'ezbizi-506117'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],

    // Field (spec 2026-09-25). Two keys on purpose: the server key does
    // Geocoding and Routes and never leaves the box; the tiles key is handed
    // to every client with a Map Tiles session, so it is restricted to that API.
    'google_maps' => [
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'tiles_key' => env('GOOGLE_MAPS_TILES_KEY'),
    ],

    // Google Cloud Speech-to-Text, for transcribing Local Services call
    // recordings (`ezbizi:transcribe-lead-calls`). Off while empty: Google
    // bills every minute of audio, so switching it on is Jose's call.
    'google_speech' => [
        'key' => env('GOOGLE_SPEECH_KEY', ''),
    ],

    // Whisper on this server instead (Jose, 2026-09-29: "i don't want to rely
    // on any physical computers"; free, and the audio never leaves the box).
    // Used when this interpreter exists - /opt/whisper, see
    // deploy/ezbizi-transcribe.service. It wins over Google when both are set.
    'whisper' => [
        'python' => env('WHISPER_PYTHON', '/opt/whisper/venv/bin/python'),
        'script' => base_path('deploy/whisper-transcribe.py'),
    ],

    // Web Management's bulk hours editor can write a listing's regularHours.
    // Off until EzBizi has Business Profile write access of its own (Jose,
    // 2026-09-28); until then that destination answers "not switched on".
    'google_business_profile' => [
        'hours_write' => (bool) env('GBP_HOURS_WRITE', false),
        // Categories and services (BusinessProfileApi). Off while another system
        // still manages A-1's profiles (2026-10-19).
        'write' => (bool) env('GBP_WRITE', false),
    ],

    // The sweep retires a job Workiz deleted (SyncWorkizJobs::retire). Off
    // until MA is connected (desk, 2026-10-05): the 43 Boston North jobs staff
    // moved into MA's account still hold Massachusetts' seats, standing in for
    // MA's own jobs until those arrive. Retired before then, Massachusetts reads
    // ~43 spots open that are taken.
    'workiz' => [
        'retire_deleted' => (bool) env('WORKIZ_RETIRE_DELETED', false),
    ],

    // The Grok Build CLI's home on the box, signed in to Jose's Grok
    // subscription (App\Support\Ai\GrokCli). Unset: nothing uses it.
    'grok_cli' => [
        // Questions asked side by side (GrokCli::askMany); 1 if rate-limited.
        'concurrency' => (int) env('GROK_CLI_CONCURRENCY', 4),
        // Grok's reasoning effort; 'low' is ~3.5x faster on transcripts.
        'effort' => env('GROK_CLI_EFFORT', 'low'),
        // Jose, 2026-10-13: "just new stuff from now on" - AI reads nothing older.
        'since' => env('GROK_AI_SINCE', '2026-10-13'),
        'home' => env('GROK_CLI_HOME', env('REPORT_TAG_GROK_CLI_HOME')),
    ],

    /*
    | Telegram - a second channel for the staff Tech Assistant. The bot serves
    | ONE organization (as the WhatsApp bot number maps to one org); unset
    | token or org means the webhook answers nothing.
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'org_id' => env('TELEGRAM_ORG_ID'),
    ],

];
