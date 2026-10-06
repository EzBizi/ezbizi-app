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

/**
 * A report waiting for somebody to answer the report_ready template, because
 * WhatsApp allows free text only within 24 hours of their last message.
 */
class WhatsAppPendingReport extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_pending_reports';

    protected $fillable = ['organization_id', 'phone_number_id', 'counterparty', 'body', 'media_id', 'filename', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(
            months: 1,
            column: 'created_at',
            because: 'A report held until its reader answers on WhatsApp. A month-old report '
                .'is out of date, so it goes.',
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a staff member who has not written to the WhatsApp line in a day still gets '
            .'their report when they tap "Show report".';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('counterparty', PersonalDataEntry::CONTACT,
                'The staff member\'s WhatsApp number, where the report goes.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
