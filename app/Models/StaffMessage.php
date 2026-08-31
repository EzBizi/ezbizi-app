<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One colleague writing to another inside EzBizi.
 *
 * Not SMS, not customer messaging: both ends are staff, and it never leaves
 * the organization.
 */
class StaffMessage extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'sender_id', 'recipient_id', 'body', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * ── Why two years and not forever ────────────────────────────
     *
     * This is people talking. Not a business record — nobody invoices from it,
     * nothing is audited against it, and a message asking whether the van is
     * back has no value eighteen months later. Keeping it forever would mean
     * an organization accumulating every aside its staff ever typed, readable
     * by anyone who later gains access to those accounts.
     *
     * Two years is long enough that "what did we agree in the spring" is still
     * answerable, and short enough that this does not quietly become a
     * permanent record of everybody's working conversation.
     *
     * A window rather than withParent, deliberately: the parent here is the
     * ORGANIZATION, and tying it to that would mean never — the organization
     * is the one thing that does not go away.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(
            24,
            'created_at',
            'Messages between colleagues inside EzBizi — who wrote to whom, '
            .'what they said, and when it was read. Kept for two years and '
            .'then deleted, because it is a working conversation rather than a '
            .'business record.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'Messages colleagues send each other inside EzBizi — who wrote '
            .'to whom, what they said, and when it was read. It is a working '
            .'conversation between staff, never sent to a customer and never '
            .'leaving the organization.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry(
                'body',
                PersonalDataEntry::CONTENT,
                'Whatever one colleague typed to another. Nobody can predict '
                .'what is in it, which is precisely why it counts.',

                /*
                 * Erased rather than dropped, and this is the interesting half.
                 *
                 * A message has TWO people in it. Dropping the row to erase the
                 * sender would also delete it from the recipient's thread —
                 * taking away a conversation belonging to somebody who has
                 * asked for nothing. Blanking the words leaves the fact that a
                 * message arrived, which is the recipient's to keep.
                 */
                Erasure::erase('Removed'),
            ),
            new PersonalDataEntry(
                'sender_id, recipient_id',
                PersonalDataEntry::IDENTITY,
                'Which colleague wrote, and which one it was for.',
                Erasure::keep('Both ends are staff records that go when the '
                    .'person does — the foreign keys cascade, so erasing a '
                    .'colleague removes their side without this table having '
                    .'to decide anything.'),
            ),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        // Either end. A message is about the person who wrote it AND the
        // person it was written to, and answering only for the sender would
        // under-report by half.
        return $subject instanceof User
            ? static::query()
                ->where('sender_id', $subject->getKey())
                ->orWhere('recipient_id', $subject->getKey())
            : null;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * The conversation between two people, in both directions.
     *
     * A thread is the pair, not a row: whichever of them wrote it, it belongs
     * in the same list.
     */
    public function scopeBetween(Builder $query, string $a, string $b): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->where('sender_id', $a)->where('recipient_id', $b))
            ->orWhere(fn (Builder $w) => $w->where('sender_id', $b)->where('recipient_id', $a)));
    }
}
