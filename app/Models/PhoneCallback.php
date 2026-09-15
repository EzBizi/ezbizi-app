<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody who stopped holding because we promised to ring them back.
 *
 * Press 1 in the queue, hang up, keep your place. Asked for by Jose from
 * RingCentral, and named in `QueueWaitWebhookController`'s docblock as the
 * thing deliberately not built — because honouring a promise needs somewhere
 * to keep it. This is that place.
 *
 * ── Why this is not just a missed call ───────────────────────────
 *
 * A missed call is a fact about the past. This is an OBLIGATION: a person was
 * told, in so many words, that we would ring them. That is why it is a row
 * a screen CAN show rather than a job in Redis nothing can — it survives a
 * worker restart and it can be counted.
 *
 * ponytail: no screen shows them yet. The promise is kept automatically when
 * somebody becomes available, which is the path that matters; what is missing
 * is the fallback for a queue where nobody ever does, and the place for it is
 * the waiting strip on the Dial tab beside parked and ringing calls.
 */
class PhoneCallback extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'number_id', 'ring_group_id',
        'to_e164', 'position', 'requested_at', 'taken_at', 'taken_by',
        'placed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'requested_at' => 'datetime',
            'taken_at' => 'datetime',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    /**
     * Still owed: nobody has placed it and nobody has cancelled it.
     *
     * `taken_at` is deliberately NOT part of this. A desk that claimed a
     * callback and then crashed before placing it would otherwise take the
     * promise out of the world silently — see [claimable], which is the query
     * that does exclude a live claim, and does it with a timeout.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('placed_at')->whereNull('cancelled_at');
    }

    /**
     * Owed, and not already being placed by somebody else right now.
     *
     * Two desks becoming free in the same second would otherwise both ring the
     * same customer. Being rung twice by a company you are waiting on reads
     * worse than waiting slightly longer, so the claim is a row update and the
     * loser of the race finds nothing to do.
     *
     * The two-minute floor is what stops a crashed claim holding a promise for
     * ever: a desk that took one and died leaves `taken_at` set and
     * `placed_at` null, and after two minutes the next free desk may have it.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->outstanding()->where(
            fn (Builder $q) => $q
                ->whereNull('taken_at')
                ->orWhere('taken_at', '<', now()->subMinutes(2)),
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'Requests to be rung back: the number of somebody who was waiting on the '
            .'phone, chose not to keep waiting, and was told we would call them.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Matches PhoneCall, which is what this becomes: the promise is only '
            .'interesting beside the call that kept it, and it names an outside '
            .'number for exactly as long.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('to_e164', PersonalDataEntry::CONTACT,
                'The number we promised to ring. An outside person who was waiting on '
                .'the phone and chose not to keep waiting.',
                Erasure::drop('The row is nothing but that promise and the number it is '
                    .'owed to. Blanking the number leaves an obligation to nobody.')),
            new PersonalDataEntry('taken_by', PersonalDataEntry::IDENTITY,
                'Which employee picked the callback up to place it, when one has.',
                Erasure::keep('A foreign key to the account the purge anonymises at its '
                    .'home. Nulling it here would orphan the record of who acted without '
                    .'removing anything about them.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if ($subject instanceof User) {
            return static::query()->where('taken_by', $subject->id);
        }

        return null;
    }
}
