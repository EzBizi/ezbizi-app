<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of one subaccount's Twilio usage, in one category.
 *
 * Read-only in practice: written by `ezbizi:phone-usage` and never edited by
 * hand. It is a record of what a carrier charged, so a person correcting it
 * would be falsifying a bill rather than fixing a mistake.
 */
class PhoneUsageDaily extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'phone_usage_daily';

    protected $fillable = [
        'organization_id', 'connection_id',
        'day', 'category', 'description',
        'usage', 'usage_unit', 'count', 'count_unit',
        'price', 'price_unit',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'count' => 'integer',

            // usage and price stay strings. They are minutes and money as the
            // carrier reported them, and a float cast here would round before
            // anybody could add them up.
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
