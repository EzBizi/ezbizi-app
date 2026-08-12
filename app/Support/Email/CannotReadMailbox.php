<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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
    private function __construct(
        string $message,
        /** True when the fix is at OUR end rather than the tenant's. */
        public readonly bool $isOurs,
        private readonly int $status,
    ) {
        parent::__construct($message);
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
                $provider === MailboxProvider::GmailOAuth
                    ? 'Somebody with permission to manage mailboxes needs to sign in to Google '
                        .'and give EzBizi permission to read it.'
                    : 'Somebody with permission to manage mailboxes needs to enter the mail '
                        .'server details and the password for this address.',
            ),
            isOurs: false,
            status: Response::HTTP_CONFLICT,
        );
    }

    /**
     * The credential was there and the provider rejected it.
     *
     * A separate sentence from `notConnected` because the remedy is different
     * and the urgency is higher: this address WAS working, so mail has been
     * arriving unseen since it stopped.
     */
    public static function credentialRejected(string $address, ?string $detail = null): self
    {
        return new self(
            sprintf(
                'The mail service would not let EzBizi open %s. This usually means the '
                .'password was changed, or the permission granted to EzBizi was withdrawn. '
                .'Somebody with permission to manage mailboxes needs to connect it again. '
                .'Mail is still arriving at the address in the meantime.%s',
                $address,
                $detail === null ? '' : ' The mail service said: '.$detail,
            ),
            isOurs: false,
            status: Response::HTTP_CONFLICT,
        );
    }

    /**
     * The provider is there and is not answering.
     *
     * 503, and it says to try again — because it is the one failure here that
     * usually fixes itself, and sending somebody to reconnect a mailbox that is
     * fine wastes their afternoon.
     */
    public static function providerUnavailable(string $address, string $detail): self
    {
        return new self(
            sprintf(
                'EzBizi could not reach the mail service for %s just now, so this list is not '
                .'showing anything rather than showing you a stale copy. Try again in a moment. '
                .'If it keeps happening, the mail service itself is likely to be having '
                .'trouble. (%s)',
                $address,
                $detail,
            ),
            isOurs: false,
            status: Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * EzBizi itself has nothing behind this provider on this installation.
     *
     * `isOurs: true`, and the sentence says so, because the tenant can do
     * absolutely nothing about it and must not be sent to check their password.
     * This is the honest state of an installation with no Google OAuth client
     * registered and no IMAP extension compiled in, and it is what
     * {@see UnconfiguredMailboxReader} answers.
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
            isOurs: true,
            status: Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    public function status(): int
    {
        return $this->status;
    }
}
