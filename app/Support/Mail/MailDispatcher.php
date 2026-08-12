<?php

namespace App\Support\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * The one place a message is given a From, and the one place that is refused.
 *
 * ── Two doors, and they are different on purpose ─────────────────
 *
 * `platform()` is for anything EzBizi says to somebody as EzBizi: invitations,
 * password resets, every HR message. The owner was explicit about why —
 * "they are signing into our platform, it is the duty of the organizations to
 * explain how our software works to their employees" — so those never carry a
 * tenant's address, however the tenant has configured themselves. There is no
 * tenant lookup on that path at all, which is stronger than a lookup that
 * happens to be skipped: there is nothing to accidentally start consulting.
 *
 * `asTenant()` is for content the CUSTOMER authors — the newsletter the Marketing
 * module will eventually send. It resolves the §6 cascade, and it refuses when
 * the identity it lands on has not proved its domain.
 *
 * ── Why the refusal is a throw ───────────────────────────────────
 *
 * Because the alternative is a caller that ignores a false and reports success.
 * Mail failing silently is the failure mode MailStatus already exists to fight;
 * an unverified identity quietly falling back to the EzBizi address would be
 * the same shape of lie, with the tenant's newsletter going out under our name.
 *
 * ── Nothing here knows what an SES is ────────────────────────────
 *
 * The transport is whatever mailer deployment config names, spoken as plain
 * SMTP. The owner's reasoning, kept because it decides future changes too:
 * host, port, user and password work for Google Workspace, SES and Postmark
 * alike, so the provider changes with credentials and no code. An SDK here
 * would undo that.
 */
class MailDispatcher
{
    public function __construct(
        private readonly PlatformIdentity $platform,
        private readonly SendingIdentityResolver $resolver,
    ) {}

    /**
     * Send as EzBizi. Never as a tenant, whatever the tenant has configured.
     *
     * @param  string|array  $to  address, or addresses
     */
    public function platform(Mailable $message, string|array $to): void
    {
        $this->handOver($this->platform->identity(), $message, $to);
    }

    /**
     * Send as the tenant — refused unless the identity has proved its domain.
     *
     * @param  \App\Models\Organization|\App\Models\Company|\App\Models\Franchise  $scope
     * @param  string|array  $to
     *
     * @throws CannotSendMail with a sentence saying what to do about it
     */
    public function asTenant(Model $scope, Mailable $message, string|array $to): void
    {
        $identity = $this->resolver->for($scope);

        if (! $identity->canSend()) {
            throw new CannotSendMail((string) $identity->refusal(), $identity);
        }

        $this->handOver($identity, $message, $to);
    }

    /** What this scope would send as, verified or not — for a screen to show. */
    public function identityFor(Model $scope): MailIdentity
    {
        return $this->resolver->for($scope);
    }

    /**
     * Stamp the identity onto the message and give it to the transport.
     *
     * The From is set on the MESSAGE rather than left to `mail.from`, so that a
     * tenant send and a platform send differ in the message and not in global
     * state. Setting config('mail.from') per send would leak across a queue
     * worker's next job, which is the kind of bug that surfaces as one
     * customer's newsletter arriving from another customer's address.
     */
    private function handOver(MailIdentity $identity, Mailable $message, string|array $to): void
    {
        $message->from($identity->fromAddress, $identity->fromName);

        if ($identity->replyTo !== null && $identity->replyTo !== '') {
            $message->replyTo($identity->replyTo);
        }

        Mail::mailer($this->platform->mailerName())->to($to)->send($message);
    }
}
