<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bank or card account read through Plaid (PlaidSync), with its last balances. */
class BankAccount extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'current_cents' => 'integer',
            'available_cents' => 'integer',
            'limit_cents' => 'integer',
            'balances_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A business bank or card account the organization connected through Plaid: its name, '
            .'the last four digits, its type and its last balances. Kept while the bank is connected '
            .'and deleted with the connection when somebody disconnects it. EzBizi never holds the '
            .'full account or routing number.'
        );
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'social_connection_id');
    }
}
