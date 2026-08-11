<?php

namespace App\Services\Hr;

use App\Models\Invitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Minting, finding and spending invitation tokens.
 *
 * ── The token is a credential, so it is treated like one ─────────
 *
 * The raw value exists in exactly two places: the local variable this class
 * returns it in, and the response body the inviter reads it from. It is never
 * stored, never logged, and never on a model attribute — `token_hash` is
 * unfillable on Invitation for that reason, so this is the only code that can
 * write one.
 *
 * sha256 rather than bcrypt, for the reasons PasswordResets sets out: 48 random
 * characters have no guessing to slow down, and the public endpoint has to find
 * a row BY the value, which bcrypt cannot do without hashing against every row
 * in the table.
 *
 * ── Why the lookup runs without a tenant ─────────────────────────
 *
 * Somebody opening an invitation link has no account and no session, so nothing
 * has established an organization — the token is the only thing that names one.
 * `runWithoutTenant()` is the sanctioned way to say that out loud (§5.1), and it
 * covers the READ only: every write that follows happens inside
 * `TenantContext::run()` for the organization the token turned out to belong to,
 * because platform scope skips the tenant stamp AND the subscription floor, and
 * neither of those may be skipped for a real business write.
 */
class Invitations
{
    /**
     * A week, the owner's decision, and the same clock the abandoned status is
     * derived from — there is only one number, so the two cannot disagree.
     */
    public const LIFETIME_DAYS = 7;

    /** Long enough that guessing is not a strategy; the same length as a reset token. */
    private const TOKEN_BYTES = 48;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Create an invitation and hand back its one-time link.
     *
     * The raw token comes back beside the model rather than on it, because the
     * caller needs it exactly once and nothing should be able to ask the model
     * for it afterwards.
     *
     * @param  array<string, mixed>  $attributes  everything except the token
     * @return array{invitation: Invitation, token: string}
     */
    public function issue(array $attributes): array
    {
        $token = $this->newToken();

        $invitation = new Invitation($attributes);

        $invitation->forceFill([
            'token_hash' => $this->hash($token),
            'sent_at'    => now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ])->save();

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * A new token and a fresh week — the owner's "resend".
     *
     * Three things change together, and they have to. The old token stops
     * working, because its hash is overwritten and there is only one column: an
     * invitation that could be accepted from either of two links is not
     * single-use. The clock restarts, which is what makes a resend worth doing
     * for one that already lapsed. And `opened_at` is cleared, because "received"
     * means somebody opened THE live link, and the one they opened is now dead —
     * leaving it set would show a chased invitation as already delivered.
     */
    public function resend(Invitation $invitation): string
    {
        $token = $this->newToken();

        $invitation->forceFill([
            'token_hash' => $this->hash($token),
            'sent_at'    => now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            'opened_at'  => null,
        ])->save();

        return $token;
    }

    /**
     * The invitation this token names, if it can still be used.
     *
     * Returns null for every kind of dead token — unknown, expired, revoked,
     * already accepted — and that uniformity is the point rather than a
     * convenience. A caller that could tell them apart would eventually SAY them
     * apart, and "already used" tells a stranger that the address they guessed
     * belongs to a real employee of a real organization.
     *
     * Reads across tenants by necessity and says so; nothing is written here.
     */
    public function open(string $token): ?Invitation
    {
        $invitation = $this->tenant->runWithoutTenant(
            fn () => Invitation::query()
                ->with(['organization', 'role', 'invitedBy'])
                ->where('token_hash', $this->hash($token))
                ->first()
        );

        if ($invitation?->isOpen() !== true) {
            return null;
        }

        /*
         * The organization has to still be there.
         *
         * Organizations are soft-deleted, so `->organization` comes back null for
         * a removed one while every invitation it ever sent keeps its live token
         * and its unexpired clock. Both endpoints then enter
         * `TenantContext::run($null)` and die with a TypeError — a 500 on a
         * public route, in front of somebody who is not even a customer.
         *
         * Treated as a dead token rather than as its own error, because that is
         * what it is: there is nothing left to join.
         */
        return $invitation->organization === null ? null : $invitation;
    }

    /**
     * The link the inviter copies.
     *
     * The host comes from config (§2.1 category 2) rather than being written
     * here: a second installation of this software must not hand out links to
     * production, which is the exact bug PasswordResetLink had.
     */
    public function acceptUrl(string $token): string
    {
        return rtrim((string) config('ezbizi.hosts.customer'), '/').'/invite?token='.$token;
    }

    private function newToken(): string
    {
        return Str::random(self::TOKEN_BYTES);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
