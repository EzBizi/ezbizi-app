<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A customer (plan §5.2).
 *
 * SHARED across the organization — readable everywhere, editable from any
 * franchise — because a customer served by two franchises is one person, and
 * duplicating them creates two half-histories of one relationship.
 *
 * Sharing is why the owning-franchise tag exists. Somebody has to be accountable
 * for the relationship and countable in a roll-up, and "whoever touched them
 * last" is not an answer.
 */
#[Fillable([
    'owning_franchise_id', 'number', 'name', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code',
    'notes', 'is_active',
])]
class Customer extends Model implements HoldsPersonalData
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Addresses typed into the email box to mean "none", measured on A-1's live
     * book (2026-09-29) and listed exactly as found.
     *
     * They are not harmless filler. `no@gmail.com` alone sat on 451 customers:
     * as an identity it makes the duplicate sweep report 451 strangers as one
     * person, and as an address it is a real Gmail mailbox somebody owns, so an
     * email automation would send 451 customers' messages to a stranger. The
     * box is optional in EzBizi, so there is nothing a placeholder stands in
     * for -- it is legacy from a system that required one.
     *
     * An explicit list rather than a pattern like /^no/: "nora@", "nm-roofing@"
     * and "none.such@" are real people, and a cleanup that blanks an address has
     * to be able to say exactly which ones it meant.
     *
     * ponytail: a list, so a placeholder nobody has seen yet gets through. Add
     * it here when an import surfaces a new one -- the importers read this.
     */
    public const PLACEHOLDER_EMAILS = [
        'no@gmail.com', 'nm@m.com', 'nm@gmail.com', 'no@me.com', 'n@m.com',
        'no@net.tek', 'noemai@gmail.com', 'noemail1@gmail.com',
        'none@msn.com', 'none@noemail.com', 'none@ymail.com', 'none@gmail.com',
        'cxdeclined@gmail.com', 'cxdeclinedemail@gmail.com', 'refuse@g.c',
        'fhfg@gmail.com', 'ghfg@gmail.com',
    ];

    /**
     * An email worth keeping, or null for a blank or a known placeholder.
     *
     * For anything that WRITES a customer from another system's data -- the
     * Workiz projection, the A1 import -- so a placeholder never becomes an
     * address to begin with.
     */
    public static function realEmail(?string $email): ?string
    {
        $normal = strtolower(trim((string) $email));

        return $normal === '' || in_array($normal, self::PLACEHOLDER_EMAILS, true)
            ? null
            : trim((string) $email);
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The people and businesses this organization works for. A customer is often '
            .'an individual at a home address, so most of this row is personal data even '
            .'though the record is a commercial one.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Soft-deleted rather than removed, because jobs and invoices reference it and '
            .'tax law requires those to survive. §9\'s resolution is that erasure here means '
            .'anonymising in place — keep the invoice and its amounts, blank the name, email, '
            .'phone and address — rather than deleting the row, so there is no age at which '
            .'a customer stops being needed.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY,
                'Who the work is for, and what every list and invoice shows.',
                // NOT NULL, and it is what every list renders, so it cannot go to
                // nothing: a row of blank space reads as a bug rather than as a
                // record somebody asked to be removed from.
                Erasure::erase('Removed customer')),
            new PersonalDataEntry('email, phone', PersonalDataEntry::CONTACT,
                'Arranging and confirming the work.',
                Erasure::erase()),
            new PersonalDataEntry('address_line1, address_line2, city, state, postal_code',
                PersonalDataEntry::CONTACT,
                'Where the work happens. Usually somebody\'s home, which is what makes '
                .'this the most re-identifying field on the row — §9 warns that blanking '
                .'a name is not anonymisation while a unique address remains.',
                // Which is exactly why all five go rather than the street lines
                // alone. A city and a postal code with one job against them is a
                // household, and §9 says so in as many words.
                Erasure::erase()),
            new PersonalDataEntry('notes', PersonalDataEntry::CONTENT,
                'Free text written by staff. Deliberately listed: it is the field with no '
                .'schema, so it is the one that ends up holding whatever somebody thought '
                .'was worth writing down about a person.',
                Erasure::erase()),
        ];
    }

    /**
     * The customer's own row, including a soft-deleted one.
     *
     * `withTrashed` is the whole point: a customer somebody already "deleted" is
     * retained personal data — §9's tension in one line — and is the likeliest
     * row to be the subject of the request, since the organization believes they
     * are gone.
     *
     * `number` is deliberately not erased and is not listed above. It identifies
     * the RECORD, not the person; the invoices §9 insists on keeping are found by
     * it, and it was assigned by this system rather than known about anybody.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof self
            ? static::query()->withTrashed()->whereKey($subject->getKey())
            : null;
    }

    // ── relations ────────────────────────────────────────────────

    /** The ONE franchise accountable for this relationship (§5.2). */
    public function owningFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'owning_franchise_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    // ── the "served by" list (§5.2) ──────────────────────────────

    /**
     * Every franchise that has actually done work for this customer.
     *
     * DERIVED, never stored. A hand-maintained list goes stale the first time
     * somebody forgets to update it, and a stale accountability tag is worse than
     * no tag at all — it asserts something false rather than saying nothing.
     *
     * Includes the owner even with no jobs yet, because "accountable but has not
     * worked for them" is still a relationship somebody is answering for.
     *
     * @return Collection<int, string> franchise ids
     */
    public function servedByFranchiseIds(): Collection
    {
        return $this->jobs()
            ->distinct()
            ->pluck('franchise_id')
            ->push($this->owning_franchise_id)
            ->unique()
            ->values();
    }

    /**
     * Has a franchise other than the owner worked for this customer?
     *
     * What the UI needs in order to warn before somebody edits another
     * franchise's customer without noticing (§5.2 — the tag has to be visible
     * BEFORE they act, not after).
     */
    public function isSharedInPractice(): bool
    {
        return $this->servedByFranchiseIds()->count() > 1;
    }
}
