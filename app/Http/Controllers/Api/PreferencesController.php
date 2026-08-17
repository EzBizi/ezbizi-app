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
     * This used to wrap the merge in OrganizationCanvasController::cleanedInto,
     * because the structure canvas was a per-user preference and this method is
     * one of the ways a stored one leaves the server. It is an organization
     * setting now, held in the `settings` table and served with the tree it
     * describes, and the old key is stripped from every user by the migration
     * that retired it. So the wrapper had no job left, and a call that guards a
     * key nothing can hold is the code-with-no-job the house rules say to
     * delete rather than to keep "just in case".
     */
    private function resolved($user): array
    {
        return array_merge($this->registry->defaults(), $user->preferences ?? []);
    }
}
