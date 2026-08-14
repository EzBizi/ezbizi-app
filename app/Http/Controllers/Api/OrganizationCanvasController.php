<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Where one person put the boxes on the Organization tab's structure canvas
 * (§2.1 category 6).
 *
 * The Organization tab draws the business — organization, companies, sub
 * companies, franchises — as boxes the user drags into an arrangement that
 * suits them. This endpoint stores that arrangement, and nothing else. It is a
 * picture of the structure, never the structure: see "What this is NOT" below,
 * which is the whole reason the file exists rather than a method somewhere.
 *
 * ── Why a controller of its own ──────────────────────────────────
 *
 * CompaniesController was the obvious home and is the wrong one twice over.
 * Every method in that class calls $this->authorize() against a Company policy,
 * writes a business record, logs an audit entry and emits an automation event;
 * this method does none of the four, and it would be the only one in a class
 * whose docblock opens "Every rule in §3 is enforced here". Worse, that file is
 * where §3's write-once-parent rule lives — `'parent_company_id' => ['missing']`
 * in update() — and a second method there accepting `{id, x, y}` for companies
 * is exactly the thing a future reader mistakes for the move endpoint §3
 * forbids.
 *
 * DashboardController was the other candidate. Its ORDERABLE constant is a
 * fixed list of DASHBOARD SURFACES and saveLayout's `surface` field says which
 * dashboard is being saved. The Organization tab is not a dashboard, there are
 * no widgets, and the payload is coordinates rather than keys-and-options —
 * adding 'organization' to that list would leave `widgets.*.key` validating a
 * company id, which is a rule lying about what it checks.
 *
 * What DOES carry over from DashboardController is its discipline, and all of
 * it does: written straight into `users.preferences` rather than through the
 * PreferenceRegistry, merged into the blob rather than replacing it, cleaned on
 * the way in AND on the way out, echoing what was stored rather than what was
 * sent, and no audit and no emit for reasons stated rather than assumed.
 *
 * ── What this is NOT ─────────────────────────────────────────────
 *
 * Dragging a box moves the PICTURE, not the business. §3 is absolute that
 * entities are never moved between parents — every setting, assignment and
 * roll-up below a company is expressed in terms of where it sits, so moving one
 * silently rewrites history. Nothing in this payload can express a parent, and
 * that is deliberate rather than incidental: the body carries a kind, an id and
 * two numbers, so there is no field here a re-parent could arrive in even by
 * accident, and no reader of this file can mistake it for one.
 *
 * ── Not through the PreferenceRegistry ───────────────────────────
 *
 * PATCH /me/preferences and PreferenceRegistry::cast exist for the controls the
 * Settings screen renders — each one a named, described, individually castable
 * value that screen draws a widget for. A canvas arrangement is not one of
 * those: it is a variable-length list of coordinates nobody would ever set from
 * a settings form. `dashboard.widgets` and `dashboard.order` are written the
 * same way, directly, for the same reason. Said here so nobody later "tidies"
 * this into the registry and gives the Settings screen a control it cannot draw.
 *
 * ── No audit, no policy, no automation event ─────────────────────
 *
 * All three omissions are deliberate, and each is written down because a write
 * path with no audit call is otherwise indistinguishable from one where
 * somebody forgot — DashboardController's own words, and the same is true of a
 * write path with no emit.
 *
 * No POLICY, for PreferencesController's reason: every query here is bound to
 * $request->user(), so there is nothing to authorize beyond being signed in,
 * and a permission would imply one user might arrange another's canvas, which
 * is never true. Nor is there an `organizations.canvas` permission to invent —
 * §4 gates acts on the BUSINESS, and looking at a picture of a tree you already
 * fetched from GET /companies (which authorizes viewAny) is not one. A
 * permission that nothing ever refuses is a dead row in every role editor.
 *
 * No AUDIT, for the same controller's reason: §10 records what people do to the
 * business. Where somebody parked a box is not an action anyone will ever need
 * to reconstruct, and writing it would dilute a log whose whole value is that
 * everything in it matters.
 *
 * No TRIGGER — no `organization.canvas_arranged` — and this one is worth all
 * four reasons, because it is the omission most likely to be "fixed":
 *
 *   1. ModuleDefinition::assertKeysMatchSubject forces a trigger's key prefix
 *      to match its module's declared subject, and `organizations` declares
 *      Organization. The key would therefore claim to be about the Organization
 *      record while describing a row in `users`, and the field picker would
 *      offer Organization fields for an event that has none. Moved under a
 *      `users` module instead, the subject is one nothing can write to —
 *      ActionRunner::WRITABLE holds Job and Customer and nothing else. When an
 *      event has no honest home, it is not an event.
 *   2. AutomationEngine::scopeOf would answer [null, null]: a preferences blob
 *      is not a model, and User neither implements CarriesAutomationScope nor
 *      carries franchise_id. That is precisely the failure CarriesAutomationScope
 *      was written about — a franchise-level rule that matches nothing for the
 *      life of the rule, with nothing on screen saying why.
 *   3. EventEmitter::emit writes the per-organization outbox and
 *      AutomationEngine::deliver queues against every active rule in the tenant.
 *      One person nudging a box in their own browser would become tenant-wide
 *      queued work, once per debounced save.
 *   4. ModuleRegistryTest fails the build either way. `isRaised: true` with no
 *      emit behind it fails its grep; `isRaised: false` puts a permanently dead
 *      row in every tenant's rule builder. TriggerDefinition::NOT_RAISED_YET
 *      exists to keep a real, planned gap honest — not to make a fabricated
 *      trigger cheap.
 *
 * No automation ACTION that rearranges somebody's canvas either. It would write
 * ANOTHER user's category-6 preferences, which would make PreferencesController's
 * absolute — nobody sets somebody else's — false for the first time, in exchange
 * for a cosmetic payoff. Same class of refusal as `roles` and `automations`.
 *
 * The positive half, so the omission is not mistaken for a gap: every fact this
 * canvas draws already raises its own event. CompaniesController emits
 * company.created, company.updated and company.deactivated; FranchisesController
 * emits franchise.created, franchise.updated and franchise.deactivated. The
 * drift that "Arrange for me" exists to fix — new companies appearing since
 * somebody last tidied their picture — is therefore already something a tenant
 * can build a rule on today, on triggers this feature did not have to add.
 *
 * And the alarm, stated where somebody will trip over it: there is no
 * `company.reparented` trigger because no code path can reparent a company. That
 * absence is §3 written into the event catalogue, the same rule this canvas
 * expresses in pixels. If a future task proposes such a trigger, that is the
 * signal that the drag has started moving the business rather than the picture.
 */
