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
 */
class MailStatus
{
    /** Transports that go nowhere. Not failures — just not delivery. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    public function transport(): string
    {
        return (string) config('mail.default');
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

        if ($this->transport() === 'ses') {
            foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY'] as $path => $label) {
                if (blank(config("services.ses.{$path}"))) {
                    $missing[] = $label;
                }
            }
        }

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
        ];
    }
}
