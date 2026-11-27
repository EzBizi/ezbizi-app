<?php

namespace App\Support\Email;

use RuntimeException;

/**
 * Something went wrong BELOW the reader — on the socket, or in what the mail
 * server said back.
 *
 * ── Why this is not CannotReadMailbox ────────────────────────────
 *
 * Because {@see CannotReadMailbox}'s factories all take a {@see \App\Models\Mailbox},
 * and the socket does not have one and should not: a transport that knew about
 * the model could not be tested without the database, and the thing most worth
 * testing here is precisely the part that has nothing to do with a mailbox row.
 *
 * So the transport throws this, carrying a KIND rather than an HTTP status, and
 * {@see ImapMailboxReader::translate} turns the kind into the right
 * CannotReadMailbox. The mapping lives in one method there — which means a new
 * failure shape is one case in one `match`, and the reader stays the only place
 * that knows what a mailbox is.
 *
 * ── Why the kind is not the sentence ─────────────────────────────
 *
 * The sentence here is written for whoever is reading a log or a stack trace: it
 * names ports, sizes and protocol states. What the PERSON sees is composed by
 * CannotReadMailbox from {@see MailboxFault}, which is written for them (R8).
 * Publishing this text on screen would be the mistake MailboxFault's own docblock
 * describes — a status page full of somebody else's vocabulary.
 */
final class ImapFailure extends RuntimeException
{
    /** Nothing answered in time. The mailbox is probably fine; try later. */
    public const TIMEOUT = 'timeout';

    /** We could not get a safe connection at all — no TLS, bad certificate, refused socket. */
    public const UNREACHABLE = 'unreachable';

    /** The server answered, and what it said was not IMAP, or was abusive. */
    public const MISBEHAVED = 'misbehaved';

    /** The server understood us perfectly and refused the username or password. */
    public const REJECTED = 'rejected';

    private function __construct(
        string $message,
        /** One of the four constants above. */
        public readonly string $kind,
    ) {
        parent::__construct($message);
    }

    public static function timedOut(string $doing): self
    {
        return new self('The mail server stopped answering while '.$doing.'.', self::TIMEOUT);
    }

    /** The connection did not open: a blip until it keeps happening. */
    public static function notAnswering(string $why): self
    {
        return new self($why, self::TIMEOUT);
    }

    public static function unreachable(string $why): self
    {
        return new self($why, self::UNREACHABLE);
    }

    public static function misbehaved(string $why): self
    {
        return new self($why, self::MISBEHAVED);
    }

    public static function rejected(string $why): self
    {
        return new self($why, self::REJECTED);
    }
}
