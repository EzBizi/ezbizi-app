<?php

namespace App\Support\Email;

/**
 * Which folder on THIS server a label means.
 *
 * ── Why this is a class and not four lines in the reader ─────────
 *
 * The same reason {@see ReplyComposer} is one: it is the half of the folder
 * path that can be exercised without a mail server. {@see ImapMailboxReader}
 * needs a socket, a certificate and a login before it reaches this decision,
 * and on Windows it cannot get one at all — so a resolver living inside it is
 * a resolver nothing can assert about on the machine most of this was written
 * on.
 *
 * What is left in the reader is the part that genuinely needs the connection:
 * asking the server for its folder list, and opening the one this returns.
 *
 * ── Two ways to recognise a folder, in order ─────────────────────
 *
 * SPECIAL-USE first (RFC 6154). `\Sent` is the server saying what a folder is
 * FOR, which is right by definition and works on a server whose folders are
 * named in Portuguese. Not every server implements it.
 *
 * Names second, and only as a fallback. `Sent`, `Sent Items`, `Sent Messages`
 * — every spelling in common use, because the alternative to recognising
 * `Sent Items` is telling an Exchange customer they have no Sent folder.
 */
final class ImapFolders
{
    /**
     * The labels this can look for, and how each is recognised.
     *
     * `DRAFT` and `DRAFTS` both appear because the client sends `DRAFT` and
     * the API is public enough that somebody will send the plural. Two keys
     * cost nothing; a refusal over an `S` costs a support message.
     */
    private const KNOWN = [
        'SENT'    => ['use' => '\\Sent',    'names' => ['sent', 'sent items', 'sent messages', 'sent mail']],
        'DRAFT'   => ['use' => '\\Drafts',  'names' => ['drafts', 'draft']],
        'DRAFTS'  => ['use' => '\\Drafts',  'names' => ['drafts', 'draft']],
        'TRASH'   => ['use' => '\\Trash',   'names' => ['trash', 'deleted items', 'deleted messages', 'bin']],
        'SPAM'    => ['use' => '\\Junk',    'names' => ['spam', 'junk', 'junk e-mail', 'junk email']],
        'ARCHIVE' => ['use' => '\\Archive', 'names' => ['archive', 'archives']],
    ];

    /** Every label this knows how to look for. */
    public static function labels(): array
    {
        return array_keys(self::KNOWN);
    }

    /**
     * The server's name for this label, or null if it has no such folder.
     *
     * ── Null is an answer, and it is not "the Inbox" ─────────────
     *
     * A server with no Archive folder must produce a refusal that says so.
     * Substituting the Inbox is the failure this module keeps finding — it
     * succeeds, it displays, and somebody reads received mail believing it is
     * what they sent. The caller turns null into a sentence; there is no
     * arrangement of arguments that turns it into a different folder.
     *
     * @param  list<array{name: string, attributes: list<string>}>  $folders  from LIST
     */
    public static function resolve(array $folders, string $label): ?string
    {
        $label = strtoupper(trim($label));

        // Every IMAP server has INBOX under exactly that name; it is the one
        // folder named in the protocol itself.
        if ($label === 'INBOX') {
            return 'INBOX';
        }

        $wanted = self::KNOWN[$label] ?? null;

        if ($wanted === null) {
            return null;
        }

        foreach ($folders as $folder) {
            foreach ($folder['attributes'] ?? [] as $attribute) {
                if (strcasecmp($attribute, $wanted['use']) === 0) {
                    return $folder['name'];
                }
            }
        }

        foreach ($folders as $folder) {
            if (in_array(self::leaf($folder['name']), $wanted['names'], true)) {
                return $folder['name'];
            }
        }

        return null;
    }

    /**
     * Which of the known labels this server actually HAS.
     *
     * So the screen can draw the folders that exist rather than six that might,
     * and a person is not offered Archive on a server without one — an offer
     * that resolves to a refusal is the same broken promise as a disabled
     * button, made after the click instead of before it.
     *
     * @param  list<array{name: string, attributes: list<string>}>  $folders
     * @return list<string>
     */
    public static function available(array $folders): array
    {
        $found = ['INBOX'];

        foreach (['SENT', 'DRAFT', 'TRASH', 'SPAM', 'ARCHIVE'] as $label) {
            if (self::resolve($folders, $label) !== null) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /**
     * The last segment of a folder path, lower-cased.
     *
     * `INBOX.Sent` and `INBOX/Sent` are both the Sent folder. The hierarchy
     * separator is whatever the server declared in its LIST response, and both
     * characters in use are tried rather than parsing that out — a folder
     * genuinely named `a.b` under a `/` server is not a case worth the extra
     * plumbing, and it fails safe by not matching rather than by matching
     * something else.
     */
    private static function leaf(string $name): string
    {
        $leaf = strtolower($name);

        foreach (['/', '.'] as $separator) {
            $at = strrpos($leaf, $separator);

            if ($at !== false) {
                $leaf = substr($leaf, $at + 1);
            }
        }

        return $leaf;
    }
}
