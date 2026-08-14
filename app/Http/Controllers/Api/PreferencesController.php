<?php

namespace App\Http\Controllers\Api;

use App\Support\Preferences\PreferenceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A user's own preferences (§2.1 category 6).
 *
 * No policy, and no audit. Both are deliberate.
 *
 * No policy because every query here is bound to $request->user() — there is
 * nothing to authorize beyond being signed in, and a permission would imply one
 * user might set another's, which is never true.
 *
 * No audit because §10 records what people do to the BUSINESS. Somebody
 * switching to dark mode is not an action anyone will ever need to reconstruct,
 * and writing it would dilute a log whose value is that everything in it matters.
 */
class PreferencesController extends Controller
{
    public function __construct(
        private readonly PreferenceRegistry $registry,
    ) {}

    /** GET /api/v1/me/preferences */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->resolved($request->user()),
            // The catalogue travels with the values so the settings screen can
            // render controls it was not written for. A preference added next
            // year needs no client change to appear.
            'meta' => ['definitions' => $this->registry->all()],
        ]);
    }

    /**
     * PATCH /api/v1/me/preferences
     *
     * Accepts a partial map. Anything not sent keeps its current value, so two
     * screens changing different preferences cannot clobber each other.
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->validate([
            'preferences' => ['required', 'array'],
        ])['preferences'];

        $user = $request->user();
        $current = $user->preferences ?? [];

        foreach ($input as $key => $value) {
            try {
                $current[$key] = $this->registry->cast($key, $value);
            } catch (InvalidArgumentException $e) {
                // The registry already states the rule and the permitted values;
                // rewording it here is how two sources of truth start.
                throw ValidationException::withMessages(["preferences.{$key}" => $e->getMessage()]);
            }
        }

        $user->forceFill(['preferences' => $current])->save();

        return response()->json(['data' => $this->resolved($user)]);
    }

    /**
     * Stored values over defaults.
     *
     * Always complete, so the client never has to know a default. A preference
     * added later starts appearing with its default the moment it is defined,
     * without touching a single stored row.
     *
     * ── Why the canvas cleaner is called here ─────────────────────
     *
     * Because this is a way OUT, and it was not being treated as one. The
     * out-clean was written on OrganizationCanvasController and called from the
     * /me payload alone, under a comment saying /me was the only place a stored
     * arrangement leaves the server. It was not: this method is the body of both
     * GET /me/preferences and the 200 of PATCH /me/preferences, and array_merge
     * passes every stored key through untouched — including keys the registry
     * has never heard of, which `organization.canvas` deliberately is. A blob
     * restored from a backup taken before that feature clamped, or hand-edited,
     * therefore reached the client with its coordinates unchecked through the
     * endpoint literally named for preferences, while the same values came back
     * clamped through /me.
     *
     * Nothing shipped is broken by that today — the Flutter client never reads
     * the body of either call — which is exactly the argument the cleaner was
     * written against: the check must not sit on the far side of the wire, and
     * the desktop and mobile clients the house rules require would reach for
     * this endpoint first.
     *
     * The write side is already closed and stays that way: update() casts every
     * key through the registry, which has no `organization.canvas` definition and
     * throws, so a 422 — not this method — is what refuses a canvas PATCHed at
     * this endpoint.
     */
    private function resolved($user): array
    {
        return OrganizationCanvasController::cleanedInto(
            array_merge($this->registry->defaults(), $user->preferences ?? []),
        );
    }
}
