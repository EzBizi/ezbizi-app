<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\EmailVerificationLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signup email verification (TODO #22).
 *
 * Sibling of PasswordResets, and deliberately shaped like it: a random token
 * that exists in plaintext only inside the message, stored as a sha256 hash,
 * single use, with anything outstanding invalidated when a new one is issued.
 *
 * ── Why 48 hours ─────────────────────────────────────────────────
 *
 * A reset lives an hour because the person asking for one is, by definition,
 * sitting at the screen right now. A confirmation is the opposite: it arrives
 * while somebody is signing up on a Friday afternoon and is read on Monday. An
 * hour would turn "check your email" into a support conversation. Resending is
 * free, so the cost of this being generous is small and the cost of it being
 * mean is a customer who cannot finish signing up.
 *
 * ── Tenancy ──────────────────────────────────────────────────────
 *
 * Redemption runs with NO session and NO tenant — the whole point is that it is
 * reachable from a link in a mailbox. Users are tenant-scoped, so every read
 * here goes through runWithoutTenant, exactly as PasswordResets::subjectsFor
 * does for the same reason. Forgetting that does not fail loudly: the scope
 * simply returns nothing and a valid token reads as an invalid one.
 */
class EmailVerifications
{
    private const LIFETIME_HOURS = 48;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Mint a token for this user, invalidating any outstanding one.
     *
     * Returns the PLAINTEXT token; it is never readable again after this.
     *
     * @return array{token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issue(User $user, ?string $ip = null): array
    {
        // Anything outstanding stops working. Two live tokens means an older
        // message still sitting in the mailbox is a second way in, and a resend
        // must invalidate what it replaces or "resend" becomes "issue another".
        DB::table('email_verifications')
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token     = Str::random(48);
        $expiresAt = now()->addHours(self::LIFETIME_HOURS);

        DB::table('email_verifications')->insert([
            'id'           => (string) Str::uuid7(),
            'user_id'      => $user->getKey(),
            // The address as it is RIGHT NOW, so a later change to the account
            // cannot be confirmed by a link that went somewhere else.
            'email'        => $user->email,
            'token_hash'   => hash('sha256', $token),
            'expires_at'   => $expiresAt,
            'requested_ip' => $ip,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Spend a token and mark the account verified.
     *
     * Returns the user on success and null on anything else — expired, already
     * spent, unknown, or pointing at an address the account no longer uses. The
     * caller answers identically whichever it was, so a token cannot be used to
     * learn whether an account exists.
     */
    public function redeem(string $token): ?User
    {
        $row = $this->liveToken($token);

        if ($row === null) {
            return null;
        }

        $user = $this->tenant->runWithoutTenant(
            fn () => User::query()->whereKey($row->user_id)->first()
        );

        if ($user === null) {
            return null;
        }

        // The address moved after the link was sent. Proving you can read the
        // old mailbox says nothing about the new one, and confirming here would
        // hand a verified badge to an account whose address nobody has checked.
        if (! hash_equals((string) $row->email, (string) $user->email)) {
            return null;
        }

        DB::transaction(function () use ($row, $user) {
            DB::table('email_verifications')
                ->where('id', $row->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);

            // Idempotent by construction: already-verified stays at its original
            // timestamp rather than being moved forward by a second click on the
            // same link, which people do.
            if ($user->email_verified_at === null) {
                $this->tenant->runWithoutTenant(function () use ($user) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                });
            }
        });

        return $user->refresh();
    }

    /**
     * Mint a token and send it, and never let a mail failure reach the caller.
     *
     * Lives here rather than on a controller because both entry points need it
     * and neither owns it: signup sends the first one, the resend endpoint sends
     * the rest. One place decides what a verification message is, so the two
     * cannot drift apart.
     *
     * Returns whether the message actually left. Signup does NOT fail when this
     * is false - the account and its organization are committed by then, and a
     * mail outage must not undo a signup that otherwise worked. The person is
     * told to check their mail, and can ask for another link.
     */
    public function issueAndSend(User $user, ?string $ip = null): bool
    {
        $issued = $this->issue($user, $ip);

        try {
            Notification::route('mail', $user->email)
                ->notify(new EmailVerificationLink($user, $issued['token'], $issued['expires_at']));

            return true;
        } catch (Throwable $e) {
            // The user id, never the address and never the token. A log is not a
            // place to put a live credential.
            Log::warning('Verification email could not be sent.', [
                'user_id' => $user->getKey(),
                'reason'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** The row for this token if it is unspent and unexpired, else null. */
    private function liveToken(string $token): ?object
    {
        return DB::table('email_verifications')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
