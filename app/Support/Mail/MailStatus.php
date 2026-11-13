<?php

namespace App\Support\Mail;

/**
 * Can this installation actually send an email?
 *
 * Worth its own class because the failure mode is silent. `MAIL_MAILER=log`
 * accepts every message and returns success; so does `ses` with an empty key,
 * right up until the queued job throws where nobody is looking. Both look
 * identical from the endpoint that "sent" the mail — which is how somebody ends
 * up waiting for a password reset that was never going anywhere.
 *
 * ── What this answers, and what it deliberately does not ─────────
 *
 * This is about the WIRE: is there a transport, and does it have what it needs.
 * Whether a given tenant may send under their own address is a different
 * question with a different owner — {@see MailIdentity::canSend()} — and the two
 * are kept apart on purpose. An installation with no SMTP credential must still
 * BUILD a password reset and hand it to a transport that reports its own,
 * accurate error; refusing to build it would mean this class never sees the
 * attempt it exists to notice.
 */
class MailStatus
{
    /** Transports that go nowhere. Not failures — just not delivery. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    /**
     * The mailer platform mail actually goes out through.
     *
     * `mail.platform_mailer` when a deployment has named one, otherwise the
     * default mailer — which is the usual case and is what this always used to
     * read. Asking the same question PlatformIdentity::mailerName() asks means
     * the health screen reports the transport that carries the mail rather than
     * one that happens to share a config file with it.
     */
    public function transport(): string
    {
        return (string) (config('mail.platform_mailer') ?: config('mail.default'));
    }

    public function delivers(): bool
    {
        if (in_array($this->transport(), self::NON_DELIVERING, strict: true)) {
            return false;
        }

        return $this->missing() === [];
    }

    /** What is stopping it, by name, so the answer is actionable. */
    public function missing(): array
    {
        $missing = [];

        if ($this->transport() === 'smtp' && blank(config('mail.mailers.smtp.username'))) {
            $missing[] = 'MAIL_USERNAME';
        }

        $from = config('mail.from.address');

        // The default placeholder is worse than nothing: SES rejects a From it
        // has no identity for, so every send fails with an error about a domain
        // nobody meant to use.
        if (blank($from) || str_ends_with((string) $from, '@example.com')) {
            $missing[] = 'MAIL_FROM_ADDRESS';
        }

        return $missing;
    }

    public function summary(): array
    {
        return [
            'transport' => $this->transport(),
            'delivers'  => $this->delivers(),
            'from'      => config('mail.from.address'),
            'missing'   => $this->missing(),

            /*
             * Whether tenants can prove their own domains, reported beside the
             * transport because they fail together and are diagnosed together.
             *
             * A staff screen showing a healthy transport and nothing about
             * verification would answer "why is this customer's newsletter
             * still coming from EzBizi" with silence. `verifier` is the
             * verifier's own sentence; `missing` names the environment
             * variables that would change the answer.
             */
        ];
    }

}
