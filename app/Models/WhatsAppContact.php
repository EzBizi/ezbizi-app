<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Notifications\LeadNotificationText;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who wrote to one of our WhatsApp numbers, and whether they said
 * they want a service or a job. See WhatsAppIntake.
 */
class WhatsAppContact extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_contacts';

    public const KIND_UNKNOWN = LeadNotificationText::WHATSAPP_UNKNOWN;

    public const KIND_LEAD = LeadNotificationText::WHATSAPP_LEAD;

    public const KIND_HIRING = LeadNotificationText::WHATSAPP_HIRING;

    protected $fillable = ['organization_id', 'phone_number_id', 'counterparty', 'profile_name', 'saved_name', 'referral', 'kind', 'lead_id', 'classified_at'];

    protected function casts(): array
    {
        return ['classified_at' => 'datetime', 'referral' => 'array'];
    }

    /** The name to show: the one the business saved, else their own WhatsApp name. */
    public function name(): ?string
    {
        return $this->saved_name ?: ($this->profile_name ?: null);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(
            months: 24,
            column: 'updated_at',
            because: 'Who wrote to a business on WhatsApp and whether they asked for a service '
                .'or a job, so they are asked only once. Kept as long as the messages themselves.',
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a business asks a new WhatsApp contact once whether they need a service or '
            .'want to work there, and routes them to Leads or to HR.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('counterparty', PersonalDataEntry::CONTACT,
                'Their WhatsApp number, the address the conversation is with.',
                Erasure::erase()),
            new PersonalDataEntry('profile_name', PersonalDataEntry::IDENTITY,
                'The name they set on their own WhatsApp profile.',
                Erasure::erase()),
            new PersonalDataEntry('saved_name', PersonalDataEntry::IDENTITY,
                'The name the business saved for them in the WhatsApp Business app.',
                Erasure::erase()),
            new PersonalDataEntry('referral', PersonalDataEntry::ACTIVITY,
                'The Click-to-WhatsApp ad or post they came from.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
