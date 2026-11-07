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
    'notes', 'is_active', 'customer_since', 'tags',
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
        return ['is_active' => 'boolean', 'customer_since' => 'date', 'tags' => 'array'];
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
        // A1 Tools' own, measured by the Workiz session on 2026-09-29 before
        // the A1 import -- each on 5 or more rows, 1,309 rows between them.
        // The misspellings are real: "nomail@yahoo.con" is what got typed.
        'nomail@yahoo.com', 'nomail@gmail.com', 'refused@gmail.com', 'naa@gmail.com',
        'no@mail.com', 'no@aol.com', 'na@gmail.com', 'refused@mail.com',
        'nomail@yahoo.con', 'nomail@yaho.com', 'nonail@yahoo.com', 'nomaol@yahoo.com',
        'nomail@yhaoo.com', 'no@tek.net', 'no@net.swk', 'n@gmail.com',
        'nomail@tahoo.com', 'no@gmail.fr', 'noname@gmail.com', 'notavaliable11@gmail.com',
        'refused@yahoo.com', 'refuse@gmail.com', 'nm@gmsil.com',
        // A-1's OWN role mailboxes, typed in as the client's address: 165 A1
        // rows on office@, 54 on the NJ office. A customer email sent there
        // reaches A-1's own staff, never the customer. Cleared on Jose's word,
        // 2026-09-29. Role addresses only -- a staff member's personal
        // a-1chimney.com address on a customer may be a real customer who
        // works there, so those are not listed.
        'office@a-1chimney.com', 'office@nj.a-1chimney.com', 'office@md.a-1chimney.com',
        'info@a-1chimney.com', 'accounting@a-1chimney.com',
    ];

    /**
     * Numbers that belong to nobody on the row: relay, tracking and office
     * lines typed in as the customer's phone. Ten digits, US, no leading 1.
     *
     * Each was measured as many DIFFERENT names at different addresses --
     * 3236761570 on 28 customers under 28 names; 8187482055 on 388 A1 rows. That
     * is the test, not the count: 3477245006 sits on 12 customers but only 8
     * names and 7 addresses, which reads as a real property manager, so it is
     * deliberately NOT here. An SMS automation would text a stranger for every
     * row one of these is on, and the duplicate sweep would call them one
     * person.
     */
    public const PLACEHOLDER_PHONES = [
        '8187482055', '9547109003', '9527777291', '2109636961', '8777711892',
        '6266244006', '2168590559', '4636764568', '6099035648', '8886219148',
        '3236761570', '2723942201',
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

        return $normal === '' || in_array($normal, self::placeholders('customers.placeholder_emails'), true)
            ? null
            : trim((string) $email);
    }

    /**
     * A phone worth keeping, or null for a blank or a known placeholder line.
     *
     * Compared as the national ten digits, so +18187482055, (818) 748-2055 and
     * 818.748.2055 are the same number -- the stored form varies with where the
     * row came from. The value handed back is the one given, untouched: this
     * decides WHETHER to keep a number, not how to write it.
     */
    public static function realPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        $national = strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;

        return in_array($national, self::placeholders('customers.placeholder_phones'), true) ? null : trim((string) $phone);
    }

    /**
     * A business list of placeholders (Settings › Lists), lower-cased. The
     * constants above are only its defaults (Jose, 2026-11-04: every business
     * value editable). Memoized per org for the request or job, because the
     * importers call this once per row.
     */
    public static function placeholders(string $list): array
    {
        return self::placeholderList($list, app(\App\Support\Tenancy\TenantContext::class)->id());
    }

    private static function placeholderList(string $list, ?string $organizationId): array
    {
        // once() keys on the closure's captured variables, so the org must be one.
        return once(fn () => $organizationId === false ? [] : array_map(
            fn (array $row) => mb_strtolower(trim((string) $row['key'])),
            app(\App\Support\Settings\Lists\BusinessLists::class)->rows($list),
        ));
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

    /**
     * Tags as written once: trimmed, blanks dropped, one per spelling
     * regardless of case (the first spelling wins), in the order given.
     */
    public function setTagsAttribute(?array $tags): void
    {
        $kept = [];
        foreach ($tags ?? [] as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && ! isset($kept[mb_strtolower($tag)])) {
                $kept[mb_strtolower($tag)] = $tag;
            }
        }

        $this->attributes['tags'] = $kept === [] ? null : json_encode(array_values($kept));
    }

    // ── relations ────────────────────────────────────────────────

    /** The ONE franchise accountable for this relationship (§5.2). */
    public function owningFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'owning_franchise_id');
    }

    /**
     * `owning_company` on an export (Jose, 2026-10-19: at organization level the
     * list says which company a customer belongs to). The company of the
     * owning franchise; not `company`, which would read as the customer's own.
     *
     * ponytail: two lazy loads per exported row. Add an eager load to the
     * export query if a large organization's export gets slow.
     */
    public function getOwningCompanyAttribute(): ?string
    {
        return $this->owningFranchise?->company?->name;
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /**
     * The Workiz client records this customer is: one per (franchise, Workiz
     * client number), and several when one person had more than one.
     */
    public function workizClients(): HasMany
    {
        return $this->hasMany(WorkizClient::class);
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

    /**
     * `{{ review_link }}` and `{{ membership_join_url }}` in an automation's
     * message (Memberships, 2026-10-05): the reviews page that knows who this
     * customer is, and the page that signs them up. Null when the
     * organization has no site with a reviews page.
     */
    public function getReviewLinkAttribute(): ?string
    {
        return \App\Support\Memberships\CustomerLinks::reviewLink($this);
    }

    /**
     * `{{ membership_link }}`: the same page by a short link, for a text message.
     * Null for a customer who is already a member, or when no plan is on offer,
     * so the automation step that names it is skipped (Automations, e63789f8).
     */
    public function getMembershipLinkAttribute(): ?string
    {
        $member = Membership::query()->where('customer_id', $this->getKey())->whereNull('cancelled_at')
            ->where('ends_on', '>=', now()->toDateString())->exists();

        return $member || ! MembershipPlan::query()->where('is_active', true)->exists()
            ? null
            : \App\Support\Memberships\CustomerLinks::joinLink($this);
    }

    public function getMembershipJoinUrlAttribute(): string
    {
        return \App\Support\Memberships\CustomerLinks::joinUrl($this);
    }
}
