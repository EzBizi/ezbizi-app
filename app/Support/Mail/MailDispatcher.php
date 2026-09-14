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

    /**
     * Send through the TENANT'S OWN mail server. Nothing of ours carries it.
     *
     * The door that opens as `asTenant` closes, and it is a different door
     * rather than the same one renamed. `asTenant` put a tenant's address on a
     * message and handed it to EzBizi's transport; this builds a transport out
     * of the tenant's own credentials, so our SES is not in the path at all.
     * That is what makes it compatible with the ruling that retired the other:
     * "EZBIZI EMAIL IS ONLY FOR EZBIZI ACTIONS" is about whose transport
     * carries the message, and here it is theirs.
     *
     * Authorised by Jose on 2026-09-14 — asked as "reports can no longer be
     * emailed, and the same is true of Automations' email action", answered
     * "yes build it for god sake".
     *
     * ── No mailer class, because Laravel already has one ─────────
     *
     * `Mail::build()` takes an array config and returns a Mailer. A dedicated
     * TenantMailer would be that call plus a constructor, so there is not one.
     *
     * ── What it refuses, and why each refusal is separate ────────
     *
     * A scope with no row, or a row on a different transport, has not connected
     * anything — that sentence tells them what to do. A row whose credentials
     * are missing is a DIFFERENT fault: something was connected and the
     * password did not survive, which is not the tenant's mistake to fix by
     * filling the same form in again. And {@see TenantSmtpHost} refuses a host
     * that points back into our own network before anything dials it.
     *
     * @param  \App\Models\Organization|\App\Models\Company|\App\Models\Franchise  $scope
     * @param  string|array  $to
     *
     * @throws CannotSendMail when this scope has no mail provider connected
     */
    public function throughTenantProvider(Model $scope, Mailable $message, string|array $to): void
    {
        $identity = $this->resolver->for($scope);

        $message->from($identity->fromAddress, $identity->fromName);

        if ($identity->replyTo !== null && $identity->replyTo !== '') {
            $message->replyTo($identity->replyTo);
        }

        /*
         * Built per send rather than registered as a named mailer. A named one
         * lives in the manager's cache for the life of the process, and a queue
         * worker handling two tenants in a row is exactly where that surfaces:
         * one customer's report leaving through another customer's mail server.
         * Same reasoning as handOver() setting From on the message rather than
         * on config.
         */
        Mail::build($this->smtpConfigFor($scope))->to($to)->send($message);
    }

    /**
     * The connection a tenant send would be made on — every refusal lives here.
     *
     * ── Public, and split out, because Mail::build() cannot be faked ─
     *
     * MailFake has no build() override, so `__call` forwards it to the REAL
     * manager. A test that calls Mail::fake() and then sends through here would
     * open an actual socket to whatever host the fixture named — silently, and
     * looking for all the world like a faked send. Splitting the decision from
     * the dispatch means everything that can be got WRONG — which scope
     * answered, whether it is connected, which host, which port — is assertable
     * without a network, and what is left for Mail::build() is Laravel's own
     * code doing what it says.
     *
     * Anyone testing a caller of throughTenantProvider should assert against
     * this and not reach for Mail::fake(), which will not save them.
     *
     * @param  \App\Models\Organization|\App\Models\Company|\App\Models\Franchise  $scope
     * @return array<string, mixed>
     *
     * @throws CannotSendMail when this scope has no usable mail server
     */
    public function smtpConfigFor(Model $scope): array
    {
        $identity = $this->resolver->for($scope);
        $source   = $this->resolver->sourceOf($scope);
        $row      = $source === null ? null : $this->resolver->rowAt($source);

        if ($row === null || $identity->transport !== MailTransport::Smtp) {
            throw new CannotSendMail(
                'This business has not connected its own mail server yet, and reports, '
                .'inspection reports and automations that email somebody go out through it '
                ."rather than through EzBizi. EzBizi's own mail carries signing in, password "
                .'resets and joining messages, and only those.',
                $identity,
            );
        }

        $credentials = $row->credentials ?? [];

        /*
         * A DIFFERENT fault from "not connected", and it gets a different
         * sentence. Something WAS connected and part of it did not survive;
         * telling that tenant to connect a mail server describes a state they
         * are not in, and they would fill in the same form and see the same
         * message.
         */
        foreach (['host', 'username', 'password'] as $required) {
            if (($credentials[$required] ?? '') === '') {
                throw new CannotSendMail(
                    "The mail server saved for this business has no {$required}, so nothing "
                    .'can be sent through it. Open Settings, Sending Address and save the '
                    .'connection again.',
                    $identity,
                );
            }
        }

        TenantSmtpHost::guard((string) $credentials['host']);

        return [
            'transport'  => 'smtp',
            'host'       => (string) $credentials['host'],
            'port'       => (int) ($credentials['port'] ?? 587),
            'username'   => (string) $credentials['username'],
            'password'   => (string) $credentials['password'],
            'encryption' => $credentials['encryption'] ?? 'tls',

            // Bounded, because the host is a stranger's machine. Without it a
            // queue worker blocks on a black-holed port until PHP's own limit.
            'timeout'    => 15,
        ];
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
