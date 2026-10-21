<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One line of an invoice, as it stood when the invoice was issued. Tax is per line, rounded per line. */
#[Fillable(['invoice_id', 'position', 'description', 'quantity', 'unit_cents', 'amount_cents', 'taxable', 'tax_rate_bp', 'tax_cents', 'meta'])]
class InvoiceLine extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cents' => 'integer', 'amount_cents' => 'integer', 'taxable' => 'boolean',
            'tax_rate_bp' => 'integer', 'tax_cents' => 'integer', 'meta' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Part of its invoice; goes when the invoice goes.');
    }
}
