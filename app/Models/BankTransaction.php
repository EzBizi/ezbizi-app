<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank or card transaction read through Plaid (PlaidSync). amount_cents
 * is Plaid's sign: positive is money out, negative is money in.
 */
class BankTransaction extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'authorized_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'pending' => 'boolean',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A transaction on a business bank or card account the organization connected through '
            .'Plaid: date, amount, the merchant and the category. Up to two years back are read when '
            .'the bank is connected. Kept while the bank is connected and deleted with the connection '
            .'when somebody disconnects it.'
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
