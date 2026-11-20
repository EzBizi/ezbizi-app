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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One listing on a connected Google Business Profile.
 *
 * ── It holds personal data, and the first answer was wrong ──────
 *
 * The tempting argument, written here before the §9 guardrail rejected it: a
 * listing is a shop front the business published to the entire world, so
 * nothing in it is confided to EzBizi and nothing is about a person.
 *
 * That is true of a chain with an office, and false of the sole trader whose
 * listed address is the house they live in and whose listed number is their
 * mobile. HoldsPersonalData's own note says personal data covers people who
 * are not users of this app and "hides in places nobody thinks of" — and an
 * inventory that omits a table because the usual case looks corporate is
 * exactly the inventory §9 warns will be believed.
 *
 * So it is declared. What erasure means here is unusual and worth stating: the
 * row is a COPY of something Google is publishing. Blanking it removes EzBizi's
 * copy and changes nothing the public can see, because EzBizi does not own that
 * listing and does not write to it. Anybody asking for the public listing to
 * change has to ask Google, and the honest thing is to say so rather than to
 * imply this erasure did more than it did.
 */
class BusinessLocation extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Google's word for the state of a listing. */
    public const VERIFIED = 'VERIFIED';

    public const UNVERIFIED = 'UNVERIFIED';

    public const SUSPENDED = 'SUSPENDED';

    public const DUPLICATE = 'DUPLICATE';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A copy of what a connected Google Business Profile listing currently says — the '
            .'trading name, address, phone, hours and whether Google is showing it. Held for as '
            .'long as the profile is connected and deleted when somebody disconnects it. It is a '
            .'copy of a listing the business publishes itself; erasing it here removes EzBizi'
            .'s copy and changes nothing the public can see. EzBizi does not own the listing; '
            .'the only thing it sends to Google for it is a post somebody publishes from '
            .'Marketing, which lives on Google and is deleted there, not here.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'A copy of what a connected Google Business Profile listing publishes — the '
            .'trading name, the address customers are invited to visit, and the number on the '
            .'listing. For a company that is a shop front; for a sole trader it is their home '
            .'and their mobile, which is why it is inventoried rather than assumed corporate.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('phone', PersonalDataEntry::CONTACT,
                'The number Google shows the public for this listing. Often a business line, '
                .'sometimes the mobile of somebody working alone.',
                Erasure::erase()),
            new PersonalDataEntry(
                'address_line1, address_line2, city, state, postal_code',
                PersonalDataEntry::CONTACT,
                'Where the listing tells the public to go. For a business run from home this is '
                .'a household, which §9 names as the most re-identifying field there is — which '
                .'is why all five go together rather than the street line alone.',
                Erasure::erase()),
        ];
    }

    /**
     * Nothing here is about a person this platform knows.
     *
     * A listing belongs to a business, not to a user, a customer or an
     * employee, and there is no id it shares with any of them. Matching a
     * listing to a person by address would be guessing, and a purge that
     * guessed would erase a stranger's shop front.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected $fillable = [
        'organization_id', 'company_id', 'franchise_id', 'connection_id',
        'remote_name', 'account_name', 'title', 'phone', 'website',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'primary_category', 'verification_state', 'is_published', 'hours', 'service_places', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'service_places' => 'array',
            'is_published' => 'boolean',
            'auto_reply' => 'boolean',
            'auto_post' => 'boolean',
            'google_review_count' => 'integer',
            'google_rating' => 'float',
            'google_totals_at' => 'datetime',
            'not_listed_since' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    /**
     * Reviews left on this listing.
     *
     * Joined on Google's own location path rather than a foreign key, because
     * a review can arrive before the location it belongs to has been fetched —
     * the two are separate syncs against separate APIs, and neither waits for
     * the other. A review whose location is not held yet is still a review.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'location_name', 'remote_name');
    }

    /**
     * Is Google actually showing this to the public right now?
     *
     * The question the screen leads with. A suspended or duplicate listing
     * looks perfectly healthy in a database and is invisible on Maps, and the
     * gap between those two is exactly what somebody opens this tab to find.
     */
    /**
     * Which listing this is, in words a picker can show (Jose, 2026-10-19:
     * forty chips all read "A1 Chimney"). Google's title is the brand, the
     * same on every listing; the city and state are what tell them apart.
     * One helper so Compose, the hours editor and Reviews name them alike.
     */
    public function label(): string
    {
        $where = collect([$this->city, $this->state])
            ->filter(fn ($part) => is_string($part) && trim($part) !== '')
            ->implode(', ');

        return $where === '' ? (string) $this->title : $this->title.' · '.$where;
    }

    /** The branch a listing is filed on, for grouping pickers; null when only the company. */
    public function branchName(): ?string
    {
        return $this->franchise?->name ?? $this->company?->name;
    }

    public function isLive(): bool
    {
        return $this->verification_state === self::VERIFIED
            && $this->is_published !== false;
    }

    /** One line, the way an address is written on an envelope. */
    public function addressLine(): string
    {
        return collect([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postal_code,
        ])->filter(fn ($part) => is_string($part) && trim($part) !== '')
            ->implode(', ');
    }
}
