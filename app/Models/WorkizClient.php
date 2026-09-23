<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One client on a branch's Workiz account, derived from that branch's jobs.
 *
 * A mirror of what Workiz reports, plus one column that is EzBizi's own:
 * `customer_id`, the customer this became. The projection sets it once and
 * nothing resets it.
 */
#[Fillable([
    'franchise_id', 'connection_id', 'client_id', 'name', 'company', 'email',
    'phone', 'phone_digits', 'address', 'unit', 'city', 'state', 'postal_code',
    'country', 'customer_id', 'from_remote_job_id', 'last_seen_at', 'synced_at',
])]
class WorkizClient extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The people and businesses a branch works for, as its Workiz account records them. '
            .'Workiz has no client record of its own, so this is reconstructed from the contact '
            .'details carried on every job, and it is what an imported customer is created from.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept on the same terms as the `customers` row projected from it and the `workiz_jobs` '
            .'rows it was derived from — deleting it would strand an imported customer with no '
            .'record of where they came from, and would let the projection mint a second one on '
            .'the next run. Erasing the customer erases this alongside it.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name, company', PersonalDataEntry::IDENTITY,
                'Who the work is for, as Workiz recorded them.',
                Erasure::erase('Removed client')),
            new PersonalDataEntry('email, phone, phone_digits', PersonalDataEntry::CONTACT,
                'Arranging the work, and the only fields that can match this person to a '
                .'customer EzBizi already holds.',
                Erasure::erase(null)),
            new PersonalDataEntry('address, unit, city, state, postal_code, country',
                PersonalDataEntry::CONTACT,
                'Where the work happens. Usually a home address, so personal even though the '
                .'record is a commercial one.',
                Erasure::erase(null)),
        ];
    }

    /**
     * Rows about a customer.
     *
     * The link is the only honest answer: a Workiz client becomes exactly one
     * EzBizi customer and the projection records which. Matching on name or
     * phone instead would reach rows about a DIFFERENT person who happens to
     * share a household line — which is the same reason Jose refused
     * auto-merging on phone in the first place.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer
            ? static::query()->where('customer_id', $subject->getKey())
            : null;
    }
}
