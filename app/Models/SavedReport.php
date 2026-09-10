<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\SavedReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named report somebody composed (spec 2026-09-10).
 *
 * The row holds a QUESTION, not an answer: column keys, filters and the place
 * it was filed on. It stores no rows and caches no results, so a report cannot
 * become a stale copy of data somebody has since corrected or erased — running
 * it always reads the live tables, through the live scope of whoever ran it.
 */
#[Fillable(['company_id', 'franchise_id', 'name', 'description', 'definition'])]
class SavedReport extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A question this organization asked of its own data. It holds column '
            .'names and filter values, never rows, so it carries no personal data '
            .'of its own; removing the organization takes it.'
        );
    }

    /** @use HasFactory<SavedReportFactory> */
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
