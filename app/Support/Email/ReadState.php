<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Mailbox;
use App\Models\MessageSeen;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who a "seen" mark belongs to — everybody, or only the person who made it.
 *
 * The owner: *"perhaps we can let the organization choose in settings if it's
 * shared or not shared view."* So it is a cascading setting like any other —
 * `email.read_state`, resolved through the §6 chain: franchise → owning company
 * → parent company → organization → the registry default.
 *
 * ── One stored shape, two readings ───────────────────────────────
 *
 * {@see MessageSeen} rows are identical under both settings. SHARED reads all of
 * them for a message and prints the names; PRIVATE reads only the caller's own.
 * That is what lets an organization change its mind without throwing away what
 * people have already read — a boolean `is_read` column could not do it, and
 * nor could deleting other people's marks when the setting flips, which would
 * be destroying data to express a display preference.
 *
 * ── Resolved at the MAILBOX'S level, not the reader's ────────────
 *
 * A mailbox hangs off one of §3's three levels, and that is the level whose
 * setting governs it. Resolving at the READER'S level instead would mean two
 * colleagues looking at the same inbox seeing different things — one sees "Seen
 * by Jenny" and the other sees nothing — because they happen to be assigned to
 * different branches. The inbox is one shared thing and it behaves one way.
 */
final class ReadState
{
    /** The setting, named once. */
    public const SETTING = 'email.read_state';

    public const SHARED = 'shared';

    public const PRIVATE = 'private';

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** Is this inbox's read state shared with the team? */
    public function isShared(Mailbox $mailbox): bool
    {
        $scope = $this->scopeOf($mailbox);

        if ($scope === null) {
            // The level the mailbox names has gone — a company deleted out from
            // under it. SHARED is the safe answer here rather than the private
            // one: it shows MORE to the colleagues who share the inbox and
            // nothing to anybody outside it, since reaching this code at all
            // already required a grant and reach.
            return true;
        }

        return $this->settings->get(self::SETTING, $scope) !== self::PRIVATE;
    }

    /**
     * Mark a message seen by this person. Idempotent.
     *
     * `firstOrCreate` against the unique key on (mailbox, message, user), so
     * opening the same message twice writes one row and two browser tabs racing
     * cannot write two. The database index is what actually guarantees that; the
     * method is what avoids the exception in the ordinary case.
     */
    public function markSeen(Mailbox $mailbox, string $providerMessageId, User $user): MessageSeen
    {
        return MessageSeen::firstOrCreate(
            [
                'mailbox_id'          => $mailbox->getKey(),
                'provider_message_id' => $providerMessageId,
                'user_id'             => $user->getKey(),
            ],
            [
                'organization_id' => $mailbox->organization_id,
                'seen_at'         => now(),
            ],
        );
    }

    /**
     * This person is no longer marked as having seen it.
     *
     * Only THEIR row. A shared inbox's "Seen by Marta" is Marta's mark, and
     * taking it off for everybody because one person clicked unread would
     * be rewriting history they did not ask to rewrite.
     */
    public function forgetSeen(Mailbox $mailbox, string $providerMessageId, User $user): void
    {
        MessageSeen::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('provider_message_id', $providerMessageId)
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * Who has seen each of these messages — the names the list prints.
     *
     * ── One query for the whole page ─────────────────────────────
     *
     * Asked for every id at once rather than per row. A list of twenty-five
     * messages asking individually is twenty-five queries plus a user lookup
     * each, which is how a mail list becomes the slowest screen in the
     * application.
     *
     * ── "You" rather than the reader's own name ──────────────────
     *
     * The caller's own mark reads as "You", because a list telling Jenny that
     * Jenny has seen something is a list making her work out that Jenny is
     * herself. It is also the commonest entry by far.
     *
     * @param  string[]  $providerMessageIds
     * @return array<string, string[]> message id => names, in a stable order
     */
    public function seenBy(Mailbox $mailbox, array $providerMessageIds, User $reader): array
    {
        if ($providerMessageIds === []) {
            return [];
        }

        $shared = $this->isShared($mailbox);

        $rows = MessageSeen::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->whereIn('provider_message_id', $providerMessageIds)
            /*
             * PRIVATE narrows the QUERY rather than filtering afterwards.
             *
             * Filtering a full result set in PHP would mean other people's names
             * had already been loaded into the response object, one careless
             * `->toArray()` away from the screen. Narrowing here means they are
             * never fetched.
             */
            ->when(! $shared, fn ($q) => $q->where('user_id', $reader->getKey()))
            ->with('user:id,name')
            ->orderBy('seen_at')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $name = $row->user_id === $reader->getKey()
                ? 'You'
                : (string) ($row->user?->name ?? 'Somebody who has since left');

            $out[$row->provider_message_id] ??= [];

            if (! in_array($name, $out[$row->provider_message_id], strict: true)) {
                $out[$row->provider_message_id][] = $name;
            }
        }

        return $out;
    }

    /**
     * What the screen says about this inbox's read marks, in words.
     *
     * Published with the mailbox so a client never has to compose the
     * explanation itself — and so the two possible states are worded once rather
     * than once per screen that shows them.
     */
    public function explain(Mailbox $mailbox): string
    {
        return $this->isShared($mailbox)
            ? 'Everybody with access to this inbox can see who has opened each message.'
            : 'Read marks here are yours alone — colleagues cannot see what you have opened, '
                .'and you cannot see what they have.';
    }

    /**
     * The level this mailbox hangs off, as a model the §6 cascade can walk.
     *
     * Tenant-scoped lookups, so a scope_id belonging to another organization is
     * simply not found and the caller falls into the null branch above rather
     * than resolving somebody else's setting.
     */
    private function scopeOf(Mailbox $mailbox): ?Model
    {
        return match ($mailbox->scope_type) {
            Mailbox::SCOPE_ORGANIZATION => $this->tenant->get()?->getKey() === $mailbox->scope_id
                ? $this->tenant->get()
                : null,
            Mailbox::SCOPE_COMPANY   => Company::find($mailbox->scope_id),
            Mailbox::SCOPE_FRANCHISE => Franchise::find($mailbox->scope_id),
            default                  => null,
        };
    }

    /**
     * Attach the names to a page of summaries.
     *
     * @param  Collection<int, MessageSummary>|MessageSummary[]  $messages
     * @return MessageSummary[]
     */
    public function decorate(Mailbox $mailbox, iterable $messages, User $reader): array
    {
        $messages = is_array($messages) ? $messages : iterator_to_array($messages);

        $seen = $this->seenBy(
            $mailbox,
            array_map(fn (MessageSummary $m) => $m->id, $messages),
            $reader,
        );

        return array_map(
            fn (MessageSummary $m) => $m->withSeenBy($seen[$m->id] ?? []),
            $messages,
        );
    }
}
