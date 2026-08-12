<?php

namespace App\Support\Email;

/**
 * Where a shared inbox actually lives.
 *
 * Both were decided, and they are not equals. OAuth is what a customer should be
 * steered to; IMAP exists because not everybody is on Google, and it is the one
 * that asks somebody to hand us a password.
 */
enum MailboxProvider: string
{
    /**
     * Google, over OAuth. The one to prefer, and the screen should say so.
     *
     * What we hold is a REFRESH TOKEN, not a password: it is scoped to the mail
     * scopes the consent screen named, it can be revoked from the account's own
     * security page without changing anybody's password, and it never gives us
     * the ability to sign in as that person anywhere else. None of that is true
     * of the alternative.
     */
    case GmailOAuth = 'gmail_oauth';

    /**
     * Anything that speaks IMAP, with a username and a password.
     *
     * Two things are worse here and both are worth saying on the screen rather
     * than only in this file. The credential is usually THE account password —
     * so a copy of it in our database is a copy of everything that account can
     * reach, mail and otherwise — and revoking it means changing that password,
     * which breaks every other device the person owns. And the HOST is chosen by
     * the tenant, which means our server dials somewhere they picked: see
     * {@see RemoteHostGuard} for what that is guarded against and why the guard
     * resolves the name itself rather than trusting the string.
     */
    case Imap = 'imap';

    public function label(): string
    {
        return match ($this) {
            self::GmailOAuth => 'Google (recommended)',
            self::Imap       => 'IMAP',
        };
    }

    /** Does connecting this one mean storing a password rather than a token? */
    public function storesAPassword(): bool
    {
        return match ($this) {
            self::GmailOAuth => false,
            self::Imap       => true,
        };
    }

    /**
     * Does this provider need a host, port and encryption from the tenant?
     *
     * The same question as "is this the SSRF surface", asked in the words of the
     * form rather than the words of the threat — the form is where the answer is
     * used, and RemoteHostGuard is where the threat is handled.
     */
    public function needsHost(): bool
    {
        return match ($this) {
            self::GmailOAuth => false,
            self::Imap       => true,
        };
    }

    /**
     * What to say beside the choice, so nobody picks IMAP by accident.
     *
     * R8: it names the consequence and what to do, and it does not name a class,
     * a permission or a protocol constant the reader has no use for.
     */
    public function caution(): ?string
    {
        return match ($this) {
            self::GmailOAuth => null,
            self::Imap       => 'Connecting by IMAP means storing the mailbox password here, '
                .'and for most mail services that is the account password — the same one that '
                .'opens everything else on that account. If this address is on Google, connect '
                .'it with Google instead: that hands over a key for mail only, and it can be '
                .'taken back without changing anybody\'s password.',
        };
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }

    public static function tryFromStored(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
