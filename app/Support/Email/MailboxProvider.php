<?php

namespace App\Support\Email;

/**
 * Where a shared inbox actually lives.
 *
 * Four ways in, and they are not equals. Per-inbox Google OAuth is what a
 * customer should be steered to for a single address; Google Workspace is the
 * domain-wide grant for companies that would rather connect once and pick
 * shared inboxes from the Directory; Microsoft 365 is the Outlook equivalent of
 * per-inbox Google; IMAP exists because not everybody is on those, and it is the
 * one that asks somebody to hand us a password.
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
     * Google Workspace, over domain-wide delegation.
     *
     * A Workspace administrator connects the DOMAIN once. After that, shared
     * addresses on that domain are opened by impersonating them with EzBizi's
     * service account — nobody signs in as info@, and no mailbox password is
     * stored. The grant is larger than per-inbox OAuth (it can open any address
     * the Directory lists), which is why connecting it is its own act, proved
     * by an admin Google sign-in, and bound uniquely to one EzBizi organization.
     *
     * Personal mailboxes are not imported. An administrator picks the shared
     * addresses this module is for.
     */
    case GmailWorkspace = 'gmail_workspace';

    /**
     * Microsoft 365, over OAuth. Per-inbox, like Google — not a domain-wide grant.
     *
     * Shared team inboxes only. Do not connect a person's own mailbox.
     */
    case MicrosoftOauth = 'microsoft_oauth';

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
            self::GmailOAuth => 'Google sign-in (retired)',
            self::GmailWorkspace => 'Google Workspace',
            self::MicrosoftOauth => 'Microsoft 365',
            self::Imap => 'IMAP',
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
            self::GmailOAuth, self::GmailWorkspace, self::MicrosoftOauth => false,
            self::Imap => true,
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
            self::GmailWorkspace => 'Connect the company\'s Google Workspace once, then pick '
                .'shared addresses like info@ from the directory. Do not pick a person\'s own '
                .'mailbox — this module is for team inboxes. A Workspace administrator has to '
                .'allow EzBizi in Google Admin (Security → Domain-wide delegation) first.',
            self::MicrosoftOauth => 'Connect a shared Outlook address, not a person\'s own '
                .'mailbox — this module is for team inboxes. Microsoft 365 has to be set up '
                .'on this installation first.',
            self::Imap => 'Connecting by IMAP means storing the mailbox password here, '
                .'and for most mail services that is the account password — the same one that '
                .'opens everything else on that account. If this address is on Google, connect '
                .'it with Google instead: that hands over a key for mail only, and it can be '
                .'taken back without changing anybody\'s password.',
        };
    }

    /**
     * How this provider is finished, published rather than inferred.
     *
     * The connect form used to guess from {@see needsHost}: a host meant a
     * password, no host meant a sign-in. Google Workspace has no host and is
     * not finished by signing in as the inbox — it is finished by picking the
     * address from the Directory after the domain is connected. A third value
     * is cheaper than teaching every client a new exception.
     */
    public function connectsBy(): string
    {
        return match ($this) {
            self::GmailOAuth => 'sign_in',
            self::GmailWorkspace => 'directory',
            self::MicrosoftOauth => 'sign_in',
            self::Imap => 'password',
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