class OrganizationCanvasController extends Controller
{
    /**
     * Where one person's arrangement lives inside their preferences.
     *
     * A public const so tests and any future reader name it rather than spell
     * it — the same reason DashboardController::ORDER_PREFERENCE is public.
     */
    public const PREFERENCE = 'organization.canvas';

    /**
     * The kinds of box the canvas can hold.
     *
     * A closed list, deliberately, and not "whatever the client sends". This
     * lands in `users.preferences`, a JSON column read on every request that
     * returns /me; an open vocabulary would let a client grow that blob with
     * entries naming shapes that will never exist, and nothing would ever clear
     * them.
     *
     * A SUB COMPANY IS A 'company'. A sub company is a company row that happens
     * to have a parent — CompaniesController nests them for drawing, but the
     * model makes no other distinction — so a fourth kind here would be the
     * client inventing a difference the database does not have, and the first
     * thing to break would be a box that stopped being findable the day
     * somebody's structure was read back by a different screen.
     *
     * Not something an organization configures: these are §3's fixed depth
     * (Organization → Company → Sub Company → Franchise), so this list moves
     * when the model does and not when a customer decides something.
     */
    public const KINDS = ['organization', 'company', 'franchise'];

    /**
     * How many boxes one arrangement may name.
     *
     * ORDERABLE's reason again: this is a JSON column read on every /me. 500 is
     * chosen so it reads as a defence rather than as a limit — 500 entries of
     * roughly sixty bytes is about 30KB, and it is far above any structure a
     * person arranges by hand.
     *
     * REFUSED above the cap rather than truncated, and that is the one place
     * this endpoint refuses where it otherwise cleans. The difference is
     * visibility: a clamped coordinate is a box a few pixels from where it was
     * dropped, which nobody can see and nobody chose, whereas silently dropping
     * the last fifty boxes would mean an arrangement that never sticks, forever,
     * with nothing on screen saying why. A 422 gets a sentence in front of
     * somebody who can act on it.
     *
     * The honest note that belongs beside it: a tenant with more than 500 boxes
     * is past what a free canvas can usefully show anyway, and the indented
     * outline the same tab falls back to on a narrow screen is the view that
     * still works for them.
     */
    public const MAX_BOXES = 500;

