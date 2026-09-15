<?php

namespace App\Support\Mail;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Support\Email\ApprovedHost;
use App\Support\Email\CannotReachHost;
use App\Support\Email\ImapFailure;
use App\Support\Email\ImapReplySender;
use App\Support\Email\RemoteHostGuard;
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
    /**
     * Below ImapReplySender's own 3MB ceiling on purpose, so a report that does
     * not fit is refused in THIS file's words rather than the transport's. The
     * Email module's docblock asks for exactly that: their limit is the
     * backstop, and a scheduled report failing into ImapFailure::unreachable
     * would be the transport answering a product question.
     */
    private const MAX_MESSAGE_BYTES = 3_000_000;

    public function __construct(
        private readonly PlatformIdentity $platform,
        private readonly SendingIdentityResolver $resolver,
        private readonly RemoteHostGuard $hosts,

        /*
         * The Email module's socket, not a copy. It dials the address the
         * guard judged with the hostname as peer_name, and it reads SMTP
         * reply codes so a refusal can be told from a timeout.
         */
        private readonly ImapReplySender $sender,
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
     * @param  Organization|Company|Franchise  $scope
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
     * filling the same form in again. And {@see RemoteHostGuard}
     * refuses a host that points back into our own network, or a port that is
     * not one a mail server answers on, before anything dials it.
     *
     * @param  Organization|Company|Franchise  $scope
     *
     * @throws CannotSendMail when this scope has no mail provider connected
     */
    public function throughTenantProvider(Model $scope, Mailable $message, string|array $to): void
    {
        $identity = $this->resolver->for($scope);
        $server = $this->smtpConfigFor($scope);

        $message->from($identity->fromAddress, $identity->fromName);

        if ($identity->replyTo !== null && $identity->replyTo !== '') {
            $message->replyTo($identity->replyTo);
        }

        $recipients = array_values(array_filter((array) $to));

        $mime = $this->composed($message, $recipients);

        /*
         * The attempt is recorded BEFORE the send and whatever happens after,
         * which is the whole reason the column exists. Recording only successes
         * would leave a server that has failed ten times still telling the
         * screen nobody has tried it — and "Saved, never tried" is only honest
         * if a failed attempt counts as an attempt.
         */
        $source = $this->resolver->sourceOf($scope);

        if ($source !== null) {
            $this->resolver->rowAt($source)?->noteAttempt();
        }

        /*
         * RemoteHostGuard, never a check of our own. It is the one guard for a
         * host a CUSTOMER typed; it resolves ONCE and hands back the address it
         * judged, and ImapSocket dials that address with the hostname as OpenSSL
         * peer_name — which closes DNS rebinding without losing certificate
         * verification. Mail::build could not do that: Symfony re-resolves when
         * it dials, so the name we checked and the name it reaches are two
         * separate lookups.
         */
        try {
            $this->sender->send(
                approved: $server['approved'],
                encryption: (string) $server['encryption'],
                username: (string) $server['username'],
                password: (string) $server['password'],
                from: $identity->fromAddress,
                to: $recipients,
                message: $mime,
            );
        } catch (ImapFailure $e) {
            throw new CannotSendMail($this->sentenceFor($e, $identity), $identity);
        }
    }

    /**
     * The message as bytes, composed locally and sent nowhere from here.
     *
     * ── Why the array transport and not a hand-built MIME ────────
     *
     * Symfony composes RFC 5322 correctly — multipart, headers, encodings — and
     * Laravel already drives it. The alternatives were widening the Email
     * module's ReplyComposer (it takes a Mailbox, and a sending identity is not
     * one) or writing a second multipart builder, which is the mistake that
     * produced TenantSmtpHost with different letters.
     *
     * `Mail::build` here dials nothing: the array transport keeps the message in
     * memory and that is all this wants from it. A fresh instance per send, so
     * two tenants handled by one worker cannot read each other's outbox.
     *
     * @param  string[]  $recipients
     */
    private function composed(Mailable $message, array $recipients): string
    {
        $mailer = Mail::build(['transport' => 'array']);

        $mailer->to($recipients)->send($message);

        /*
         * `collect(...)` because messages() hands back a COLLECTION, not an
         * array. Written as `$sent === []` and `end($sent)` first, which is
         * wrong twice over and silently: the identity check is false against an
         * object so the guard never fired, and end() on a non-array returned
         * false, so the failure surfaced as "Call to a member function
         * getOriginalMessage() on false" rather than as the empty case it was
         * meant to catch. Collections satisfy count() and offsetGet(), which is
         * why a probe in tinker looked fine.
         */
        $sent = collect($mailer->getSymfonyTransport()->messages());

        $composed = $sent->last();

        if ($composed === null) {
            throw new CannotSendMail('The message composed to nothing, so there is nothing to send.');
        }

        $mime = $composed->getOriginalMessage()->toString();

        /*
         * SEVEN-BIT SAFE OR NOT AT ALL — the Email module's constraint, which
         * did not exist in writing until they wrote it down for this caller.
         *
         * ImapReplySender::dotStuffed() normalises every line ending to CRLF
         * before writing the body. Correct for SMTP, and idempotent on a message
         * that is already CRLF — but it means a part carrying raw bytes under
         * `binary` or `8bit` is silently corrupted, because a 0x0D inside a
         * payload cannot be told from a line break at that point. Measured, not
         * taken: a lone 0x0D in the input comes out as 0x0D0A.
         *
         * Symfony defaults to base64 for attachments and quoted-printable for
         * text, so this passes today. Asserted rather than assumed because that
         * is a library default, and the failure it guards is SILENT: a CSV that
         * arrives subtly wrong is worse than a report that does not arrive.
         */
        if (preg_match('~^Content-Transfer-Encoding:\s*(binary|8bit)~mi', $mime) === 1) {
            throw new CannotSendMail(
                'This message cannot be sent safely: part of it is in a format the mail path '
                .'would corrupt. Nothing was sent.'
            );
        }

        /*
         * Bounded BELOW the transport's own 3MB ceiling, deliberately, so the
         * sentence somebody reads is this one rather than ImapFailure's. The
         * Email module's docblock asks for exactly that — theirs is the backstop.
         *
         * Not theoretical: ReportRunner::ROW_CAP is 5000 rows and base64 adds
         * about a third, so a wide report can reach this. The fix for a report
         * that does not fit is a narrower report, not a bigger number — above
         * 3MB is a chunked SMTP path, which is a different feature.
         */
        if (strlen($mime) > self::MAX_MESSAGE_BYTES) {
            throw new CannotSendMail(sprintf(
                'This report is too large to email — %.1fMB, and mail servers refuse messages '
                .'over about 3MB. Narrow the report, or shorten the period it covers.',
                strlen($mime) / 1_048_576,
            ));
        }

        return $mime;
    }

    /**
     * What to tell somebody, taken from the code the server actually sent.
     *
     * Four causes the Email module's socket already tells apart, and the 4xx
     * against 5xx split is the one that matters: a temporary refusal that sends
     * somebody off to check their password is a confident wrong cause. None of
     * this parses the server's prose — every kind comes from a numeric reply.
     */
    private function sentenceFor(ImapFailure $e, MailIdentity $identity): string
    {
        return match ($e->kind) {
            ImapFailure::REJECTED => 'The mail server refused the user name or password saved for '
                .$identity->fromAddress.'. This will not fix itself — check the account with '
                .'whoever provides this business its email, then save the connection again.',

            ImapFailure::TIMEOUT, ImapFailure::MISBEHAVED => 'The mail server did not accept this '
                .'message just now, and nothing says the account is wrong. There is nothing to '
                .'do — it will be tried again, and worth reporting if it is still failing '
                .'tomorrow.',

            default => 'EzBizi could not reach the mail server saved for '.$identity->fromAddress
                .'. Check the server name and port with whoever provides this business its email.',
        };
    }

    /**
     * The connection a tenant send would be made on — every refusal lives here.
     *
     * ── Public and split out, because a send cannot be faked ─────
     *
     * MailFake has no build() override, so `__call` forwards it to the real
     * manager, and this path does not go through Mail::fake at all anyway — it
     * dials a socket. A test that wanted to prove "this tenant is connected"
     * would otherwise have to open one. Everything that can be got WRONG —
     * which scope answered, whether it is connected, which host, which port,
     * whether the host may be dialled — is decided here and is assertable
     * without a network. What is left for the caller is the send itself.
     *
     * Anyone testing a caller of throughTenantProvider should assert against
     * this and not reach for Mail::fake(), which will not save them.
     *
     * ── It approves the host, and it approves it ONCE ────────────
     *
     * `approve()` returns the address it judged, not merely a yes. Calling it
     * again to get a fresh one would be DNS rebinding written by hand — two
     * lookups, and an attacker's nameserver answering the second differently.
     * RemoteHostGuard's own docblock says an early draft of it had that bug. So
     * the ApprovedHost comes back in this array and the caller dials THAT.
     *
     * @param  Organization|Company|Franchise  $scope
     * @return array{approved: ApprovedHost, username: string, password: string, encryption: string}
     *
     * @throws CannotSendMail when this scope has no usable mail server
     */
    public function smtpConfigFor(Model $scope): array
    {
        $identity = $this->resolver->for($scope);
        $source = $this->resolver->sourceOf($scope);
        $row = $source === null ? null : $this->resolver->rowAt($source);

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
                    .'can be sent through it. Open Settings, Sending Server and save the '
                    .'connection again.',
                    $identity,
                );
            }
        }

        /*
         * The Email module's guard and its port list, never a check of our own.
         * TenantSmtpHost was that mistake and it was deleted the day it shipped:
         * two guards on one class of input drift, and the newer one was worse —
         * no port allow-list at all, no bounded timeouts, and it resolved twice.
         */
        try {
            $approved = $this->hosts->approve(
                (string) $credentials['host'],
                (int) ($credentials['port'] ?? 587),
                RemoteHostGuard::SMTP_PORTS,
            );
        } catch (CannotReachHost $e) {
            throw new CannotSendMail($e->getMessage(), $identity);
        }

        return [
            'approved' => $approved,
            'username' => (string) $credentials['username'],
            'password' => (string) $credentials['password'],
            'encryption' => $credentials['encryption'] ?? 'tls',
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
