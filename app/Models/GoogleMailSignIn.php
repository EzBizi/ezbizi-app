<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google sign-in somebody has started and not yet finished.
 *
 * One row exists between pressing "Sign in with Google" and Google redirecting
 * the browser back. It carries the four bindings that make the callback safe —
 * organization, user, mailbox and the hashed `state` — and the PKCE verifier
 * that never leaves this server. See the migration for the argument in full.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  IT DELIBERATELY DOES NOT USE BelongsToTenant, AND THAT IS THE
 *  WHOLE REASON THE COLUMN IS ON THE ROW.
 *
 *  Every other tenant-owned model in this application carries that
 *  trait, so departing from it needs a reason rather than a
 *  preference. The reason is that this model is read from a route
 *  with NO TENANT: Google finishes the flow by redirecting a browser,
 *  which carries no bearer token, so `auth:sanctum` and the `tenant`
 *  middleware cannot run and TenantScope would throw on the first
 *  query.
 *
 *  This is the same shape as App\Models\Invitation being looked up by
 *  InvitationAcceptanceController: a credential arriving from outside
 *  is what NAMES the organization, so the organization cannot already
 *  be known when the credential is read.
 *
 *  What replaces the scope is stricter rather than weaker, and it is
 *  worth being precise about why. An unscoped read here is safe
 *  because there is nothing to enumerate: rows are found ONLY by
 *  `state_hash`, which is the SHA-256 of 64 random characters. Then
 *  the controller enters {@see organization} and does every subsequent
 *  read inside it — so the mailbox this flow may touch is resolved
 *  under the tenant named on THIS row, and a state minted in one
 *  organization cannot reach a mailbox in another even if its id were
 *  known. The binding is not a filter applied after the fact; it is
 *  the scope the lookup happens inside.
 * ═══════════════════════════════════════════════════════════════════
 */
class GoogleMailSignIn extends Model implements StatesRetention
{
    use HasUuids;

    /**
     * Configuration of a flow in progress, and nothing anybody keeps (§10).
     *
     * `elsewhere` rather than a window, because a window is the wrong shape of
     * answer for a credential that lives ten minutes. `ezbizi:expire-data` runs
     * nightly and RetentionPolicy::window() refuses anything shorter than a
     * month — so asking the sweep to govern this would mean either lying about
     * the number or keeping a pending sign-in for thirty days.
     *
     * What actually removes rows is named here so a reader can go and check it:
     * the callback DELETES the row it reads (that delete is the single-use
     * burn), and every new sign-in first clears whatever has expired. Both live
     * in App\Support\Email\GoogleOAuthSignIns.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere(
            'the sign-in flow itself — App\Support\Email\GoogleOAuthSignIns deletes the row '
            .'the moment the sign-in finishes, and clears expired ones whenever another '
            .'sign-in begins',
            'A sign-in to Google that somebody has started and not yet finished. It holds no '
            .'message, no name and no address — only which inbox is being connected, who '
            .'started it, and a one-time value that expires ten minutes after it is issued. '
            .'The row is deleted as soon as the sign-in finishes, and abandoned ones are '
            .'cleared when the next sign-in begins, so nothing accumulates. Who connected a '
            .'mailbox and when is recorded in the audit log instead, which is built to be '
            .'read and expires on its own schedule.'
        );
    }

    /**
     * Nothing is fillable, on purpose.
     *
     * Every column on this row is a security binding — which organization, which
     * person, which mailbox, and the two secrets. None of it may ever be
     * assembled from a request body, and there is exactly one writer:
     * {@see \App\Support\Email\GoogleOAuthSignIns::begin}, which uses
     * `forceFill`. An empty `$fillable` means a future `create($request->all())`
     * anywhere writes nothing at all rather than writing an attacker's mailbox
     * id.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            /*
             * Encrypted, like every other secret in this application, and for
             * the reason the migration gives: an authorization code lifted from
             * a log or a browser history is worthless without the verifier, and
             * that stops being true if a database dump carries it in the clear.
             */
            'code_verifier' => 'encrypted',
            'expires_at'    => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Already past the ten-minute window, whether or not anything deleted it. */
    public function hasExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
