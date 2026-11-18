<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
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
 * One SMS we store (spec §11) — body included, unlike email.
 */
class PhoneMessage extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES); read per line (ChangeFeed::NARROW). */
    protected static string $changeType = 'phone_messages';

    use HasFactory;
    use HasUuids;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public static function personalDataPurpose(): string
    {
        return 'Text messages sent or received on a business line. The body is free text '
            .'and often names a customer or other person.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Default matches business audit until a Phone-specific setting exists (spec §11). '
            .'SMS bodies age out with the same window as call records.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('from_e164, to_e164', PersonalDataEntry::CONTACT,
                'The numbers on the text. Usually a customer or other outside person.',
                Erasure::drop('A message row is the exchange itself. Blanking the numbers '
                    .'leaves a SID and a body with no parties; the row goes.')),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'What was written. Free text with no schema, so it can hold names and other '
                .'personal detail that nothing else indexes.',
                Erasure::drop('Goes with the row: selective blanking cannot tell which words '
                    .'are a name.')),
            new PersonalDataEntry('customer_id', PersonalDataEntry::IDENTITY,
                'The matched customer card, when Lookup or the desk linked the number.',
                Erasure::keep('A foreign key into customers, which the purge anonymises at '
                    .'its one home. Nulling it here would drop the link without removing the '
                    .'message content that still names them — and that content is dropped '
                    .'with the row when this message is about the subject.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        if ($subject instanceof Customer) {
            return static::query()->where('customer_id', $subject->getKey());
        }

        return null;
    }

    protected $fillable = [
        'organization_id', 'number_id', 'connection_id',
        'twilio_sid', 'direction', 'from_e164', 'to_e164',
        'body', 'customer_id',
    ];

    /**
     * Which company's line this happened on (§4, §5.5).
     *
     * Without this the dispatcher's column sniff looks for `franchise_id` and
     * `owning_franchise_id`, finds neither on this table, and reads that as "no
     * scope" — which its organization branch turns into EVERY ACTIVE USER. So a
     * rule saying "notify me" on `phone.sms.received` would have carried a customer's message to
     * everybody in the business, including the companies that do not answer
     * that line. That is the exact leak NotificationDispatcher's own header says
     * reach exists to prevent, arriving through a record shape its sniff was
     * never written for.
     *
     * The scope is the NUMBER's, not the message's, because the message has no place of
     * its own: it belongs wherever the line it arrived on is filed. That is the
     * same answer PhoneScope gives when deciding who may open it — one rule read
     * twice, rather than a second derivation that can disagree with who is
     * allowed to see the record.
     *
     * A row whose number has been deleted answers `[null, null]` rather than
     * guessing, and that is the conservative direction rather than a gap: the
     * organization branch reaches the people who STAND at the organization, not
     * everyone in it.
     */
    public function automationScope(): array
    {
        $number = $this->number;

        return match ($number?->scope_type) {
            PhoneNumber::SCOPE_FRANCHISE => ['franchise', $number->scope_id],
            PhoneNumber::SCOPE_COMPANY => ['company', $number->scope_id],
            default => [null, null],
        };
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
