<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the boxes sit on the Organization tab's structure canvas — for
 * everybody in the organization (§2.1 category 5).
 *
 * The Organization tab draws the business — organization, companies, sub
 * companies, franchises — as boxes somebody drags into an arrangement. This
 * endpoint stores that arrangement, and nothing else. It is a picture of the
 * structure, never the structure: see "What this is NOT" below, which is the
 * whole reason the file exists rather than a method somewhere.
 *
 * ── Why a controller of its own ──────────────────────────────────
 *
 * CompaniesController was the obvious home and is the wrong one twice over.
 * Every method in that class calls $this->authorize() against a Company policy,
 * writes a business record and emits an automation event; this method does none
 * of those three. It would be the only one in a class whose docblock opens
 * "Every rule in §3 is enforced here". Worse, that file is where §3's
 * write-once-parent rule lives — `'parent_company_id' => ['missing']` in
 * update() — and a second method there accepting `{id, x, y}` for companies is
 * exactly the thing a future reader mistakes for the move endpoint §3 forbids.
 *
 * (That sentence used to say "none of the four", the fourth being an audit
 * entry. It is four minus one now — this DOES audit, for the reason set out
 * below — and the clause was corrected rather than carried forward, because a
 * paragraph that lists a property the code no longer has is worse than no
 * paragraph at all.)
 *
 * DashboardController was the other candidate. Its ORDERABLE constant is a
 * fixed list of DASHBOARD SURFACES and saveLayout's `surface` field says which
 * dashboard is being saved. The Organization tab is not a dashboard, there are
 * no widgets, and the payload is coordinates rather than keys-and-options —
 * adding 'organization' to that list would leave `widgets.*.key` validating a
 * company id, which is a rule lying about what it checks.
 *
 * What DOES carry over from DashboardController is its defensive discipline,
 * and all of it still does: cleaned on the way in AND on the way out by one
 * function, coordinates clamped rather than refused, the count capped at both
 * ends, ids the caller cannot resolve kept rather than swept up, and the
 * response echoing what was STORED rather than what was sent.
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
 * ── One picture, held in the settings system ─────────────────────
 *
 * This used to be one person's preference, written straight into
 * `users.preferences['organization.canvas']` (§2.1 category 6). The owner's
 * answer to "should this be shared" was "should be shared obviously", so it is
 * a tenant setting now: ONE arrangement per organization that everybody in that
 * organization sees.
 *
 * It lives in the settings system rather than in a store of its own because
 * that system holds it with no strain. Its unique (scope_type, scope_id, key)
 * IS the "exactly one row per organization" invariant this value needs, and the
 * `json` column has about two thousand times the headroom the cap allows. The
 * definition is declared in SettingRegistry, organization-scope only, and
 * carries `editedOn` so the Settings screen does not draw a box of raw
 * coordinates for a picture that has its own screen.
 *
 * WRITTEN HERE rather than through PATCH /settings/{key}, and that is a
 * deliberate choice with one reason: that route audits the entire before-blob
 * and the entire after-blob unconditionally. See the audit paragraph in save().
 *
 * READ on GET /companies, beside the tree it describes. Not on /me, which is
 * answered once at launch and cached in the client's session — a colleague's
 * arrangement would not have appeared until the next sign-in.
 *
 * ── Gated, because a save now changes what everybody sees ────────
 *
 * §4: `organizations.edit`, plus an assignment at the whole organization. Both
 * halves are asked from one method, {@see self::refusalToArrange()}, which is
 * also what CompaniesController::index publishes on the tree as
 * `may_arrange_reason` — so the live control and the 403 behind it cannot come
 * to disagree, because they are the same string.
 *
 * Somebody WITHOUT it still sees the arrangement, still pans, still zooms,
 * still re-centres. What is refused is the SAVE.
 *
 * ── Automations: nothing new, and the reason has CHANGED ─────────
 *
 * The old argument here does not survive this change and is not repeated. It
 * said a `canvas_arranged` trigger would have to live under a subject nothing
 * could write to, and that AutomationEngine::scopeOf would answer [null, null]
 * for a preferences blob that is not a model. Both were true of a row in
 * `users`. Neither is true now: this writes an organization-scoped setting, the
 * `organizations` module already declares Organization as its subject, and the
 * target handed to the audit logger is a real Organization.
 *
 * The honest reason a trigger is still not added is simpler, and it is that one
 * already exists and already covers this:
 *
 *     organization.settings_changed — "Somebody saved a different value for a
 *     setting, at whichever level they set it."  (isRaised: false)
 *
 * The arrangement IS a setting saved at organization level, so that sentence is
 * already true of it. Adding `organization.structure_arranged` beside it would
 * be a second, narrower trigger for a case the first one names, and it would be
 * dead in exactly the same way — settings are saved without going through the
 * outbox, which is still true of this write.
 *
 * It stays isRaised: false rather than being wired up here. Making it fire for
 * THIS setting alone would be the worst of both: a trigger a tenant builds a
 * rule on because the label says "Setting changed", which then fires for the
 * org chart and stays silent for the tax rate and the password policy. Either
 * every setting write goes through the outbox or none does, and that is a
 * decision about SettingsController, not about a canvas.
 *
 * No automation ACTION that rearranges the picture, either. The old reason — it
 * would write another user's preferences, which nothing may do — is also void,
 * and the new one is stronger: ActionRunner::WRITABLE holds Job and Customer
 * and nothing else, and an action here would be a rule firing on "a job was
 * completed" and silently moving everybody's furniture, with an audit row that
 * can name no person. Same class of refusal as `roles` and `automations`.
 *
 * The positive half is unchanged and is the reason none of this is a gap: every
 * fact this canvas draws already raises its own event. CompaniesController
 * emits company.created, company.updated and company.deactivated;
 * FranchisesController emits the three franchise equivalents. The drift that
 * "Arrange for me" exists to fix is already something a tenant can build a rule
 * on today.
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
     * The organization-wide setting this arrangement is stored as.
     *
     * Was `organization.canvas` in `users.preferences`. A public const so tests
     * and any future reader name it rather than spell it — the same reason
     * DashboardController::ORDER_PREFERENCE is public.
     *
     * The old key is not merely unused: it is stripped from every user by
     * {@see \App\Support\Structure\PromoteCanvasArrangements}, which runs once at
     * the migration that retires it. Nothing reads it and nothing may.
     */
    public const SETTING = 'organization.structure_canvas';

    /**
     * The kinds of box the canvas can hold.
     *
     * A closed list, deliberately, and not "whatever the client sends". This
     * lands in a JSON column that rides out on the tree payload every time
     * anybody opens the Organization screen; an open vocabulary would let a
     * client grow that blob with entries naming shapes that will never exist,
     * and nothing would ever clear them. It is one row for the whole
     * organization now, so the junk would be everybody's rather than one
     * person's.
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
     * ORDERABLE's reason again: this is a JSON column served on a payload the
     * Organization screen fetches every time it opens. 500 is chosen so it
     * reads as a defence rather than as a limit — 500 entries of roughly sixty
     * bytes is about 30KB, and it is far above any structure a person arranges
     * by hand.
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
     * whatever produced the number — and now on EVERY person who opens the tab,
     * because there is one arrangement rather than one each.
     *
     * Zero is the floor for the matching reason: the origin is the top-left of
     * the viewport's extent, so a negative coordinate is a box nobody can scroll
     * to. Both ends are CLAMPED rather than refused — see coordinate().
     */
    public const MAX_COORDINATE = 20000;

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Save an arrangement, for everybody.
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
     * a different box would lose an arrangement they never changed. It would now
     * lose it for the whole office rather than for one person, which makes the
     * case stronger and not weaker.
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
     * into a column the whole organization reads.
     *
     * `present` rather than `required` on the list itself, for saveLayout's
     * reason: an empty array is a legal save. "I put everything back" is a real
     * answer, and refusing it would leave somebody unable to undo an
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

        $boxes = self::clean($input['boxes']);

        $user         = $request->user();
        $organization = $user->organization;

        // Asked here and asked identically on the tree payload, from ONE
        // method, so the disabled canvas and the 403 behind it cannot come to
        // disagree — they are the same string. SettingsController::refusalFor
        // states the rule; this is the same rule for a write that does not go
        // through that controller.
        $refusal = self::refusalToArrange($user);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, $refusal);

        DB::transaction(function () use ($boxes, $organization) {
            $before = self::arrangementFor($this->settings, $organization);

            $this->settings->set(self::SETTING, $organization, $boxes);

            /*
             * ── Audited, and audited THIN ────────────────────────
             *
             * The old per-user save wrote nothing, and PreferencesController's
             * reason was right for what it was: §10 records what people do to
             * the BUSINESS, and where somebody parked a box in their own
             * browser was not that. It is that now. One person's drag changes
             * what every colleague opens tomorrow, so "who rearranged the
             * chart, and when" is a question somebody will actually ask — and
             * it is the only question about this that anybody will ever ask.
             *
             * Which is why the coordinates are NOT here. Routing this through
             * PATCH /settings/{key} would have written the entire before-blob
             * and the entire after-blob into `changes` on every debounced save
             * — up to sixty kilobytes of x and y, twice, for a diff no human
             * will ever read and no code will ever replay. That is exactly the
             * dilution DashboardController warns about, arriving from the other
             * direction: a log whose value is that everything in it matters
             * does not want a hundred rows of somebody tidying.
             *
             * One row per accepted save rather than per drag: the client
             * settles for 700ms first, so tidying a chart is a handful of rows.
             *
             * PreferencesController's and DashboardController::saveLayout's
             * no-audit paragraphs are both untouched by this. They are still
             * right about what they cover — values only their own author can
             * observe — and this is no longer one of those.
             */
            $this->audit->log(
                action:  'organization.structure_arranged',
                target:  $organization,
                changes: ['boxes_before' => count($before), 'boxes_after' => count($boxes)],
            );
        });

        // What was STORED, not what was sent. A clamped coordinate or a
        // deduplicated entry is then visible to the client rather than corrected
        // silently, and the client applies this answer rather than its own — the
        // echo is doing work rather than decorating the response.
        return ['boxes' => $boxes];
    }

    /**
     * Why this person may not rearrange the picture, or null when they may.
     *
     * ── Two questions, both required, and one string ─────────────
     *
     * The same pair SettingsController::refusalFor asks of every other
     * organization-scoped setting, because this IS one: does the role grant the
     * permission, and does the person stand at the whole organization?
     *
     * The second half is the one that would have been forgotten, and
     * SettingsController records what it cost last time — a plain role holding
     * only `organizations.edit`, assigned to a single franchise, was measured
     * lowering the tenant's password minimum from 12 to 4, 200 OK. The same
     * shape here is somebody who runs one branch rearranging the chart of the
     * whole business for everybody in it.
     *
     * `organizations.edit` rather than a permission of its own. It is already,
     * by construction, the key every organization-level write of configuration
     * uses — SettingDefinition::permissionRequired() returns it for all thirty
     * settings and no definition has ever overridden it. A bespoke
     * `organizations.arrange` would be the system's first override, spent on its
     * most cosmetic value, and it would be a permanent extra row in every
     * tenant's role editor that refuses nothing except box-dragging. The honest
     * cost, stated: to let an office manager tidy the chart you must also trust
     * them with the password policy. That is the trade, and it is the right way
     * round for a picture everybody sees.
     *
     * PUBLIC and static because CompaniesController::index publishes the same
     * answer on the tree as `may_arrange_reason`, and a second computation of it
     * is how a live control and a 403 come to say different things.
     */
    public static function refusalToArrange(User $user): ?string
    {
        // Named as the act, in the words somebody would use for it.
        $act = 'rearrange the structure everyone sees';

        if (! $user->hasPermission('organizations.edit')) {
            return Refusal::permission($act)->message();
        }

        // An assignment covering the organization itself — CompanyPolicy::create's
        // test, deliberately the same one. "Reaches every company that happens to
        // exist today" is a set that changes when somebody adds a branch, and the
        // organization is not.
        $reaches = $user->assignments->contains(
            fn ($a) => $a->assigned_organization_id === $user->organization_id
        );

        // Refused by POSITION rather than by place: there is no other
        // organization they could have arranged instead, so "somebody assigned
        // there can" would be nonsense.
        return $reaches ? null : Refusal::abovePosition($act)->message();
    }

    /**
     * The organization's stored arrangement, cleaned, and never a 500.
     *
     * ── Why this is not one expression at the call site ───────────
     *
     * Because `$resolver->get()` can THROW on this key, and the thing it throws
     * on is precisely the case the out-clean exists for. `value` is a JSON
     * column: it can have been hand-edited, restored from an older backup, or
     * written by a build before a rule existed. SettingDefinition::castJson
     * refuses anything that is not an array or valid JSON, so a column holding
     * `"nonsense"` does not arrive here as junk to be swept up — it arrives as
     * an exception, on GET /companies, which is the endpoint the whole
     * Organization screen is drawn from.
     *
     * So a wrong-shaped value reads as NO ARRANGEMENT rather than as an error,
     * which is the call the old per-user cleaner made for the same reason: a
     * screen that refused to open because of a stored value would be a screen
     * nobody could open and nobody could fix. Every box goes to its automatic
     * place, and one press of "Arrange for me" writes a good value over the bad
     * one.
     *
     * ── And why the guard is not simply wrapped round everything ──
     *
     * Because `get()` throws for two quite different reasons, and only one of
     * them is a tenant's bad data. The other is `getOrFail` refusing a key the
     * registry has never heard of, which would be a programming error — this
     * constant is this file's own — and swallowing it would turn a typo into
     * every organization silently losing its arrangement on every request, with
     * nothing anywhere saying so.
     *
     * So the registry is asked first, through sourceOf, which resolves the
     * definition and casts nothing. An unknown key throws there, outside the
     * guard, loudly. A null answer means nobody has set one, which is the
     * common case and needs no cast at all. Only the remaining path — there IS
     * a stored value — can throw for the reason this method is defending
     * against, and that is the only path inside the try.
     *
     * The resolver is passed in rather than pulled out of the container so this
     * says what it depends on. Both callers already hold one, and it memoises
     * the scope's overrides, so asking twice is one query.
     */
    public static function arrangementFor(SettingsResolver $settings, Organization $organization): array
    {
        if ($settings->sourceOf(self::SETTING, $organization) === null) {
            return [];
        }

        try {
            $stored = $settings->get(self::SETTING, $organization);
        } catch (InvalidArgumentException) {
            return [];
        }

        return self::clean(is_array($stored) ? $stored : []);
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
     * afternoon — and now the whole office's.
     *
     * ── And it is what makes a response engine-independent ────────
     *
     * MySQL does not preserve a JSON object's key order — it sorts keys by
     * length then lexicographically — so a box stored as {kind,id,x,y} comes
     * back from the column as {x,y,id,kind}. Every box this rebuilds is written
     * in one fixed order, so what a client is told never depends on which engine
     * the tenant is on. Nothing that reads this column may skip it.
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
     * PUBLIC, because the read side is now in another controller. It was
     * private when the only way out was a cleaner in this file; the read now
     * rides on the tree payload CompaniesController assembles, and a second
     * copy of these rules over there is exactly the drift this one function
     * exists to prevent.
     *
     * Note what this does NOT do: it does not check whether an id is one the
     * caller can see. That is save()'s decision and it holds identically here —
     * a position for a company that is away for the afternoon is an arrangement
     * to be preserved, not junk to be swept up.
     *
     * @param  array<mixed>  $boxes
     * @return list<array{kind: string, id: string, x: int, y: int}>
     */
    public static function clean(array $boxes): array
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
     * screen and costs bytes in a column every colleague reads.
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
