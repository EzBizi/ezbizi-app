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
 * One SMS we store (spec §11) — body included, unlike email.
 */
class PhoneMessage extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
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
