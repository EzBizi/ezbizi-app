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
 * ── An arrangement is boxes AND the direction they flow ──────────
 *
 * It used to be a bare list of boxes, and that was the whole of it. The owner
 * asked for the chart to run left to right — depth rightward, siblings stacked
 * downward — with "up to down" kept as a second view, so which way the picture
 * flows is now part of what is stored.
 *
 * It belongs HERE rather than in anybody's own settings for the same reason the
 * coordinates do: it is a fact about the picture everyone is looking at, not
 * about where one person happens to be looking. Two colleagues describing the
 * same chart over the phone need to be describing the same chart. The zoom level
 * is the opposite case and is deliberately not here — that is where somebody is
 * looking, it is never sent, and the client says so where it holds it.
 *
 * So the stored value grew from a list into `{orientation, boxes}`. A bare list
 * is still read — see clean(), which has to keep accepting one for as long as
 * any tenant has an arrangement written before today, including every row
 * PromoteCanvasArrangements wrote at the migration.
 *
 * ── And a place is no longer a box ───────────────────────────────
 *
 * A franchise has no children and never can (§3), so on the chart it stopped
 * being a box and became a row in a compact list attached to the company or sub
 * company that owns it. The real customer this was built for has thirteen places
 * under four companies, and thirteen boxes on one line is wider than any window.
 *
 * The consequence for this file is small and worth stating rather than
 * discovering: a stored `{kind: franchise, x, y}` names a coordinate for
 * something that no longer HAS one. It is dropped, in both directions, by the
 * one clean() both directions run through.
 *
 * `franchise` is still an accepted WORD in the payload, and that is the
 * difference between refusing and cleaning. A browser tab left open on the build
 * that shipped this morning still sends place coordinates; a 422 would dead-end
 * every save it made, over entries this endpoint is perfectly able to ignore.
 * See KINDS and POSITIONED_KINDS, which are two different questions.
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
     * The kinds a saved entry may NAME.
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
     *
     * `franchise` is still in it although a place no longer has a position —
     * see POSITIONED_KINDS, which is the question this one is not.
     */
    public const KINDS = ['organization', 'company', 'franchise'];

    /**
     * The kinds that actually HOLD a position, which is now fewer than the
     * kinds a payload may name.
     *
     * A franchise has no children and never can, so the chart stopped drawing
     * one as a box: a place is a row in a list attached to the company or sub
     * company above it, and a row in a list has no coordinate of its own. An
     * entry saying where a place sits therefore describes something that does
     * not exist, and every one of them counts against MAX_BOXES and rides out on
     * the tree payload forever.
     *
     * ── Why this is not simply KINDS with `franchise` taken out ───
     *
     * Because the two lists answer two questions, and merging them would turn a
     * cleaning into a refusal. Rule::in(KINDS) is what the REQUEST is validated
     * against; a client that still sends place coordinates — a browser tab open
     * since this morning, a save queued behind a slow connection — would then
     * get a 422 for its whole arrangement, including the company positions that
     * are perfectly good, and nothing it could do about it. Cleaning drops the
     * three useless entries, stores the rest, and echoes back what was stored,
     * so that client's next read simply no longer has them. That is this file's
     * standing rule ("clean, don't refuse") applied to a vocabulary change
     * instead of to a coordinate.
     *
     * Fixed by §3 like KINDS is, and for the same reason: it moves when the
     * model does, never when a customer decides something.
     */
    public const POSITIONED_KINDS = ['organization', 'company'];

    /**
     * Which way the chart flows, as a closed list.
     *
     * `left_to_right` is depth rightward — organization, then companies, then
     * sub companies — with siblings stacked downward. `top_to_bottom` is the
     * same chart turned ninety degrees: depth downward, siblings side by side,
     * which is what shipped first and what the owner asked to keep.
     *
     * Closed, and validated as such, for KINDS' reason exactly: it is a word
     * that lands in a shared JSON column and comes back out on every tree
     * payload. An open one would let a client store `diagonal` in a value every
     * colleague reads, which nothing would ever draw and nothing would ever
     * clear.
     *
     * Two, and NOT a tenant setting. This is a view of one picture rather than a
     * preference about how the product behaves — somebody flips it, looks, and
     * flips back — so it lives in the arrangement it describes rather than
     * becoming a thirty-first row in the settings screen.
     */
    public const ORIENTATIONS = ['left_to_right', 'top_to_bottom'];

    /**
     * What an arrangement flows as when nobody has said.
     *
     * The owner picked left to right from the mockups, and the reason is the
     * real data: one customer has nine places under a single company and four
     * more under three others. Down is the direction a screen scrolls, so
     * stacking siblings downward costs nothing, while thirteen of anything
     * across is wider than any window.
     *
     * It is also what every arrangement written before today reads as, which is
     * the honest answer for a blob that says nothing: the old layout ran top to
     * bottom, but the old layout also drew places as boxes, so no stored
     * arrangement can be carried forward unchanged anyway. The one thing that
     * would be worse is a default that varies with what happens to be stored.
     */
    public const DEFAULT_ORIENTATION = 'left_to_right';

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
     *
     * ── The orientation is `sometimes`, and that is not laziness ──
     *
     * An unknown orientation is REFUSED — it is the one field here that cannot
     * be cleaned into something sensible, because every possible reading of
     * `diagonal` is a guess about what somebody meant, and the value came from a
     * two-item control rather than from a finger on a trackpad.
     *
     * An ABSENT one is a different thing entirely and must not be read as the
     * default. A client that predates this field — the build that shipped this
     * morning, still open in somebody's tab — sends boxes and nothing else, and
     * defaulting would have that save quietly turn everybody's chart back to
     * left to right the moment one box moved. So an absent orientation means
     * "keep the one that is stored", which is the only reading that cannot
     * change something nobody touched.
     */
    public function save(Request $request): array
    {
        $input = $request->validate([
            'orientation'  => ['sometimes', 'string', Rule::in(self::ORIENTATIONS)],
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
            // Says what the two answers ARE. "The selected orientation is
            // invalid" tells a developer that something was refused without
            // telling them what would be accepted, and this is a refusal no
            // customer can act on — so the sentence has to be for whoever is
            // reading the response.
            'orientation.in' => 'A structure chart flows left to right or top to bottom, and '
                .'nothing else.',
        ]);

        $user         = $request->user();
        $organization = $user->organization;

        // Asked here and asked identically on the tree payload, from ONE
        // method, so the disabled canvas and the 403 behind it cannot come to
        // disagree — they are the same string. SettingsController::refusalFor
        // states the rule; this is the same rule for a write that does not go
        // through that controller.
        $refusal = self::refusalToArrange($user);

        abort_if($refusal !== null, Response::HTTP_FORBIDDEN, $refusal);

        return DB::transaction(function () use ($input, $organization) {
            $before = self::arrangementFor($this->settings, $organization);

            // Inside the transaction because the orientation this save keeps
            // when it names none is the one that is stored RIGHT NOW, and a read
            // outside would be a read of what was stored a moment ago.
            $arrangement = self::clean([
                'orientation' => $input['orientation'] ?? $before['orientation'],
                'boxes'       => $input['boxes'],
            ]);

            $this->settings->set(self::SETTING, $organization, $arrangement);

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
                changes: [
                    'boxes_before' => count($before['boxes']),
                    'boxes_after'  => count($arrangement['boxes']),
                    // Two short words rather than sixty kilobytes of x and y,
                    // and they earn their place: turning the whole chart round
                    // is the one thing anybody does here that a colleague
                    // notices immediately and cannot undo by dragging. Without
                    // them the row for that save reads "3 boxes before, 3 boxes
                    // after", which is an audit entry saying nothing happened
                    // about the most visible change this endpoint can make.
                    'orientation_before' => $before['orientation'],
                    'orientation_after'  => $arrangement['orientation'],
                ],
            );

            // What was STORED, not what was sent. A clamped coordinate, a
            // deduplicated entry or a dropped place is then visible to the
            // client rather than corrected silently, and the client applies this
            // answer rather than its own — the echo is doing work rather than
            // decorating the response.
            return $arrangement;
        });
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
        // `canActOnOrganization` rather than the hand-written test that stood
        // here, for ONE RULE rather than two copies of it - not because the old
        // one let anybody through.
        //
        // Checked before changing it, because I first reported this as a hole
        // and it is not. The nearest reason is ten lines up in this same
        // function: `hasPermission` runs first and its first line is
        // `if (! $this->is_active) return false` (User:229). So a deactivated
        // user is refused BEFORE reaching here, by a method that is not a
        // `canAct*` one - which is the item I read past when I called this a
        // hole. Settings proved it by mutation rather than by reading: strip
        // hasPermission's clause and the endpoint still 403s; strip the
        // method's too and it 200s, which is the control.
        //
        // Further out, a deactivated user cannot log in at all
        // (AuthenticateUser:93, :209) and deactivating through HR deletes
        // their tokens (HrPeopleController:386). The scope half is unreachable
        // too: a CHECK constraint, `assignments_exactly_one_scope`, means an
        // assignment carrying an organization id IS organization-scoped.
        //
        // So the hand-written test was protected by the line in FRONT of it,
        // not by anything it did itself, and it stops being protected the
        // moment somebody reorders those two lines or calls this from a path
        // with no permission gate.
        //
        // So this is consistency, and the residual is narrow: somebody
        // deactivated by a direct database write, outside the HR flow, keeps
        // their tokens. The method covers that; a hand-written copy would not.
        $reaches = $user->canActOnOrganization($user->organization);

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
     *
     * ALWAYS the full shape — `{orientation, boxes}` — including for an
     * organization nobody has arranged. The alternative was answering `null` or
     * an empty list for "never arranged" and the map otherwise, which is two
     * shapes for one thing and would put the branch in every reader instead of
     * here. "Nobody has arranged it" and "the arrangement is empty" mean the
     * same thing to the only reader there is: every box goes to its automatic
     * place, flowing whichever way DEFAULT_ORIENTATION says.
     *
     * @return array{orientation: string, boxes: list<array{kind: string, id: string, x: int, y: int}>}
     */
    public static function arrangementFor(SettingsResolver $settings, Organization $organization): array
    {
        if ($settings->sourceOf(self::SETTING, $organization) === null) {
            return self::clean([]);
        }

        try {
            $stored = $settings->get(self::SETTING, $organization);
        } catch (InvalidArgumentException) {
            return self::clean([]);
        }

        return self::clean(is_array($stored) ? $stored : []);
    }

    /**
     * An arrangement reduced to what this canvas can actually draw.
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
     * ── A PLACE HAS NO POSITION, in both directions, from here ────
     *
     * A franchise is drawn as a row in a list attached to its company now, not
     * as a box, so `{kind: franchise, x, y}` is a coordinate for something with
     * nowhere to put one. Every such entry counts against MAX_BOXES and rides
     * out on the tree payload to every colleague, forever, describing nothing.
     *
     * Dropping them HERE is what makes that one change rather than two. The way
     * in is a client that has not been updated yet, or a save that was already
     * queued; the way out is every arrangement written before today, and there
     * are real ones — this feature shipped this morning. Both go through this
     * function, so neither can be the direction somebody forgot. A filter
     * written next to the storage call instead would have left the stored blobs
     * to be cleaned by whoever noticed them next.
     *
     * The alternative was refusing a place coordinate on the way in, and it is
     * worse: see POSITIONED_KINDS, which is where the choice between cleaning
     * and refusing is argued out.
     *
     * ── And a bare LIST is still an arrangement ───────────────────
     *
     * The stored value is `{orientation, boxes}` now and used to be the boxes
     * alone. A list is therefore read as "these boxes, flowing the default way",
     * and that is not a courtesy: every arrangement in every tenant is one today
     * — including every row PromoteCanvasArrangements wrote at the migration,
     * which reimplements this reduction as it stood and writes a bare list on
     * purpose. Refusing the old shape would mean an arrangement that survived
     * being promoted out of one person's preferences and then vanished at the
     * next deploy.
     *
     * ── And it is what makes a response engine-independent ────────
     *
     * MySQL does not preserve a JSON object's key order — it sorts keys by
     * length then lexicographically — so a box stored as {kind,id,x,y} comes
     * back from the column as {x,y,id,kind}. Every box this rebuilds is written
     * in one fixed order, so what a client is told never depends on which engine
     * the tenant is on. Nothing that reads this column may skip it.
     *
     * That now covers the arrangement AROUND the boxes as well: `{orientation,
     * boxes}` is rebuilt in that order however the engine happened to hold it,
     * so the outer object is as engine-independent as the inner ones. It is
     * read by key rather than by position on the way in, which is what makes
     * that true rather than lucky.
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
     * @param  array<mixed>  $arrangement
     * @return array{orientation: string, boxes: list<array{kind: string, id: string, x: int, y: int}>}
     */
    public static function clean(array $arrangement): array
    {
        // A list is the old shape and is read as the boxes themselves. An empty
        // array is a list too, which is the right answer for it: no boxes, and
        // the default orientation.
        $boxes = array_is_list($arrangement) ? $arrangement : ($arrangement['boxes'] ?? null);

        if (! is_array($boxes)) {
            $boxes = [];
        }

        $orientation = $arrangement['orientation'] ?? null;

        // Cleaned rather than refused, which is the out-clean's whole argument
        // applied to a word instead of to a number: an unreadable orientation in
        // a stored blob must draw the chart the default way, not fail to draw
        // it. validate() is what refuses an unknown one on the way in, where
        // there is somebody to tell.
        if (! is_string($orientation) || ! in_array($orientation, self::ORIENTATIONS, strict: true)) {
            $orientation = self::DEFAULT_ORIENTATION;
        }

        $kept = [];

        foreach ($boxes as $box) {
            if (! is_array($box)) {
                continue;
            }

            $kind = $box['kind'] ?? null;
            $id   = $box['id'] ?? null;

            // POSITIONED_KINDS rather than KINDS, which is what drops a place's
            // coordinate in both directions. The two lists are two questions —
            // what a payload may say, and what may hold a position — and this is
            // the second one.
            if (! is_string($kind) || ! in_array($kind, self::POSITIONED_KINDS, strict: true)) {
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

        return [
            'orientation' => $orientation,
            'boxes'       => array_slice(array_values($kept), 0, self::MAX_BOXES),
        ];
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
