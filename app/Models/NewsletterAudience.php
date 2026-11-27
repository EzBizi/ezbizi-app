<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who a mailing goes to.
 *
 * Two kinds, and they are genuinely different rather than one with a flag.
 * `customers` is derived from EzBizi's own customer book each time it is sent,
 * so it is always current and we hold the addresses. `mailchimp` is a list that
 * lives in the tenant's account — we hold an id and nothing else, which means no
 * addresses, no consent records and no §9 erasure surface for that half.
 */
class NewsletterAudience extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KIND_CUSTOMERS = 'customers';

    public const KIND_MAILCHIMP = 'mailchimp';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A named mailing list: either a rule for picking people out of the organization’s '
            .'own customer book, or a pointer to a list held in the company’s Mailchimp '
            .'account. It holds no email addresses of its own — a customer list is read from '
            .'the customer records at send time, and a Mailchimp list stays at Mailchimp.'
        );
    }

    protected $fillable = ['name', 'kind', 'mailchimp_list_id', 'tag', 'scope_type', 'scope_id'];

    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    public function isMailchimp(): bool
    {
        return $this->kind === self::KIND_MAILCHIMP;
    }
}
