<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's Stripe connected account: where its customers' invoice payments land. */
class PaymentAccount extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('One row per company naming its Stripe account; deleted with the company.');
    }

    protected $fillable = [
        'organization_id', 'company_id', 'stripe_account_id',
        'charges_enabled', 'payouts_enabled', 'details_submitted',
    ];

    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Stripe's three flags, as the status endpoint and the webhook both write them. */
    public function absorb(array $flags): void
    {
        $this->forceFill([
            'charges_enabled' => (bool) ($flags['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($flags['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($flags['details_submitted'] ?? false),
        ]);
    }

    public function present(): array
    {
        return [
            'company_id' => $this->company_id,
            'stripe_account_id' => $this->stripe_account_id,
            'charges_enabled' => $this->charges_enabled,
            'payouts_enabled' => $this->payouts_enabled,
            'details_submitted' => $this->details_submitted,
        ];
    }
}
