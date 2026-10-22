<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice: lines snapshotted at issue, payments against it, a status that
 * only moves forward (draft → approved → sent → paid; void from anything but
 * paid). Amount due is worked out from the payments, never stored.
 */
#[Fillable([
    'company_id', 'franchise_id', 'number', 'issued_on', 'due_on', 'status', 'currency',
    'bill_to_type', 'bill_to_id', 'bill_to_name', 'bill_to_address', 'bill_to_emails', 'bill_to_phones',
    'source_type', 'source_id', 'summary', 'subtotal_cents', 'tax_cents', 'total_cents', 'notes',
    'pdf_disk', 'pdf_path', 'created_by_user_id', 'approved_by_user_id', 'approved_at',
    'sent_by_user_id', 'sent_at', 'paid_at', 'voided_at', 'void_reason', 'overdue_announced_at',
])]
class Invoice extends Model implements CarriesAutomationScope, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUSES = ['draft', 'approved', 'sent', 'paid', 'void'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'bill_to_emails' => 'array', 'bill_to_phones' => 'array',
            'subtotal_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer',
            'approved_at' => 'datetime', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
            'voided_at' => 'datetime', 'overdue_announced_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'issued_on',
            'An invoice and its payments are tax records, kept seven years from the day it was issued.'
        );
    }

    /** Where it was issued from: its place, else its company, else the organization. */
    public function automationScope(): array
    {
        return match (true) {
            $this->franchise_id !== null => ['franchise', $this->franchise_id],
            $this->company_id !== null => ['company', $this->company_id],
            default => [null, null],
        };
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /** Charges less refunds; tips are not part of what the invoice asked for. */
    public function paidCents(): int
    {
        return (int) $this->payments->sum(fn (InvoicePayment $p) => $p->kind === 'refund' ? -$p->amount_cents : $p->amount_cents);
    }

    public function dueCents(): int
    {
        return $this->status === 'void' ? 0 : max(0, $this->total_cents - $this->paidCents());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'sent' && $this->due_on !== null && $this->due_on->lt(today()) && $this->dueCents() > 0;
    }
}
