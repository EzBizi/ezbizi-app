<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody should know about (plan §12).
 *
 * Addressed to a USER, not a role or a franchise. Roles change and assignments
 * change; a notification is about a moment, and re-deriving its audience later
 * would silently change who had been told what.
 */
class Notification extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    // ── §9 data inventory ────────────────────────────────────────

    /*
     * "Notification history" is another of §9's named hiding places, and this
     * table earns the listing for a specific reason: the body is composed from
     * whatever raised it. A rule that says "tell the manager when a customer's
     * details change" writes that customer's details into a row addressed to
     * somebody else — so personal data about A ends up filed under B, where an
     * erasure request about A would never think to look.
     */
    public static function personalDataPurpose(): string
    {
        return 'Things somebody was told, kept so the app can show what they have and have '
            .'not seen. A notification is addressed to one person and is often ABOUT '
            .'another.';
    }

    /**
     * Six months, replacing "there is no automatic expiry today".
     *
     * That sentence was accurate and was not a policy — it described the absence
     * of one. This table takes a row every time any automation tells anybody
     * anything, which makes it the fastest-growing table in the system that a
     * person can read, and every row can name a third party who never agreed to
     * appear in it.
     *
     * Six months rather than the three the audit log gets, because these are read
     * by people rather than by investigators: "what was I told about that job in
     * the spring" is asked by somebody scrolling their own list, and a quarter is
     * short enough to lose the previous season. Six is also long enough that
     * nothing in the product needs to warn anybody it is coming.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(6, 'created_at',
            'Kept after being read, so the list is a history rather than a queue — and then '
            .'removed, because a notification is about a moment and stops being useful long '
            .'before it stops naming whoever it was about.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Who was told, and — through read_at — whether they have seen it.',
                Erasure::drop('A notification is addressed to exactly one person. With that '
                    .'person erased there is nobody it was for, and the column cannot be '
                    .'blanked anyway — it is NOT NULL and it is what the list is queried '
                    .'by, so an anonymised notification would be one nobody could ever '
                    .'read or delete.')),
            new PersonalDataEntry('title, body', PersonalDataEntry::CONTENT,
                'The message. Composed by whatever raised it, so it can contain the name '
                .'or details of a third party rather than of the person it was sent to.',
                Erasure::drop('Free text with no schema, which is why it cannot be '
                    .'selectively cleaned: nothing can tell which words in it are a name. '
                    .'It goes with the row above.')),
        ];
    }

    /**
     * Notifications sent TO this person.
     *
     * Only those. A notification whose BODY happens to name them is not findable
     * — nothing indexes free text for personal names, and pretending otherwise
     * would be the misleading kind of thoroughness. The Anonymiser publishes that
     * gap as a caveat rather than leaving it to be discovered.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    public const UPDATED_AT = null;   // read_at is the only mutation

    protected $fillable = [
        'organization_id', 'user_id', 'source', 'kind', 'title', 'body',
        'subject_type', 'subject_id', 'automation_id', 'event_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'urgent' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
