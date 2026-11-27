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

];
