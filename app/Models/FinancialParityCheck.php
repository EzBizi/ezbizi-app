<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One day's Airtable-vs-EzBizi comparison of one table (ezbizi:airtable-parity). */
#[Fillable(['source_base_id', 'source_table_id', 'table_name', 'day', 'green', 'cut_over', 'airtable_rows', 'ezbizi_rows', 'diffs'])]
class FinancialParityCheck extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['day' => 'date:Y-m-d', 'green' => 'boolean', 'cut_over' => 'boolean', 'diffs' => 'array',
            'airtable_rows' => 'integer', 'ezbizi_rows' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'day',
            'A daily check that the books copied into EzBizi match Airtable; two years is enough to explain any switch-over.'
        );
    }
}
