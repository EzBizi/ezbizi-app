<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A social OAuth sign-in somebody has started and not yet finished.
 *
 * No BelongsToTenant: the callback has no tenant. The row names the
 * organization, the person, the view, and which network. Same shape as
 * GoogleMailSignIn.
 */
class SocialSignIn extends Model implements StatesRetention
{
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere(
            'the sign-in flow itself — App\Support\Social\SocialOAuthSignIns deletes the '
            .'row the moment the sign-in finishes, and clears expired ones whenever another '
            .'sign-in begins',
            'A sign-in to a social network that somebody has started and not yet finished. '
            .'It holds no post and no token — only which view is being connected, which '
            .'network, who started it, and a one-time value that expires ten minutes after '
            .'it is issued. The row is deleted as soon as the sign-in finishes.'
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
