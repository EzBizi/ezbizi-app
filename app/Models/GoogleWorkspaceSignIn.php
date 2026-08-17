<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Workspace-domain Google sign-in that has been started and not yet finished.
 *
 * Same shape as {@see GoogleMailSignIn}: the callback is unauthenticated, so
 * this row IS the identification. mailbox_id is absent because nothing is being
 * attached to an inbox — the domain is.
 */
class GoogleWorkspaceSignIn extends Model implements StatesRetention
{
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere(
            'the sign-in flow itself — App\Support\Email\GoogleWorkspaceSignIns deletes the row '
            .'the moment the sign-in finishes, and clears expired ones whenever another '
            .'sign-in begins',
            'A Google sign-in started to prove a Workspace administrator runs a domain. It '
            .'holds no mail. The row is deleted when the sign-in finishes, and abandoned ones '
            .'are cleared when the next sign-in begins.'
        );
    }

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
