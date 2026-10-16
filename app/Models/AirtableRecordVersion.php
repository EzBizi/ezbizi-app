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

/** An earlier state of an AirtableRecord, kept when the row changed. */
class AirtableRecordVersion extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'airtable_record_id', 'fields', 'seen_at'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'seen_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return 'An earlier state of a copied Airtable row, so a past report can be rebuilt as it stood.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'seen_at', 'As the row it is a version of: seven years, as tax records are.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('fields', PersonalDataEntry::CONTENT,
                'An earlier state of a copied Airtable row.',
                Erasure::keep('a financial record of the business, kept for the tax period like the books it copies')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
