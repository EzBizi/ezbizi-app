<?php

namespace App\Support\Mail;

/**
 * EzBizi's own sending identity — the root of the cascade (§2.1, §6).
 *
 * ── Deployment config, not a tenant setting ──────────────────────
 *
 * §2.1's test is "would a second INSTALLATION need a different value", and this
 * one screams yes: a staging box must not send as noreply@ezbizi.com, and a
 * second installation is a different company. So the platform address lives in
 * the environment beside the transport credentials that carry it, and there is
 * no row for it and no screen that edits it. Blurring this into the tenant
 * settings — one more "sending identity" record with a magic organization id —
 * is exactly the category error §2.1 warns about.
 *
 * ── The root of the cascade, not a fallback beside it ────────────
 *
 * It answers with the same {@see MailIdentity} a tenant row does, so
 * SendingIdentityResolver's loop ends here the way SettingsResolver's ends at a
 * definition's `default`. Nothing downstream branches on "is this the platform".
 *
 * ── Why it is always Verified ────────────────────────────────────
 *
 * Not a shortcut. Verification answers "does this business own this domain",
 * and the answer for our own domain is yes by construction — there is nobody to
 * prove it to. Whether the wire actually works is a DIFFERENT question with its
 * own answer already: {@see MailStatus} reports the transport and what it is
 * missing, and that is what the staff health screen reads.
 *
 * Conflating the two would be a real bug rather than a tidy simplification. An
 * installation with no SMTP credential would start REFUSING to build platform
 * messages, so a password reset would fail with "this identity may not send"
 * instead of being handed to a transport that reports its own, accurate error —
 * and MailStatus, the thing built to notice a broken transport, would never see
 * an attempt to notice.
 */
class PlatformIdentity
{
    /**
     * The identity every message falls back to.
     *
     * Read fresh each call rather than cached in a property: config() is
     * swapped inside tests and by `config:cache` at deploy time, and an
     * identity captured at container-build time would outlive both.
     */
    public function identity(): MailIdentity
    {
        return new MailIdentity(
            fromAddress: $this->address(),
            fromName:    (string) (config('mail.from.name') ?: config('app.name') ?: 'EzBizi'),
            replyTo:     config('mail.reply_to.address') ?: null,
            transport:   MailTransport::Platform,
            state:       SendingState::Verified,
        );
    }

    /**
     * Which configured mailer platform mail goes out through.
     *
     * `mail.platform_mailer`, which defaults to the installation's default
     * mailer. Naming it separately is what lets a deployment send platform mail
     * over one transport while something else uses another, without any code
     * knowing — and it is the hook that made it possible to stop platform mail
     * depending on a `MAIL_MAILER` that production had left pointing at an SES
     * account with no credentials.
     */
    public function mailerName(): string
    {
        return (string) (config('mail.platform_mailer') ?: config('mail.default'));
    }

    /**
     * The address, refusing the placeholder.
     *
     * `hello@example.com` is Laravel's stock value and it is worse than blank —
     * MailStatus already records why: a transport rejects a From it holds no
     * identity for, and the error then names a domain nobody meant to use. A
     * MailIdentity has to hold something, so this substitutes an address built
     * from the installation's own URL, which is at least true about where the
     * mail came from.
     */
    private function address(): string
    {
        $configured = (string) config('mail.from.address');

        if ($configured !== '' && ! str_ends_with($configured, '@example.com')) {
            return $configured;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return 'noreply@'.$host;
    }
}
