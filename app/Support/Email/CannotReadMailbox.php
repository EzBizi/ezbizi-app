<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use RuntimeException;

/**
 * A mailbox could not be read, and the sentence says what to do about it (R8).
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THIS EXISTS SO THAT "NOT CONNECTED" IS NEVER DRAWN AS "NO MAIL".
 *
 *  An empty list is the tempting return value for a reader that
 *  cannot reach its provider. It makes every screen work, every test
 *  pass, and every demo look finished — and it is a lie the person
 *  reading it has no way to detect. An inbox showing nothing means
 *  "nobody has written to you today". An inbox that cannot connect
 *  means "customers have been emailing you for a week and you cannot
 *  see it". They look identical, and only one of them is a reason to
 *  ring somebody.
 *
 *  So the unconfigured path THROWS. It is more work at every call
 *  site and that is the price of the guarantee: a blank message list
 *  in this module always means an empty mailbox, because the other
 *  case cannot reach the screen.
 * ═══════════════════════════════════════════════════════════════════
 *
 * Thrown rather than returned for the same reason CannotSendMail is: a status
 * enum on a result object is a field somebody forgets to check, and the failure
 * of forgetting is precisely the false empty list above.
 */
class CannotReadMailbox extends RuntimeException
{
    /**
     * True when the fix is at OUR end rather than the tenant's.
     *
     * Still a property, because it is read as one — the messages controller puts
     * it straight into the body as `at_our_end` — but it is now DERIVED from the
     * fault in the constructor rather than passed in at each call site, so the
     * two cannot disagree about the same failure.
     */
    public readonly bool $isOurs;

    /**
     * @param  MailboxFault  $fault  the cause, as something other than English
     *
     * ── Why a cause is carried beside the sentence ───────────────
     *
     * The sentence is for a person. It cannot be branched on, and two things need
     * to: {@see RecordingMailboxReader} has to decide whether this failure means
     * the mailbox row should read broken, and a client has to decide whether to
     * offer a "Connect again" button. Both would otherwise be done by feel — and
     * the flags used to be passed in by hand at each call site, which is how one
     * status code and one "whose problem is this" answer came to be repeated in
     * four places that could drift apart.
     *
     * So the fault decides all three: {@see MailboxFault::isOurs},
     * {@see MailboxFault::httpStatus} and {@see MailboxFault::marksBroken}. A new
     * failure mode is one enum case with one set of answers, and nothing has to
     * remember to keep the call sites agreeing.
     */
    private function __construct(
        string $message,
        public readonly MailboxFault $fault,
    ) {
        parent::__construct($message);

        $this->isOurs = $fault->isOurs();
    }

    /**
     * The cause's own sentence, with the provider's own words appended.
     *
     * ── Two audiences, one fact ──────────────────────────────────
     *
     * What is STORED on the mailbox is {@see MailboxFault::sentence} on its own —
     * see {@see RecordingMailboxReader} — because a status screen full of
     * `invalid_grant` and `Precondition check failed` is a status screen nobody
     * reads, and those strings are neither stable nor written for anybody here.
     *
     * What is RETURNED from a live read may carry them, because the person
     * looking at that response is usually the administrator who set the
     * connection up thirty seconds ago, and "the mail service said: ..." is the
     * one moment that text earns its place.
     *
     * ── The third lock on the credential ─────────────────────────
     *
     * The mailbox is passed rather than its address purely so this can happen:
     * text arriving from somewhere else is checked against the stored secret and
     * DROPPED WHOLE if it contains it.
     *
     * The masking on the model and the allowlist in the controller both guard
     * things this application composes. This is the one path where a string
     * somebody else wrote is copied into a response, and a mail service that
     * echoed a token back inside an error — an accident, but not an
     * inconceivable one — would have walked it straight past both. The sentence
     * stands perfectly well on its own, so dropping the detail costs a debugging
     * hint and nothing else.
     */
    private static function because(MailboxFault $fault, Mailbox $mailbox, ?string $detail = null): self
    {
        $detail     = trim((string) $detail);
        $credential = (string) $mailbox->credential();

        if ($detail !== '' && $credential !== '' && str_contains($detail, $credential)) {
            $detail = '';
        }

        return new self(
            $fault->sentence($mailbox->address)
                .($detail === '' ? '' : ' The mail service said: '.$detail),
            $fault,
        );
    }

    /**
     * Nothing has been connected to this mailbox yet.
     *
     * 409 rather than 404 or 200: the mailbox EXISTS — somebody added it and can
     * see it in the list — and what is wrong is its state, which is precisely
     * what a conflict status means. A 404 would say the inbox is not there and
     * send an administrator hunting for it.
     */
    public static function notConnected(string $address, MailboxProvider $provider): self
    {
        return new self(
            sprintf(
                '%s is not connected yet, so there is nothing to show — this is not an empty '
                .'inbox. %s Until that is done, mail sent to this address is still arriving '
                .'normally; EzBizi simply cannot show it to you.',
                $address,
                match ($provider) {
                    MailboxProvider::GmailOAuth => GmailMailboxReader::RETIRED,
                    MailboxProvider::GmailWorkspace => 'Somebody with permission to manage '
                        .'mailboxes needs to connect this organization\'s Google Workspace and '
                        .'pick this address from the directory.',
                    MailboxProvider::MicrosoftOauth => 'Somebody with permission to manage mailboxes '
                        .'needs to sign in to Microsoft and give EzBizi permission to read it.',
                    MailboxProvider::Imap => 'Somebody with permission to manage mailboxes needs '
                        .'to enter the mail server details and the password for this address.',
                },
            ),
            MailboxFault::NotConnected,
        );
    }

