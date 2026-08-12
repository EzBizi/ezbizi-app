<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    | ── The fallback is smtp, and it used to be log ──────────────
    |
    | `log` accepts every message, returns success and delivers nothing. An
    | installation that simply forgot to set MAIL_MAILER therefore looked like a
    | working one from every endpoint that "sent" mail — which is the exact
    | failure App\Support\Mail\MailStatus was written to notice, arrived at by
    | the config default rather than by anybody's decision.
    |
    | `smtp` is the honest fallback: with no credentials it fails loudly and
    | MailStatus names what is missing, and it is what the platform transport is
    | meant to be anyway (see the platform mailer below). Setting MAIL_MAILER=log
    | is still perfectly reasonable in local development — it is now a choice
    | somebody made rather than one nobody made.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | The Platform Mailer
    |--------------------------------------------------------------------------
    |
    | Which configured mailer EzBizi's own messages go out through — invitations,
    | password resets, every HR message, and any tenant mail sent under a domain
    | the tenant has proved (that is still OUR transport, signed as theirs).
    |
    | Named separately from `default` for one specific reason. Production runs
    | with MAIL_MAILER=ses and an empty AWS key, so nothing has ever sent; the
    | owner is moving to plain SMTP against their own Google Workspace, and may
    | move again to SES or Postmark later. Plain SMTP with host, port, user and
    | password speaks to all three, so the provider changes with credentials and
    | no code — nothing in the application names an SDK.
    |
    | Null (the usual case) means "whatever `default` is". Set MAIL_PLATFORM_MAILER
    | only when platform mail should go somewhere other than the default mailer.
    |
    | Read by App\Support\Mail\PlatformIdentity::mailerName().
    |
    */

    'platform_mailer' => env('MAIL_PLATFORM_MAILER'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        /*
         * The platform transport, and the only one the owner has decided on.
         *
         * Plain SMTP: host, port, username, password. Google Workspace, SES and
         * Postmark all accept exactly this, so moving between them is a
         * credential change and never a code change. That is why no mailer
         * below is coupled to a provider SDK, and why the `ses` entry is left
         * only because Laravel ships it — production's MAIL_MAILER=ses with an
         * empty AWS key is what has meant nothing sends at all.
         */
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where replies to platform mail go
    |--------------------------------------------------------------------------
    |
    | Optional. Blank means replies go back to the From above, which is normally
    | right — a noreply address that bounces a customer's reply into nothing is
    | a decision an installation should have to make on purpose.
    |
    */

    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proving a tenant owns the domain they want to send as
    |--------------------------------------------------------------------------
    |
    | All DEPLOYMENT CONFIG (§2.1): every value here names EzBizi's own
    | infrastructure or the service EzBizi verifies through. A tenant's sending
    | ADDRESS is a tenant setting and lives in the sending_identities table; the
    | machinery that proves it lives here. The two must not be blurred.
    |
    | Nothing below is set today, which is the honest state of this installation:
    | there is no DNS-verification service connected, so no tenant domain can be
    | proved and every tenant sends from the EzBizi address instead. The code
    | says so out loud rather than pretending — see UnconfiguredDnsVerifier.
    |
    */

    'verification' => [

        /*
         * The class that reads real DNS — the ONE seam.
         *
         * Must implement App\Support\Mail\DnsVerifier. Unset, the default is
         * UnconfiguredDnsVerifier, which refuses every domain and says why.
         * A class that does not implement the interface is refused loudly
         * rather than downgraded silently: an operator who set this believes
         * verification is switched on, and a quiet fallback would leave every
         * tenant pending forever with nothing anywhere saying why.
         */
        'dns_verifier' => env('MAIL_DNS_VERIFIER'),

        /*
         * The label the ownership TXT record sits at — `_ezbizi-verify.everdoors.com`.
         *
         * Configurable because a second installation of this software is not
         * called EzBizi, and asking its customers to publish a record with our
         * name in it would be odd.
         */
        'ownership_host' => env('MAIL_VERIFICATION_HOST', '_ezbizi-verify'),

        /*
         * The host a tenant's SPF record includes, once there is one to include.
         *
         * Unset means no SPF record is generated at all, rather than one naming
         * a plausible-looking host: a tenant who publishes an include for
         * something that does not send their mail has made their deliverability
         * worse, not better.
         */
        'spf_include' => env('MAIL_SPF_INCLUDE'),

        /*
         * DKIM, which takes both or neither.
         *
         * The selector is the provider's to choose, so it is configured rather
         * than invented here. A selector with no host would produce a CNAME
         * pointing at nothing — published, and failing.
         */
        'dkim_selector' => env('MAIL_DKIM_SELECTOR'),
        'dkim_host'     => env('MAIL_DKIM_HOST'),
    ],

];
