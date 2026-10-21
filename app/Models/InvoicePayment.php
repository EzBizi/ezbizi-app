<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A charge or a refund against an invoice. */
#[Fillable(['invoice_id', 'kind', 'paid_on', 'amount_cents', 'tip_cents', 'fee_cents', 'method', 'reference', 'provider',
    'provider_reference', 'payout_id', 'collected_by_user_id', 'recorded_by_user_id'])]
class InvoicePayment extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const METHODS = ['cash', 'check', 'card', 'transfer', 'other'];

    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount_cents' => 'integer', 'tip_cents' => 'integer', 'fee_cents' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Part of its invoice; goes when the invoice goes.');
    }
}
