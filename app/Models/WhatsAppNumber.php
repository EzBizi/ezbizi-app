<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp number this organization sends from.
 *
 * ── StatesRetention, and deliberately NOT HoldsPersonalData ──────
 *
 * `PhoneNumber` is the precedent and it is an exact one: a live phone line the
 * organization has bought, held while it is on the account, stating retention
 * and no personal data. This is the same kind of thing on a different carrier.
 *
 * The number in `display_number` is the BUSINESS's own — the one printed on the
 * van, the one a customer sees in the chat header. A person's number lives in
 * `whatsapp_messages.counterparty`, which DOES declare it, with an erasure
 * strategy. Declaring it here as well would say a person is in this table when
 * the only thing in it is which tenant owns which of our registered numbers.
 *
 * `display_name` is the same: the name Meta shows for the business, not a
 * person's.
 *
 * ── forever(), because the row IS the access ─────────────────────
 *
 * Like PhoneNumber, this is held while the number is registered and deleted
 * when it is given up. There is nothing to age out: a two-year window would
 * delete a live number's routing and silently send its customers' messages
 * nowhere, because the webhook finds the tenant through this row and only
 * through this row.
 */
class WhatsAppNumber extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Named for the same reason as WhatsAppMessage: the convention pluralises the capital A. */
    protected $table = 'whatsapp_numbers';

    protected $fillable = [
        'organization_id', 'phone_number_id', 'waba_id', 'display_number', 'display_name', 'purpose', 'social_connection_id', 'auto_reply',
    ];

    protected $casts = ['auto_reply' => 'boolean'];

    /** What a number is for. Reports send from `reports`, the staff bot answers on `support`. */
    public const PURPOSES = ['reports', 'support', 'authentication', 'company'];

    /**
     * This organization's number for $purpose, or null when none is marked
     * for it: the caller keeps its own choice then. Inside the tenant.
     */
    public static function forPurpose(string $purpose): ?self
    {
        return static::query()->where('purpose', $purpose)->first();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A WhatsApp number the organization sends from, and which tenant it belongs to. '
            .'Held while the number is registered to the account; giving it up deletes the row. '
            .'The messages sent and received on it hang off whatsapp_messages, which has its '
            .'own two-year window.'
        );
    }
}