    /**
     * The sign-in was there and the mail service will not accept it.
     *
     * ── This is the "revoked or expired" the owner asked about ───
     *
     * And they are ONE case, not two, because the mail service makes them one:
     * Google answers a withdrawn token, an aged-out token and a token invalidated
     * by a password change with the same `invalid_grant`. The sentence behind
     * {@see MailboxFault::SignInNoLongerValid} names all three and says EzBizi
     * cannot tell which — see that enum for why guessing would be worse than
     * admitting it.
     *
     * A separate sentence from `notConnected` because the remedy is different and
     * the urgency is higher: this address WAS working, so mail has been arriving
     * unseen since it stopped.
     */
    public static function credentialRejected(Mailbox $mailbox, ?string $detail = null): self
    {
        return self::because(MailboxFault::SignInNoLongerValid, $mailbox, $detail);
    }

    /**
     * Signed in, and no longer permitted to read the mail.
     *
     * Split out of `credentialRejected` because the two send somebody to
     * different places: a rejected sign-in is reconnected, a narrowed permission
     * is reconnected AND the permission has to be allowed when asked. Told apart
     * by the provider saying so — Google's `insufficientPermissions` — and never
     * guessed at from a bare 403, which is also what a rate limit looks like.
     */
    public static function permissionNarrowed(Mailbox $mailbox, ?string $detail = null): self
    {
        return self::because(MailboxFault::PermissionNarrowed, $mailbox, $detail);
    }

    /**
     * Nothing answered at all.
     *
     * 503, and it says to try again — because it is the one failure here that
     * usually fixes itself, and sending somebody to reconnect a mailbox that is
     * fine wastes their afternoon.
     *
     * Distinguishable from every other case by the shape of the failure rather
     * than by reading a response: there IS no response. A timeout, a refused
     * connection and a name that does not resolve all arrive as the HTTP client
     * giving up before a status code exists.
     */
    public static function hostNotAnswering(Mailbox $mailbox, string $detail): self
    {
        return self::because(MailboxFault::HostNotAnswering, $mailbox, $detail);
    }

    /**
     * The mail service answered, and what it said was that it is broken.
     *
     * A 5xx is the provider's own fault and nothing about this mailbox, which is
     * why it must not mark the row broken and must not offer a reconnect.
     */
    public static function providerFaulty(Mailbox $mailbox, string $detail): self
    {
        return self::because(MailboxFault::ProviderFaulty, $mailbox, $detail);
    }

    /**
     * Slow down.
     *
     * Kept apart from every other refusal precisely because it looks like one and
     * is not: nothing is wrong with the mailbox, and the only correct response is
     * to wait. See {@see MailboxFault::marksBroken} for why this one deliberately
     * leaves the row alone.
     */
    public static function tooManyRequests(Mailbox $mailbox, ?string $detail = null): self
    {
        return self::because(MailboxFault::TooManyRequests, $mailbox, $detail);
    }

    /**
     * Refused, and the provider did not say enough to name a cause.
     *
     * The honest bucket, and it exists so that the named causes above stay
     * truthful. The tempting alternative is to fold anything unrecognised into
     * `credentialRejected`, since that is the commonest real failure — and then
     * every unexplained hiccup tells an administrator their sign-in was revoked,
     * which is a guess presented as a diagnosis.
     */
    public static function providerRefused(Mailbox $mailbox, ?string $detail = null): self
    {
        return self::because(MailboxFault::ProviderRefused, $mailbox, $detail);
    }

    /**
     * The provider rejected EZBIZI, not the tenant.
     *
     * Google answers a bad `client_id`/`client_secret` at the token endpoint with
     * a 401, and a stale or withdrawn refresh token with a 400. Those two were
     * being caught by the same branch and both blamed on the mailbox — so an
     * installation whose own Google registration had lapsed would have told every
     * tenant, individually, that their password had been changed.
     */
    public static function platformRejected(Mailbox $mailbox, ?string $detail = null): self
    {
        return self::because(MailboxFault::PlatformRejected, $mailbox, $detail);
    }

    /**
     * EzBizi itself has nothing behind this provider on this installation.
     *
     * At OUR end, and the sentence says so first, because the tenant can do
     * absolutely nothing about it and must not be sent to check their password.
     * This is the honest state of an installation with no Google OAuth client
     * registered and no IMAP library installed, and it is what both
     * {@see GmailMailboxReader} and {@see ImapMailboxReader} answer today.
     */
    public static function notBuiltHere(MailboxProvider $provider, string $why): self
    {
        return new self(
            sprintf(
                'EzBizi cannot open %s mailboxes on this installation yet, so there is nothing '
                .'to show. This is at EzBizi\'s end and not something you can fix from here — '
                .'nothing is wrong with the address or the password. %s',
                $provider->label(),
                $why,
            ),
            MailboxFault::NotBuiltHere,
        );
    }

    /**
     * The status the cause answers with — see {@see MailboxFault::httpStatus}.
     *
     * Kept as a method here because that is how both controllers already call it,
     * and delegated rather than stored so that a fault's status is decided once.
     */
    public function status(): int
    {
        return $this->fault->httpStatus();
    }
}
