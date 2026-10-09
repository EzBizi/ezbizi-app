<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invitations that are not going to become people unless somebody does
 * something (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── What "stalled" means here, stated rather than assumed ────────
 *
 * Invitation::status() derives six words, and four of them are not a problem: a
 * `completed` one worked, a `revoked` one was withdrawn on purpose, and a `sent`
 * or `received` one from this morning is simply young. Counting those would make
 * the panel a restatement of the Invitations tab rather than a reason to open it.
 *
 * So this counts the two ways an invitation quietly fails:
 *
 *   expired      the week ran out and nobody accepted — the link is dead and
 *                only a resend fixes it
 *   never opened still live, but sent a while ago and never even clicked —
 *                usually a wrong address or a mail folder
 *
 * `revoked` is excluded from both, which is the whole reason it was made its own
 * status rather than being collapsed into `abandoned`: this panel exists to
 * answer "are the invitations we send being ignored", and every withdrawal
 * counted here would inflate that number with a decision the organization made
 * deliberately.
 *
 * ── Why the clock is not read from the status ────────────────────
 *
 * `status()` is a PHP method over an unloaded relation, so it cannot be a WHERE
 * clause; a list filtered row by row cannot be counted in the database. The
 * predicates below are built from the same timestamps in the same order that
 * method resolves them, and the one place they could drift — what counts as
 * expired — is `expires_at` in both.
 */
class InvitationsStalled
{
    use ScopesToPlaces;

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);

        $quiet = (int) ($options['quiet_days'] ?? 3);
        // Clamped for the reason since() is: an option is user input that
        // happens to have been saved earlier.
        $quietBefore = now()->subDays(max(1, min($quiet, 365)));

        // A fresh builder per count — open() returns a new one each call, so
        // the two predicates cannot leak into each other.
        $expired = $this->open($places)
            ->where('expires_at', '<', now())
            ->count();

        $neverOpened = $this->open($places)
            ->where('expires_at', '>=', now())
            ->whereNull('opened_at')
            ->where('sent_at', '<', $quietBefore)
            ->count();

        return [
            'rows' => [
                ['title' => 'Expired before anyone accepted', 'total' => $expired],
                ['title' => 'Sent but never opened', 'total' => $neverOpened],
            ],

            'total' => $expired + $neverOpened,

            // So the client can distinguish "nothing has stalled" from "nothing
            // has been sent" — the first is good news and the second means this
            // organization has not started inviting anybody, which needs a
            // different sentence and a different next step.
            'sent_total' => $this->constrainToPlaces(Invitation::query(), $places)->count(),
        ];
    }

    /** Neither accepted nor withdrawn — the only ones that can be stalled. */
    private function open(array $places): Builder
    {
        return $this->constrainToPlaces(
            Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at'),
            $places,
        );
    }
}
