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

/**
 * One WhatsApp message, in or out.
 *
 * The row is written BEFORE an outbound send and the provider's id written back
 * after, so a send that died between here and Meta leaves a `queued` row with
 * no `wamid` rather than leaving nothing at all.
 */
class WhatsAppMessage extends Model implements HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /*
     * Named, because the convention gets it wrong. Eloquent reads the capital A
     * in WhatsAppMessage as a word boundary and pluralises to
     * `whats_app_messages`, which is not the table the migration creates - and
     * the failure is a "table not found" at runtime rather than anything a
     * boot-time check would see.
     */
    protected $table = 'whatsapp_messages';

    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IN = 'in';

    /** Ours, before Meta has said anything. */
    public const STATUS_QUEUED = 'queued';

    /** Meta's, verbatim, so a new one it invents is not silently dropped. */
    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECEIVED = 'received';

    protected $fillable = [
        'organization_id', 'phone_number_id', 'direction', 'counterparty',
        'wamid', 'idempotency_key', 'status', 'status_reason', 'kind', 'template_name', 'body',
        'media_id', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function retention(): RetentionPolicy
    {
        /*
         * Two years, not forever and not with a parent.
         *
         * It has no parent to hang from - a WhatsApp thread is not owned by a
         * lead or a job, and tying it to one would delete a conversation when
         * an unrelated record went. And it is not `forever()`: unlike an
         * assignment, this table grows with TRAFFIC rather than with headcount,
         * so something has to bound it or it is the largest table in the
         * product within a year.
         *
         * Two years because a customer asking "what did you tell me" about last
         * winter's chimney work is an ordinary thing and a year would not cover
         * it, while nobody has ever needed the winter before that.
         */
        return RetentionPolicy::window(
            months: 24,
            column: 'created_at',
            because: 'A WhatsApp message exchanged with a customer: which number, which '
                .'direction, what was said, and whether it reached them. Kept for two years so '
                .'a business can answer "what did you tell me about my chimney", then deleted.',
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So a business can send a customer a message on WhatsApp and see whether it '
            .'arrived, and can read what that customer sent back.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('counterparty', PersonalDataEntry::CONTACT,
                'The customer\'s WhatsApp number, in E.164. It is the address the message went '
                .'to or came from, so it cannot be dropped while the message is held.',
                Erasure::erase()),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'The message itself, in whoever wrote it\'s own words. Free text, so it can '
                .'contain anything they chose to say - an address, a name, a complaint.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
