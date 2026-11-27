<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What was quoted to a customer, and whether they took it (spec 2026-09-29 §4).
 *
 * Money, so every action also needs Jobs' Financials toggle (EstimatePolicy).
 * On a customer; a job is optional, because most estimates come before there is
 * one -- 6 of A1's 2,388 had a job.
 */
#[Fillable([
    'franchise_id', 'customer_id', 'job_id', 'number', 'title', 'status', 'issued_on',
    'note', 'currency', 'total_minor', 'source', 'external_ref', 'import_batch',
])]
class Estimate extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed on its branch. */
    protected static string $changeType = 'estimates';

    use HasUuids;

    /** A1 Tools' own words, so its history imports without a mapping. */
    public const STATUSES = ['draft', 'sent', 'accepted', 'declined', 'archived'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'What was quoted to a customer, and whether they took it. Kept with the '
            .'organization, as sales orders are; erasing the customer anonymises the '
            .'customer in place and this row keeps its amounts. Removing the organization '
            .'takes it.'
        );
    }

    protected function casts(): array
    {
        return [
            'issued_on'   => 'date',
            'accepted_at' => 'datetime',
            'total_minor' => 'integer',
        ];
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EstimateLine::class)->orderBy('position')->orderBy('created_at');
    }

    /**
     * The total is what the lines come to. Each line is rounded on its own, the
     * way an invoice prints it, rather than rounding the sum once. An estimate
     * imported with a total and no lines keeps its total until lines exist.
     */
    public function recomputeTotal(): void
    {
        $this->forceFill(['total_minor' => $this->computeTotal()])->save();
    }

    /** What the lines come to, unsaved: for a caller that audits the change first. */
    public function computeTotal(): int
    {
        return (int) $this->lines()->get()
            ->sum(fn (EstimateLine $l) => (int) round((float) $l->quantity * $l->unit_price_minor));
    }
}
