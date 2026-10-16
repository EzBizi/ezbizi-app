<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One row of an A-1 Airtable table, as last read (AirtableSnapshot). */
class AirtableRecord extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'base_id', 'table_id', 'table_name', 'record_id', 'fields', 'fields_hash', 'record_created_at', 'fetched_at', 'gone_at'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'record_created_at' => 'datetime', 'fetched_at' => 'datetime', 'gone_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return "A copy of the company's own Airtable bookkeeping (P&L, repair teams, deposits, refunds and disputes, office expenses), so the weekly and monthly reports are built inside EzBizi.";
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'fetched_at',
            'Financial records of the business, kept seven years as tax records are; a row Airtable no longer has goes seven years after it was last seen.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('fields', PersonalDataEntry::CONTENT,
                'The row as Airtable holds it: amounts, dates, job numbers and the names of the technicians and companies they belong to.',
                Erasure::keep('a financial record of the business, kept for the tax period like the books it copies')),
        ];
    }

    /** Rows are keyed by Airtable, not by a person; the window removes them. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
