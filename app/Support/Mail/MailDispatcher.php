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
     * Send as the tenant — RETIRED. Always refuses (Jose, 2026-09-14).
     *
     * His words, after being asked in three different costumes and saying so he
     * had "answered this a thousand times already":
     *
     *   "Email should not be sent as EzBizi, EZBIZI EMAIL IS ONLY FOR EZBIZI
     *    ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESET, VERIFICATIONS, ETC."
     *
     * Not the from-address — the SERVICE. So there is no configuration that
     * turns this back on, which is why it refuses unconditionally instead of
     * testing the identity.
     *
     * WHY THE IDENTITY CANNOT BE THE TEST, measured rather than reasoned:
     * handOver() ends at Mail::mailer($this->platform->mailerName()). EVERY
     * message leaves on the platform mailer. A tenant identity changes from()
     * and replyTo() and nothing else — sending "as" a tenant has never meant
     * sending "through" one, so the reputation and the quota are EzBizi's
     * whether a domain is verified or not. A guard keyed on the identity would
     * refuse the unconfigured tenant and wave through the configured one, and
     * the configured one is exactly what the ruling is about.
     *
     * THE LINE. Content the tenant AUTHORS — an inspection report, a saved
     * report, an automation that emails somebody — needs their own provider,
     * the way a newsletter already leaves through Mailchimp on the tenant's own
     * API key. Mail that IS the mechanism — invitation link, password reset,
     * address confirmation — is EzBizi's own and is correct as EzBizi. Those
     * three never reach this method: they go through Notification::route, and
     * platform() above is their path.
     *
     * KEPT RATHER THAN DELETED, and the method still takes its arguments. A
     * missing method is a fatal at the call site; a refusal is a sentence the
     * caller can show somebody. The resolver call below survives only to give
     * the exception the identity it would have used, which is what the settings
     * screen still reads through identityFor().
     *
     * @param  \App\Models\Organization|\App\Models\Company|\App\Models\Franchise  $scope
     * @param  string|array  $to
     *
     * @throws CannotSendMail always
     */
    public function asTenant(Model $scope, Mailable $message, string|array $to): void
    {
        throw new CannotSendMail(
            'Reports, inspection reports and automations that email somebody need this business '
            ."to have its own email provider connected. EzBizi's own mail carries signing in, "
            .'password resets and joining messages, and only those.',
            $this->resolver->for($scope),
        );
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
