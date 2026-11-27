<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Somebody here has looked at this message."
 *
 * The owner asked for a plain shared view with a *"Seen by X"* indicator rather
 * than claiming or assignment — ownership of a thread was considered and
 * deferred. This is that indicator, and it is the smallest record that can carry
 * it: which mailbox, which message at the provider, which person, when.
 *
 * ── One shape, read two ways ─────────────────────────────────────
 *
 * Whether a read mark is EVERYBODY'S or only yours is an organization setting
 * that cascades like any other — `email.read_state`, and
 * App\Support\Email\ReadState is what resolves it. The rows are identical either
 * way: a shared inbox reads all of them and prints the names, a private one
 * reads only the caller's. Storing one shape and interpreting it at read time is
 * what lets an organization change its mind without throwing away what people
 * have already read, which a boolean `is_read` column on a message could not do.
 *
 * ── It holds an opaque id, and that is the whole design ──────────
 *
 * `provider_message_id` is Gmail's id or an IMAP UID qualified by folder. There
 * is deliberately no subject, no sender and no date: this table must not become
 * the message mirror decision 2 forbids, and the way that happens is by
 * accretion — one useful column at a time, each individually defensible. A row
 * here is meaningless without asking the provider, which is exactly right.
 *
 * ── Not `HoldsPersonalData`, and the reasoning ───────────────────
 *
 * `user_id` names an employee, which is why the question has to be asked rather
 * than waved past. It is a POINTER at a person's account, not a fact about them:
 * the same shape as `audit_events.user_id` and `notifications.user_id`, neither
 * of which is in the inventory either. Nothing here describes anybody — no name,
 * no address, no contact detail — and the row goes with the user by the cascade
 * when the account does. What the message SAYS is personal data belonging to
 * whoever wrote it, and none of it is stored.
 */
class MessageSeen extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** Somebody reading a message is its mailbox's change on the live feed (the inbox shows who saw it). */
    protected static array $changeParent = ['mailboxes', Mailbox::class, 'mailbox_id'];

    use HasFactory;
    use HasUuids;

    /**
     * `message_seen`, not `message_seens`.
     *
     * Laravel pluralises the class name and would produce the second, which is
     * not a word. The table is named for what one row IS.
     */
    protected $table = 'message_seen';

    /**
     * `seen_at` IS the time, so there is no second one.
     *
     * A `created_at` beside it would say the same thing and could disagree —
     * and nothing here is ever updated, so `updated_at` would be a column that
     * is always equal to another column.
     */
    public $timestamps = false;

    protected $fillable = ['organization_id', 'mailbox_id', 'provider_message_id', 'user_id', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /**
     * The one table in this module whose size follows MAIL VOLUME.
     *
     * ── Why `withParent` and not a window, stated honestly ───────
     *
     * `withParent` is TRUE: `mailbox_id` cascades, so disconnecting a mailbox
     * removes every mark against it, and `user_id` cascades, so a deleted
     * account takes its own with it. Nothing here outlives the things it points
     * at, and there is no age at which "Jenny opened this" becomes wrong.
     *
     * What it does NOT do is bound the table while a mailbox stays connected,
     * and that is the honest caveat rather than a hidden one. A busy info@
     * mailbox with four people watching it produces up to four rows per message,
     * indefinitely. The row is small — two ids, an id and a timestamp, no text —
     * so a mailbox taking a hundred messages a day accumulates on the order of a
     * hundred and fifty thousand rows a year, which is nothing beside the
     * `audit_events` table; but it grows, and a table that grows deserves to be
     * named rather than described as bounded.
     *
     * A window is the obvious next answer and it is a real decision rather than
     * a formality: "seen" on a two-year-old message is not information anybody
     * uses, so twelve months would cost nothing — but declaring one puts this
     * table under the nightly sweep, and the sweep's own documentation
     * enumerates every table it governs. That enumeration lives in
     * App\Support\Privacy\RetentionPolicy, which this change does not own. The
     * window belongs there, decided in one place, rather than half-declared
     * here.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'One small row saying that a named colleague opened a particular message in a '
            .'shared inbox, so the rest of the team can see it has been looked at. It holds '
            .'the mail service\'s own reference for the message and nothing else — no subject, '
            .'no sender, no content, and no part of the message itself, which is never stored. '
            .'It is removed when the mailbox is disconnected and when the person\'s account is '
            .'deleted. It grows with how much mail a connected inbox receives, which is worth '
            .'saying plainly: there is no age limit on a mark today.'
        );
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
