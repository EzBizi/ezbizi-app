<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short number that reaches one person on one phone account. */
class PhoneExtension extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'user_id', 'number_id', 'extension',
    ];

    /**
     * Goes with the phone account, and with the person.
     *
     * Both foreign keys cascade, so an extension cannot outlive either end of
     * what it connects. There is nothing to sweep on a timer: an extension is
     * an address, and an address that expires while the person is still here
     * would silently stop a caller reaching them.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'The short number callers can dial to reach one person directly — '
            .'the digits and who they ring. Deleted with the phone account, and '
            .'with the person it belongs to.'
        );
    }

    /**
     * The line this person's outbound calls go out from.
     *
     * Null is not a gap: it means they call out from whichever line the Call
     * tab has selected, which is how everybody behaved before this existed.
     */
    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
