<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody who asked to stop, and the place they asked to stop from.
 *
 * ── Why this outlives the audience ──────────────────────────────
 *
 * Deleting a list and rebuilding it must not resurrect the people who left. So
 * an unsubscribe is recorded against the SCOPE, not against the audience, and
 * every send in that scope reads it.
 *
 * ── Why it is scoped rather than global ─────────────────────────
 *
 * Leaving A-1 Chimney's mailing list must not remove somebody from Premier
 * Garage's. They are different businesses that happen to share a customer, and
 * one tenant must never be able to learn who unsubscribed from another.
 */
class NewsletterUnsubscribe extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An email address that asked to stop receiving marketing mail from one company, '
            .'and when. Kept for as long as the company sends newsletters at all: forgetting '
            .'it is how somebody who opted out starts receiving mail again, so this row is '
            .'the record that protects them rather than data held about them for our benefit.'
        );
    }

    protected $fillable = ['email', 'scope_type', 'scope_id', 'unsubscribed_at'];

    protected function casts(): array
    {
        return ['unsubscribed_at' => 'datetime'];
    }
}
