<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An enquiry that arrived through a connected advertising account.
 *
 * A member of the public left their phone number expecting to be called back
 * about work. That is what every field here is in service of, and it is why
 * nothing on this model sends anything: no automated reply, no follow-up
 * message, no rule that can compose one. A reply to a lead is a message to a
 * stranger in the customer's name, and it goes out because a person sent it.
 */
class Lead extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Google's Local Services, through the tenant's own Google Ads account. */
    public const SOURCE_LOCAL_SERVICES = 'local_services';

    /**
     * Ours, not the source's.
     *
     * `NEW` is the absence of a decision rather than a decision, which is why a
     * sync may set it and nothing else may set it back.
     */
    public const STATUS_NEW        = 'new';
    public const STATUS_BOOKED     = 'booked';
    public const STATUS_ARCHIVED   = 'archived';
    public const STATUS_NOT_A_LEAD = 'not_a_lead';

    /** @var string[] */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_BOOKED,
        self::STATUS_ARCHIVED,
        self::STATUS_NOT_A_LEAD,
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An enquiry a member of the public made to this organization through its '
            .'advertising — their name, phone number and what they asked about — together with '
            .'what the organization did with it. Held for as long as the organization keeps its '
            .'record of the work it was asked to do, and deleted with the organization. EzBizi '
            .'does not use it for anything other than showing it to that organization, and never '
            .'combines it with another organization\'s.'
        );
    }

    /**
     * §9. The person here never signed up for anything.
     *
     * Every other personal-data row on this platform is about somebody who
     * chose a relationship with the tenant — a customer, an employee, an
     * invited user. A lead is a member of the public who rang a phone number
     * they saw in an advert, and whose name and number are now in a database
     * they have never heard of. That is exactly the case §9 exists for, and the
     * reason this model must not be the one somebody forgets to declare.
     */
    public static function personalDataPurpose(): string
    {
        return 'Somebody who contacted this organization through its advertising, and what '
            .'the organization did about it. The contact details are what a person gave in '
            .'order to be called back about work; they are held so the organization can do '
            .'that and can account for what it was charged for.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('contact_name', PersonalDataEntry::IDENTITY,
                'Who to ask for when the business calls back.',
                Erasure::erase('Removed enquirer')),
            new PersonalDataEntry('phone, phone_digits, email', PersonalDataEntry::CONTACT,
                'How the business returns the enquiry. `phone_digits` is the same number '
                .'with the punctuation removed so a search matches however either side '
                .'wrote it — a second copy of the same personal data, and erased with it.',
                Erasure::erase()),
            new PersonalDataEntry('summary', PersonalDataEntry::CONTENT,
                'What the person said they wanted, in their own words or transcribed from '
                .'what they said. Free text, so it can contain anything they mentioned — an '
                .'address, a name, a reason for the work.',
                Erasure::erase()),
        ];
    }

    /**
     * Nothing joins a lead to a person on this platform.
     *
     * An enquirer is not a user, not a customer, and not an employee; there is
     * no id they share with any of them. A lead that later becomes a customer
     * gets a customer row of its own, and erasing that customer does not reach
     * back here — which is a gap worth stating rather than papering over with a
     * name match, because two people called J. Smith are not one person and a
     * purge that assumed otherwise would erase a stranger's record.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected $fillable = [
        'organization_id', 'company_id', 'franchise_id', 'connection_id',
        'source', 'remote_id', 'contact_name', 'phone', 'email', 'phone_digits',
        'lead_type', 'category', 'summary', 'status', 'source_status',
        'charge_status', 'received_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'synced_at'   => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
     * Digits only, so a search matches however either side wrote the number.
     *
     * `+1 (555) 123-4567` and `5551234567` are the same person. Kept as a
     * stored column rather than computed at query time because a `REPLACE`
     * chain in the WHERE clause cannot use an index, and the search this feeds
     * is the one somebody uses while a customer is on the phone.
     */
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return $digits === '' ? null : substr($digits, -32);
    }
}
