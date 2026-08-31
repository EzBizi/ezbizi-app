<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a person saw on a handset when they rang this number.
 *
 * No Twilio API reports whether a number currently shows as "Scam Likely" on a
 * given carrier — not one. The only way to know is for somebody to ring the
 * number from a phone on that carrier and write down what appeared. This is
 * that record, and an empty list means nobody has looked rather than that a
 * sync failed.
 */
class PhoneTrustObservation extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'scope_type', 'scope_id',
        'e164', 'carrier', 'observed', 'observed_by', 'observed_at',
    ];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }

    /**
     * Kept for as long as the phone account, because the value of this log is
     * entirely in its history: "clean on Verizon in August, labelled again in
     * October" is the finding, and a sweep window would delete the earlier
     * half of every such pair.
     *
     * The only personal data is which colleague looked, and that is already
     * nulled when the user is deleted by the foreign key.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A note somebody wrote down after ringing one of your numbers from '
            .'a handset, recording what the screen showed — the carrier, the '
            .'label, and who looked. Kept with the phone account so the history '
            .'stays comparable, and deleted with it.'
        );
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by');
    }
}
