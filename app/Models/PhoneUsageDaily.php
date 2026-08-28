<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
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
class PhoneUsageDaily extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /**
     * ── Why with the parent, and not a window ────────────────────
     *
     * There is no personal data here. A row is a day, a category, a number of
     * minutes and a price — no caller, no number dialled, nothing said. So the
     * question §9 asks of a person's data does not arise, and the question
     * §10 asks about growth is answered by the cascade: both foreign keys are
     * ON DELETE CASCADE, so the rows go when the phone account goes and when
     * the organization goes.
     *
     * A sweep window was the other candidate and I rejected it deliberately.
     * These are the figures the whole table exists to make invoicing POSSIBLE
     * from — deleting them on a timer would destroy the billing history before
     * anybody has decided whether to bill, which is the exact mistake the
     * table was created to avoid in the other direction.
     *
     * ── What this costs, said plainly ────────────────────────────
     *
     * It grows while an account lives: roughly five categories a day, so a few
     * thousand rows per phone account per year. Small, but unbounded, and the
     * honest name for that is a decision to keep rather than an oversight.
     *
     * It also means a company that DISCONNECTS phone service loses its cost
     * history with it. That follows from the cascade rather than from this
     * policy, and it is the right trade only while nobody is invoicing from
     * these figures. The day somebody does, the connection cascade wants
     * revisiting before this policy does.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'What your phone service cost, day by day. It holds no personal '
            .'information — no callers, no numbers, no recordings — only how '
            .'many minutes and messages were used and what they were charged. '
            .'It is kept for as long as the phone account exists so the '
            .'history stays available, and is deleted with it.'
        );
    }

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
