<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A caller this company will not take a call from.
 *
 * Held for the company, not the line — see the migration. The check runs on
 * the inbound webhook before anything routes, so a blocked call never becomes
 * a ringing phone in anybody's pocket.
 */
class PhoneBlockedNumber extends Model implements StatesRetention
{
    /** @use HasFactory<\Database\Factories\PhoneBlockedNumberFactory> */
    use HasFactory;

    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'e164', 'reason', 'blocked_by',
    ];

    /**
     * Kept until somebody unblocks.
     *
     * A block that expired on its own would let a robocaller back in on a
     * schedule nobody chose, and the first sign of it would be the phone
     * ringing again.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One row per caller a company has blocked. Removed when somebody unblocks '
            .'them, and never before: an expiring block silently readmits the caller '
            .'it was created to keep out.'
        );
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * Is this caller blocked on this company's phone account?
     *
     * `$e164` empty means Twilio sent no caller — an anonymous or withheld
     * number — which is NOT the same as a blocked one and must not match a
     * blank row. Nothing writes a blank row today, and this is one `=== ''`
     * standing between that and every anonymous caller being rejected.
     */
    public static function blocks(string $connectionId, string $e164): bool
    {
        if ($e164 === '') {
            return false;
        }

        return static::query()
            ->where('connection_id', $connectionId)
            ->where('e164', $e164)
            ->exists();
    }
}
