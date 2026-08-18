<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Microsoft 365 sign-in somebody has started and not yet finished.
 *
 * Deliberately does not use BelongsToTenant — the callback has no tenant. Same
 * argument as {@see GoogleMailSignIn}.
 */
class MicrosoftMailSignIn extends Model implements StatesRetention
{
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere(
            'the sign-in flow itself — App\Support\Email\MicrosoftOAuthSignIns deletes the row '
            .'the moment the sign-in finishes, and clears expired ones whenever another '
            .'sign-in begins',
            'A sign-in to Microsoft 365 that somebody has started and not yet finished. It holds no '
            .'message, no name and no address — only which inbox is being connected, who '
            .'started it, and a one-time value that expires ten minutes after it is issued. '
            .'The row is deleted as soon as the sign-in finishes, and abandoned ones are '
            .'cleared when the next sign-in begins, so nothing accumulates. Who connected a '
            .'mailbox and when is recorded in the audit log instead, which is built to be '
            .'read and expires on its own schedule.'
        );
    }

    /** @var array<int, string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'code_verifier' => 'encrypted',
            'expires_at'    => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
