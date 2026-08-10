<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Auth\SessionLifetime;
use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep the idle window open while somebody is working
 * (plan §6 — `security.session_timeout_minutes`).
 *
 * ── What this does, and what it deliberately does not ────────────
 *
 * It does not refuse anybody. Sanctum's guard already refuses a token whose
 * `expires_at` has passed, so the idle timeout is expressed as that expiry and
 * this middleware's only job is to push it forward each time the token is used.
 * Stop using it and it stops moving; the setting decides how far ahead it sits.
 *
 * Putting the enforcement here instead would mean a second, parallel notion of
 * an expired token living beside Sanctum's — and the two disagreeing is exactly
 * how somebody ends up signed out of a session the framework still considers
 * valid, or the reverse.
 *
 * ── Why not `last_used_at`, which already exists ─────────────────
 *
 * Sanctum stamps `last_used_at` inside the guard, BEFORE any route middleware
 * runs, so by the time this is reached it always reads "now" and the idle
 * interval it would measure is always zero. Reading it before authentication
 * would mean resolving the bearer token a second time, and then deciding
 * expiry in two places anyway.
 *
 * ── Why the write is conditional ─────────────────────────────────
 *
 * The target moves with the clock, so writing it unconditionally is one UPDATE
 * per API call — and a screen that polls is a lot of API calls. A minute of
 * slack costs nothing against an eight-hour window and turns that into roughly
 * one write a minute per active session.
 *
 * The comparison is absolute, in both directions, on purpose. An administrator
 * SHORTENING the window expects it to bite: taking only the later of the two
 * would leave every session already open running on the old, longer setting
 * until it ended on its own.
 */
class SlideIdleWindow
{
    /** How far the stored expiry may drift from the target before it is rewritten. */
    private const TOLERANCE_SECONDS = 60;

    public function __construct(
        private readonly SessionLifetime $lifetime,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        if ($user?->organization !== null && $this->isSlidable($token)) {
            $this->slide($token, $user->organization);
        }

        return $next($request);
    }

    /**
     * Is there a stored expiry here to move?
     *
     * Three things arrive as `currentAccessToken()` and only one of them has one:
     *
     *   · null — nothing authenticated this request, or the guard is not Sanctum.
     *   · a TransientToken — session-cookie authentication, which has no row and
     *     no expiry; the session's own lifetime governs it.
     *   · a real PersonalAccessToken.
     *
     * And a fourth, which is why this is a method rather than one `instanceof`:
     * `Sanctum::actingAs()` installs a MOCK of the token model, which passes
     * `instanceof` and answers `false` to every attribute it was not told about.
     * That is not merely a test artifact to be tolerated — it is the general
     * statement that a token object with no issue time has no ceiling to measure
     * from, and the arithmetic below would be nonsense rather than merely wrong.
     * Without this the entire authenticated suite raised a TypeError.
     */
    private function isSlidable(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && $token->exists
            && $token->created_at instanceof DateTimeInterface;
    }

    private function slide(PersonalAccessToken $token, Organization $organization): void
    {
        // created_at, not now(), so the absolute ceiling counts from when the
        // session began. Measuring it from the current request would make the
        // ceiling slide too, which is the one thing it exists not to do.
        $target = $this->lifetime->expiryFor($organization, Carbon::instance($token->created_at));

        $stored = $token->expires_at;

        if ($stored instanceof DateTimeInterface
            && Carbon::instance($stored)->diffInSeconds($target, absolute: true) <= self::TOLERANCE_SECONDS) {
            return;
        }

        // forceFill because expires_at is not in Sanctum's $fillable, and this
        // is the same route the guard takes to stamp last_used_at.
        $token->forceFill(['expires_at' => $target])->save();
    }
}
