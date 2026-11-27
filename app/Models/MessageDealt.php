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
 * "This message has been handled."
 *
 * ── The distinction from {@see MessageSeen}, which is the point ──
 *
 *     message_seen    (mailbox, message, USER)  Jenny looked at it
 *     message_dealt   (mailbox, message)        somebody handled it
 *
 * Seen is per person: it is what lets a shared inbox print "Seen by Jenny", and
 * two colleagues genuinely disagree about it, because only one of them looked.
 * Dealt-with is a statement about the CUSTOMER'S message — answered, or needed
 * nothing — and there is no version of it that is true for one colleague and
 * false for another. So it is one row for the inbox, not one per reader.
 *
 * ── Why an automation may write HERE and not to message_seen ─────
 *
 * This table exists because a rule was asked for that marks mail handled, and
 * the obvious implementation — writing a `message_seen` row — would have made
 * "Seen by" assert that a person read something nobody read. A colleague would
 * then skip a customer's mail on the strength of a name that was never there.
 *
 * A rule has no eyes, so it cannot have seen anything; it can perfectly well
 * have DEALT WITH something. Splitting the two facts is what lets the automation
 * exist without corrupting the one signal a shared inbox depends on.
 *
 * ── It holds an opaque id, deliberately ──────────────────────────
 *
 * `provider_message_id` and nothing else about the message: no subject, no
 * sender, no date. Same rule as MessageSeen — this module does not mirror mail,
 * and a mirror is built by accretion, one individually defensible column at a
 * time. A row here is meaningless without asking the provider.
 *
 * ── Not `HoldsPersonalData`, same reasoning as MessageSeen ───────
 *
 * `dealt_by_user_id` POINTS AT an account rather than describing a person — the
 * shape of `audit_events.user_id`, which is not in the inventory either. Nothing
 * here is a fact about anybody, and the row goes with the user by the cascade.
 * It is nullable on top of that, because a rule has no user at all.
 */
class MessageDealt extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A message marked dealt with is its mailbox's change on the live feed. */
    protected static array $changeParent = ['mailboxes', Mailbox::class, 'mailbox_id'];

    use HasFactory;
    use HasUuids;

    /** `message_dealt`, not `message_dealts`. The table is named for what one row IS. */
    protected $table = 'message_dealt';

    /**
     * `dealt_at` IS the time, so there is no second one — the same argument
     * MessageSeen makes about `seen_at`. Nothing here is ever updated: undoing
     * it deletes the row, because "not dealt with" is the absence of the fact
     * rather than a second state of it.
     */
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'mailbox_id', 'provider_message_id', 'dealt_by_user_id', 'dealt_at',
    ];

    protected function casts(): array
    {
        return ['dealt_at' => 'datetime'];
    }

    /**
     * Bounded by its parents, like MessageSeen, and smaller than it.
     *
     * At most ONE row per message here, where `message_seen` takes one per
     * reader per message — so a mailbox four people watch produces up to a
     * quarter of the rows. Everything else is the same: it cascades with the
     * mailbox and with the user, nothing outlives what it points at, and there
     * is no age at which "this was handled" becomes untrue.
     *
     * The same honest caveat applies: no age limit bounds it while a mailbox
     * stays connected. If a window is ever wanted it belongs in
     * {@see \App\Support\Privacy\RetentionPolicy} beside the others, decided in
     * one place, not half-declared here.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'One small row saying that a message in a shared inbox has been handled, so the '
            .'team can tell what still needs answering. It holds the mail service\'s own '
            .'reference for the message and nothing else — no subject, no sender, no content, '
            .'and no part of the message itself, which is never stored. It records who marked '
            .'it, or nothing at all when an automation did. It is removed when the mailbox is '
            .'disconnected, and it grows with how much mail a connected inbox receives.'
        );
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** Null when a rule did it rather than a person. */
    public function dealtBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dealt_by_user_id');
    }
}