    /**
     * The furthest from the origin a box may sit.
     *
     * Not a canvas size — the canvas has no fixed size, it is panned. This is a
     * sanity bound: about twenty screens across, unreachable by dragging, and
     * its job is to turn a corrupt write into a clamp rather than into a screen
     * nobody can open. A stored 1e300 handed to a Flutter Transform throws, and
     * the throw lands on the person who happened to open the tab rather than on
     * whatever produced the number.
     *
     * Zero is the floor for the matching reason: the origin is the top-left of
     * the viewport's extent, so a negative coordinate is a box nobody can scroll
     * to. Both ends are CLAMPED rather than refused — see coordinate().
     */
    public const MAX_COORDINATE = 20000;

    /**
     * Save an arrangement.
     *
     * ── Clean, don't refuse — with the one exception above ────────
     *
     * asDeclared's argument, applied to coordinates. A value nobody can see and
     * nobody chose is not worth dead-ending a save over: a drag produces
     * doubles, so `numeric` rather than `integer` and the server rounds, because
     * a 422 over a half-pixel would be a refusal about something invisible. The
     * same goes for a coordinate out of range and for a box named twice. What
     * was actually stored comes back in the response, so nothing is corrected
     * behind the client's back.
     *
     * ── An id the caller cannot see is KEPT ───────────────────────
     *
     * No Rule::exists, no Gate check, no filtering by what the caller can see.
     * This is DashboardController::saveOrder's decision, and the argument
     * transfers more strongly rather than less, so both halves are written down:
     *
     * A position naming a company that is not here is kept, not dropped. A
     * company can be missing for the afternoon — somebody's role is being
     * edited, a module is switched off and back on, a scope is narrowed — and
     * forgetting where it sat because it happened to be absent when they nudged
     * a different box would lose an arrangement they never changed.
     *
     * And unlike `dashboard.widgets`, nothing downstream ever resolves one of
     * these. saveLayout must re-check its keys because index() hands them to
     * resolve(); a canvas position resolves nothing at all. The tree comes from
     * CompaniesController::index under the Company policy, and this list only
     * says where to draw whatever survives that. An id nobody can see therefore
     * costs a uuid string and grants exactly nothing — which is a claim, so
     * OrganizationCanvasTest asserts it rather than trusting this paragraph.
     *
     * `uuid` rather than `string|max:n` is what bounds the length without a cap
     * that would look arbitrary, and it is also what stops prose being written
     * into a column read on every /me.
     *
     * `present` rather than `required` on the list itself, for saveLayout's
     * reason: an empty array is a legal save. "I put everything back" is a real
     * answer, and refusing it would leave somebody unable to undo their own
     * arrangement.
     */
    public function save(Request $request): array
    {
        $input = $request->validate([
            'boxes'        => ['present', 'array', 'max:'.self::MAX_BOXES],
            'boxes.*.kind' => ['required', 'string', Rule::in(self::KINDS)],
            'boxes.*.id'   => ['required', 'uuid'],
            'boxes.*.x'    => ['required', 'numeric'],
            'boxes.*.y'    => ['required', 'numeric'],
        ], [
            // The cap states itself. A bare "may not have more than 500 items"
            // tells somebody the number without telling them what to do, and
            // this is the one refusal here they cannot fix by dragging.
            'boxes.max' => 'A canvas can hold '.self::MAX_BOXES.' boxes. This structure has more '
                .'than that, which is past what a free canvas can usefully show — the outline '
                .'view lists all of it.',
        ]);

        $boxes = self::normalised($input['boxes']);

        // Merged into the existing blob, never assigned over it. `preferences`
        // also holds the theme, the sidebar width and the saved scope, and an
        // arrangement that took somebody's theme with it would be a bug found
        // days later by somebody who never connected the two.
        //
        // forceFill because `preferences` is deliberately not mass-assignable.
        $user = $request->user();

        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], [self::PREFERENCE => $boxes]),
        ])->save();

        // What was STORED, not what was sent. A clamped coordinate or a
        // deduplicated entry is then visible to the client rather than corrected
        // silently, and the client applies this answer rather than its own — the
        // echo is doing work rather than decorating the response.
        return ['boxes' => $boxes];
    }

    /**
     * A resolved preference map with any stored arrangement cleaned.
     *
     * ── Cleaning on the way OUT, and where it had to live ─────────
     *
     * DashboardController::index re-runs asDeclared on a layout its own
     * saveLayout already checked, because "a layout stored before it existed is
     * still user input nobody has checked". The same is true here and more so:
     * this blob is a JSON column, it can be edited by hand, it can have been
     * written by an older build of this app, and the client hands its numbers
     * straight to a Transform that throws on a value it cannot use.
     *
     * The awkward part, stated rather than hidden: nothing on the SERVER ever
     * reads this back. The layout only ever leaves as part of a preferences
     * blob, so the out-clean has to be called from every place one of those is
     * served — which is a real cost, and the reason the alternative was
     * considered at all.
     *
     * There are three such places and all three call this: the /me payload, and
     * PreferencesController::resolved(), which is the body of GET
     * /me/preferences and of PATCH /me/preferences' answer. That last pair was
     * missed at first, under a comment here claiming /me was the only exit —
     * worth recording, because the mistake is the obvious one. /me is where the
     * client READS this preference, so it looked like the only door; it is not
     * the only door out of the column.
     *
     * The rejected alternative was to leave the out-clean entirely to the
     * client's own defensive reader. That reader exists and must keep existing:
     * a client talking to an older server, or to this one before this method
     * was written, still has to survive a blob it cannot use. But leaving it
     * THERE alone puts the only check on the far side of the wire, which is the
     * one place the server cannot vouch for — and it means the day a second
     * client exists (desktop, mobile) the check has to be written again, and
     * correctly, from the same reasoning nobody wrote down. One cleaner, on the
     * side that owns the column, called from both directions.
     *
     * Note what this does NOT do: it does not check whether an id is one the
     * caller can see. That is save()'s decision and it holds identically here —
     * a position for a company that is away for the afternoon is an arrangement
     * to be preserved, not junk to be swept up.
     *
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    public static function cleanedInto(array $preferences): array
    {
        // Absent stays absent. "Never arranged anything" and "arranged nothing"
        // are different sentences, and inventing an empty list for the first
        // would tell the client somebody made a choice they never made.
        if (! array_key_exists(self::PREFERENCE, $preferences)) {
            return $preferences;
        }

        $stored = $preferences[self::PREFERENCE];

        // A value of the wrong shape entirely — a string, a number, whatever an
        // older build or a hand edit left — reads as no arrangement rather than
        // as an error. saveOrder makes the same call for the same reason: a
        // screen that refused to open because of somebody's stored preference
        // would be a screen nobody could open and nobody could fix.
        $preferences[self::PREFERENCE] = is_array($stored) ? self::normalised($stored) : [];

        return $preferences;
    }

    /**
     * A list of boxes reduced to what this canvas can actually draw.
     *
     * One function for both directions, which is asDeclared's shape and the
     * point of it: the rules cannot drift between the write and the read,
     * because there is one copy of them. What differs between the directions is
     * only who does the REFUSING — on the way in, validate() above has already
     * turned a missing coordinate or an unknown kind into a 422 somebody can
     * read, so nothing reaches here to be dropped. On the way out there is
     * nobody to tell, so a bad entry is skipped and the rest survive: losing one
     * box is a box in the wrong place, losing the arrangement is somebody's
     * afternoon.
     *
     * The cap is applied here rather than only in validate() for the same
     * reason. Above 500 the way in is a 422 with a sentence, because somebody is
     * present to act on it; a stored blob already past the cap can only be
     * trimmed, because the alternative on a read is refusing to draw the screen.
     * Keeping both in one place is what makes "never more than MAX_BOXES" true
     * at both ends rather than true on the side somebody remembered.
     *
     * A repeated (kind, id) is honoured once and the LAST one wins. A bad write
     * must not draw one box in two places, and last-wins is what two racing
     * saves would have meant anyway. Deduplicating BEFORE the trim matters: six
     * hundred rows that are five hundred duplicates is a small arrangement
     * written badly, not an oversized one.
     *
     * @param  array<mixed>  $boxes
     * @return list<array{kind: string, id: string, x: int, y: int}>
     */
    private static function normalised(array $boxes): array
    {
        $kept = [];

        foreach ($boxes as $box) {
            if (! is_array($box)) {
                continue;
            }

            $kind = $box['kind'] ?? null;
            $id   = $box['id'] ?? null;

            if (! is_string($kind) || ! in_array($kind, self::KINDS, strict: true)) {
                continue;
            }

            // Bounded the same way validate() bounds it, because a stored blob
            // never went through validate(). Str::isUuid rather than a regex of
            // this file's own, because it is exactly what Laravel's `uuid` rule
            // calls — so the way in and the way out cannot come to disagree
            // about what an id is, which a second hand-written pattern is how
            // they would.
            //
            // Worth bounding at all because a canvas key is "kind:id" and the
            // client looks boxes up by it: an id that is not an id can never
            // match anything on screen, so it would sit in the column forever,
            // counting against the cap and naming nothing.
            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }

            $x = self::coordinate($box['x'] ?? null);
            $y = self::coordinate($box['y'] ?? null);

            if ($x === null || $y === null) {
                continue;
            }

            $kept["{$kind}:{$id}"] = ['kind' => $kind, 'id' => $id, 'x' => $x, 'y' => $y];
        }

        return array_slice(array_values($kept), 0, self::MAX_BOXES);
    }

    /**
     * One coordinate, as an integer the canvas can actually put a box at.
     *
     * CLAMPED rather than refused, which is the decision worth defending. A
     * coordinate is not a value anybody chose: it is where a finger or a mouse
     * happened to end up, or what an automatic layout computed. Refusing a save
     * because a number was out of range would dead-end somebody over something
     * they cannot see and did not decide, which is exactly what asDeclared
     * refuses to do with an option value — and here the clamped answer comes
     * back in the response, so the box appears where it will actually be drawn
     * rather than where it was asked to go.
     *
     * Rounded rather than kept as a float. Sub-pixel precision buys nothing on
     * screen and costs bytes in a column read on every /me.
     *
     * Infinity clamps like any other out-of-range number, because min() and
     * max() do the right thing with it. NaN is the one value that cannot be
     * clamped — comparisons against it are false in both directions, so a bound
     * is not a bound — and it is answered with null, which drops the entry.
     * Worth saying that neither can arrive through save(): JSON has no NaN
     * literal, and `numeric` rejects the string. They are here for the stored
     * blob, which no validator ever saw.
     */
    private static function coordinate(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        if (is_nan($number)) {
            return null;
        }

        return (int) max(0, min(self::MAX_COORDINATE, round($number)));
    }
}
