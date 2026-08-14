<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\OrganizationCanvasController as Canvas;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Organization tab's structure canvas — where somebody put the boxes.
 *
 * Two kinds of test, and the second kind is the point of the file.
 *
 * The first kind is the ordinary discipline of a preference write: it lands
 * under its own key, it does not take the theme with it, it is one person's and
 * not the organization's, and a blob stored by an older build cannot make the
 * endpoint fall over.
 *
 * The second kind is about what this feature deliberately is NOT. §3 says
 * entities are never moved between parents, and this canvas is a picture of
 * that structure that a person drags around. So there are tests here asserting
 * that a stored position grants nothing, that no audit entry is written and
 * that no automation event is raised — three claims the controller's docblock
 * makes at length, which would otherwise be prose nobody can check. Each of
 * those three fails the day somebody makes the canvas mean more than it does.
 */
class OrganizationCanvasTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry
        // rather than by provisioning an organization, and GET /companies is
        // authorized against the Company policy. Without this the tree endpoint
        // one of these tests leans on would refuse for a reason that has
        // nothing to do with what is being tested.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        SubscriptionGuard::flush();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function as(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Somebody's stored preferences.
     *
     * Read under the tenant, because User is tenant-scoped and reading one
     * outside a context is refused on purpose — a test that reached for it
     * directly would be exercising a path no request takes.
     *
     * @return array<string, mixed>
     */
    private function preferencesOf(User $user): array
    {
        $stored = $this->inTenant(fn () => User::find($user->id)->preferences ?? []);

        if (is_array($stored[Canvas::PREFERENCE] ?? null)) {
            $stored[Canvas::PREFERENCE] = self::inAuthoredKeyOrder($stored[Canvas::PREFERENCE]);
        }

        return $stored;
    }

    /**
     * The stored boxes with each one's keys back in the order this file writes
     * them, so an assertion can be read as it was written.
     *
     * ── Why this is needed at all ─────────────────────────────────
     *
     * MySQL does not store a JSON object's keys in the order they arrived. It
     * sorts them by length and then lexicographically, so `{kind, id, x, y}`
     * comes back as `{x, y, id, kind}` — while sqlite keeps insertion order.
     * `assertSame` compares key order, so without this every assertion below
     * passes on one engine and fails on the other with nothing about the stored
     * data different. That is not a difference any of these tests mean to make.
     *
     * It matters here and nowhere else because these assertions read the raw
     * column. Everything the CLIENT sees goes through
     * OrganizationCanvasController::normalised(), which rebuilds each box in a
     * fixed order, so a response is deterministic whatever the engine did.
     *
     * ── Why a template rather than ksort ──────────────────────────
     *
     * Because the ordering must not become a way to hide things. array_replace
     * fills from a four-key template, so a key that should not be stored at all
     * is still present — appended after the four — and the test that exists to
     * catch a stray key still catches it. A missing key arrives as null rather
     * than vanishing. Sorting both sides instead would have been shorter and
     * would have quietly accepted a box with a fifth key in it.
     *
     * @param  list<mixed>  $boxes
     * @return list<mixed>
     */
    private static function inAuthoredKeyOrder(array $boxes): array
    {
        return array_map(
            fn (mixed $box) => is_array($box)
                ? array_replace(['kind' => null, 'id' => null, 'x' => null, 'y' => null], $box)
                : $box,
            $boxes,
        );
    }

    /** Put a blob into somebody's preferences the way an older build might have. */
    private function storeRaw(User $user, mixed $value): void
    {
        $this->inTenant(function () use ($user, $value) {
            $found = User::find($user->id);

            $found->forceFill([
                'preferences' => array_merge($found->preferences ?? [], [Canvas::PREFERENCE => $value]),
            ])->save();
        });
    }

    /**
     * The arrangement as the CLIENT is told it, on /me.
     *
     * Indexed by the literal key rather than read with `json('...')`, because
     * that helper splits on dots and this preference has one in its name —
     * `data.preferences.organization.canvas` asks for a `canvas` inside an
     * `organization` and quietly answers null, which would make every assertion
     * below pass against a server that sent nothing at all.
     *
     * @return array<mixed>|null
     */
    private function asToldTo(User $user): ?array
    {
        $preferences = $this->as($user)->getJson('/api/v1/me')->assertOk()->json('data.preferences');

        return $preferences[Canvas::PREFERENCE] ?? null;
    }

    /** A second person in the same organization, with their own preferences. */
    private function secondPerson(): User
    {
        return $this->inTenant(fn () => User::create([
            'organization_id' => $this->alpha['org']->id,
            'role_id'         => $this->alpha['admin']->role_id,
            'name' => 'Other', 'email' => 'other@alpha.test',
            'password' => 'a-real-password', 'is_active' => true,
        ]));
    }

    private function lapse(): void
    {
        $this->alpha['org']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->alpha['org']->refresh();

        SubscriptionGuard::flush();
    }

    // ── it is stored, and it is theirs ───────────────────────────

    public function test_an_arrangement_is_stored_under_its_own_key(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [
                ['kind' => 'organization', 'id' => $this->alpha['org']->id,       'x' => 0,   'y' => 0],
                ['kind' => 'company',      'id' => $this->alpha['company']->id,   'x' => 232, 'y' => 156],
                ['kind' => 'franchise',    'id' => $this->alpha['franchise']->id, 'x' => 464, 'y' => 312],
            ],
        ])->assertOk();

        // The STORED blob, not only the response. An endpoint that answers 200
        // and writes nothing looks identical from the client's side, and the
        // client would carry the arrangement in memory until the tab closed.
        $this->assertSame(
            [
                ['kind' => 'organization', 'id' => $this->alpha['org']->id,       'x' => 0,   'y' => 0],
                ['kind' => 'company',      'id' => $this->alpha['company']->id,   'x' => 232, 'y' => 156],
                ['kind' => 'franchise',    'id' => $this->alpha['franchise']->id, 'x' => 464, 'y' => 312],
            ],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * §2.1 category 6. Not a tenant setting and not an organization setting:
     * nobody arranges somebody else's picture, and two people sharing a role
     * must be able to disagree about where a box sits.
     */
    public function test_one_persons_arrangement_is_not_another_persons(): void
    {
        $other = $this->secondPerson();

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 10, 'y' => 20]],
        ])->assertOk();

        // The control assertion, first: the second person has no arrangement at
        // all rather than an identical one. Without this the test below passes
        // against an implementation that wrote to a shared row, because both
        // people would read back the same thing.
        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $this->preferencesOf($other));

        $this->as($other)->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 900, 'y' => 900]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 10, 'y' => 20]],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * `users.preferences` is one blob shared with everything else somebody has
     * chosen. An arrangement that overwrote it would take their theme with it —
     * and it would be found days later by somebody who never connected the two.
     */
    public function test_saving_an_arrangement_leaves_every_other_preference_alone(): void
    {
        $this->as($this->alpha['admin'])->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 2]],
        ])->assertOk();

        $this->assertSame(
            'dark',
            $this->preferencesOf($this->alpha['admin'])['appearance.theme'],
        );
    }

    // ── ids this server cannot resolve ───────────────────────────

    /**
     * The tempting `Rule::exists`, and the arrangement it would lose.
     *
     * A company can be missing for the afternoon — somebody's role is being
     * edited, a module is switched off and back on, a scope is narrowed — and
     * forgetting where it sat because it happened to be absent when they nudged
     * a different box loses an arrangement they never changed.
     */
    public function test_a_position_for_a_company_that_is_gone_is_kept(): void
    {
        $gone = (string) Str::uuid();

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [
                ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 10, 'y' => 20],
                ['kind' => 'company', 'id' => $gone,                       'x' => 30, 'y' => 40],
            ],
        ])->assertOk();

        $this->assertSame(
            [
                ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 10, 'y' => 20],
                ['kind' => 'company', 'id' => $gone,                       'x' => 30, 'y' => 40],
            ],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * The other half of keeping an id nobody here can resolve: it must grant
     * nothing.
     *
     * The controller's defence for storing an unresolvable id is that nothing
     * downstream ever resolves one — the tree comes from CompaniesController
     * under the Company policy, and this list only says where to draw whatever
     * survives that. This asserts it rather than trusting the paragraph: a
     * position naming another organization's company is stored, and the tree
     * endpoint is asked immediately afterwards and still answers with only this
     * organization's own structure.
     */
    public function test_a_position_for_another_organizations_company_is_kept_and_grants_nothing(): void
    {
        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $beta['company']->id, 'x' => 5, 'y' => 5]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $beta['company']->id, 'x' => 5, 'y' => 5]],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );

        $tree = $this->as($this->alpha['admin'])->getJson('/api/v1/companies')->assertOk()->json();

        // The blob is inert. If this ever fails, somebody has made the canvas a
        // source of the tree, and a stored id has become a way to name a record
        // in an organization the caller cannot see.
        $this->assertSame(
            [$this->alpha['company']->id],
            array_column($tree['companies'], 'id'),
        );
        $this->assertSame($this->alpha['org']->id, $tree['organization']['id']);
    }

    // ── clean rather than refuse, and the one exception ──────────

    /**
     * A coordinate is not a value anybody chose — it is where a finger ended up,
     * or what an automatic layout computed. Refusing a save over one would
     * dead-end somebody about something invisible.
     */
    public function test_a_coordinate_outside_the_canvas_is_clamped_rather_than_refused(): void
    {
        $response = $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => -50, 'y' => 999999]],
        ])->assertOk();

        $clamped = [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => Canvas::MAX_COORDINATE]];

        $this->assertSame($clamped, $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE]);

        // And the RESPONSE carries the clamped values, not the ones that were
        // sent. Otherwise the correction happens behind the client's back and
        // the box is drawn where it will not be next time.
        $this->assertSame($clamped, $response->json('boxes'));
    }

    /** A drag produces doubles, so the server rounds rather than refusing halves. */
    public function test_a_fractional_coordinate_is_rounded_rather_than_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 12.4, 'y' => 12.6]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 12, 'y' => 13]],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * Clamping is for numbers out of range. A value that is not a number at all
     * is a client sending the wrong thing, and there is no reading of it that
     * places a box anywhere.
     */
    public function test_a_coordinate_that_is_not_a_number_is_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 'over there', 'y' => 0]],
        ])->assertStatus(422);

        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $this->preferencesOf($this->alpha['admin']));
    }

    public function test_a_box_named_twice_is_stored_once(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [
                ['kind' => 'company',   'id' => $this->alpha['company']->id, 'x' => 1,  'y' => 1],
                ['kind' => 'company',   'id' => $this->alpha['company']->id, 'x' => 99, 'y' => 99],
                ['kind' => 'franchise', 'id' => $this->alpha['franchise']->id, 'x' => 7, 'y' => 7],
            ],
        ])->assertOk();

        // Once, and the LAST one wins — which is what two racing saves would
        // have meant anyway. A blob that kept both would draw one box in two
        // places.
        $this->assertSame(
            [
                ['kind' => 'company',   'id' => $this->alpha['company']->id,   'x' => 99, 'y' => 99],
                ['kind' => 'franchise', 'id' => $this->alpha['franchise']->id, 'x' => 7,  'y' => 7],
            ],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * The same id under two kinds is two boxes, not one.
     *
     * `kind` is what makes the blob readable by a person who opens the column,
     * and it is half the key. Dropping it from the key would be a step towards
     * dropping it from the payload — at which point a stale company id and a
     * stale franchise id become indistinguishable.
     */
    public function test_the_kind_is_part_of_what_names_a_box(): void
    {
        $shared = (string) Str::uuid();

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [
                ['kind' => 'company',   'id' => $shared, 'x' => 1, 'y' => 1],
                ['kind' => 'franchise', 'id' => $shared, 'x' => 2, 'y' => 2],
            ],
        ])->assertOk();

        $this->assertCount(2, $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE]);
    }

    /**
     * §3 fixes the depth at Organization → Company → Sub Company → Franchise,
     * and a sub company is a company row with a parent. An open vocabulary here
     * would let a client grow a column read on every /me with shapes that will
     * never exist and nothing to clear them.
     */
    public function test_an_unknown_kind_is_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'sub_company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => 0]],
        ])->assertStatus(422);

        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $this->preferencesOf($this->alpha['admin']));
    }

    /**
     * The one place this endpoint refuses where it otherwise cleans, so the
     * sentence has to carry its own explanation.
     *
     * Truncating would mean an arrangement that never sticks, forever, with
     * nothing on screen saying why — unlike a clamped coordinate, which is
     * invisible and harmless.
     */
    public function test_more_boxes_than_the_cap_are_refused_with_a_sentence(): void
    {
        $boxes = [];

        for ($i = 0; $i <= Canvas::MAX_BOXES; $i++) {
            $boxes[] = ['kind' => 'company', 'id' => (string) Str::uuid(), 'x' => $i, 'y' => $i];
        }

        $message = $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => $boxes])
            ->assertStatus(422)
            ->json('errors.boxes.0');

        $this->assertStringContainsString((string) Canvas::MAX_BOXES, $message);
        $this->assertStringContainsString('outline', $message);

        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $this->preferencesOf($this->alpha['admin']));
    }

    /**
     * "I put everything back" is a real answer, and `required` would make it
     * unsayable — leaving somebody unable to undo their own arrangement.
     */
    public function test_an_empty_arrangement_is_a_legal_save(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertOk();

        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => []])
            ->assertOk();

        $this->assertSame([], $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE]);
    }

    // ── a blob written before any of this existed ────────────────

    /**
     * A stored preference is user input somebody saved earlier, and this one is
     * merged INTO the surrounding blob rather than replacing it. A value of the
     * wrong shape — written by an older build, or by hand — must not become a
     * 500 on a save nobody could get past.
     */
    public function test_an_arrangement_stored_in_the_wrong_shape_is_replaced_rather_than_merged_into(): void
    {
        $this->storeRaw($this->alpha['admin'], 'nonsense');

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 3, 'y' => 4]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 3, 'y' => 4]],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );
    }

    /**
     * Cleaned on the way OUT as well as on the way in.
     *
     * DashboardController::index re-runs its check on a layout its own save
     * already checked, because a layout stored before that check existed is
     * still user input nobody has looked at. /me is the only place a canvas
     * leaves this server, so it is the only place the out-clean can be — and
     * without it a client asks a transform to place a box at "nonsense".
     */
    public function test_an_arrangement_stored_in_the_wrong_shape_reaches_the_client_as_no_arrangement(): void
    {
        $this->storeRaw($this->alpha['admin'], 'nonsense');

        $this->assertSame([], $this->asToldTo($this->alpha['admin']));
    }

    /**
     * One unusable entry is a box in the wrong place. Bailing on the whole blob
     * is somebody's afternoon.
     */
    public function test_a_stored_entry_that_is_missing_a_coordinate_does_not_lose_the_others(): void
    {
        $this->storeRaw($this->alpha['admin'], [
            ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 11, 'y' => 22],
            ['kind' => 'company', 'id' => (string) Str::uuid(), 'x' => 30],
            'not even a map',
            ['kind' => 'somewhere', 'id' => (string) Str::uuid(), 'x' => 1, 'y' => 1],
            ['kind' => 'company', 'id' => 'a whole sentence where an id should be', 'x' => 1, 'y' => 1],
            ['kind' => 'franchise', 'id' => $this->alpha['franchise']->id, 'x' => 33, 'y' => 44],
        ]);

        $this->assertSame(
            [
                ['kind' => 'company',   'id' => $this->alpha['company']->id,   'x' => 11, 'y' => 22],
                ['kind' => 'franchise', 'id' => $this->alpha['franchise']->id, 'x' => 33, 'y' => 44],
            ],
            $this->asToldTo($this->alpha['admin']),
        );
    }

    /**
     * The bound applies to what was already in the column, not only to what
     * arrives. A stored 1e300 handed to a transform throws, and the throw lands
     * on whoever opened the tab rather than on whatever produced the number.
     */
    public function test_a_stored_coordinate_outside_the_canvas_is_clamped_on_the_way_out(): void
    {
        $this->storeRaw($this->alpha['admin'], [
            ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => -4000, 'y' => 1.0e300],
        ]);

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => Canvas::MAX_COORDINATE]],
            $this->asToldTo($this->alpha['admin']),
        );
    }

    /**
     * The same clamp, through the endpoint literally named for preferences.
     *
     * /me is where the CLIENT reads this arrangement, which is what made it look
     * like the only way out of the column — and for a while the out-clean was
     * called from there alone. It is not the only way out: PreferencesController
     * serves the whole blob to GET /me/preferences and to the 200 of a PATCH,
     * merging every stored key through untouched, including keys the registry
     * has never heard of. `organization.canvas` is deliberately one of those.
     *
     * So the same stored 1e300 came back clamped on one endpoint and raw on the
     * other. No shipped client is broken by that today, which is exactly the
     * argument the cleaner was written against: it must not sit on the far side
     * of the wire, and the desktop and mobile clients the house rules require
     * would reach for the endpoint named for preferences first.
     */
    public function test_a_stored_coordinate_is_clamped_on_the_preferences_endpoint_too(): void
    {
        $this->storeRaw($this->alpha['admin'], [
            ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => -4000, 'y' => 1.0e300],
        ]);

        $clamped = [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => Canvas::MAX_COORDINATE]];

        // Read out of the whole map rather than with json('...'), which splits
        // on dots and would ask for a `canvas` inside an `organization`.
        $read = $this->as($this->alpha['admin'])->getJson('/api/v1/me/preferences')
            ->assertOk()->json('data');

        $this->assertSame($clamped, $read[Canvas::PREFERENCE]);

        // And in the answer to a PATCH, which is the same body. Patching some
        // OTHER preference is what a client actually does — the canvas cannot be
        // written here at all, because the registry has no definition to cast it
        // with and refuses — and the untouched canvas rides back out with it.
        $patched = $this->as($this->alpha['admin'])->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk()->json('data');

        $this->assertSame($clamped, $patched[Canvas::PREFERENCE]);
    }

    /**
     * And a blob of the wrong shape entirely reads as no arrangement there too,
     * rather than reaching a client that will hand it to a transform.
     */
    public function test_an_arrangement_stored_in_the_wrong_shape_is_cleaned_on_the_preferences_endpoint(): void
    {
        $this->storeRaw($this->alpha['admin'], 'nonsense');

        $read = $this->as($this->alpha['admin'])->getJson('/api/v1/me/preferences')
            ->assertOk()->json('data');

        $this->assertSame([], $read[Canvas::PREFERENCE]);
    }

    /**
     * "Never arranged anything" and "arranged nothing" are different sentences,
     * and the client draws them the same way only by coincidence. Inventing an
     * empty list for somebody who has never opened the tab would be telling the
     * client about a choice nobody made.
     */
    public function test_a_canvas_nobody_has_arranged_is_absent_rather_than_empty(): void
    {
        $preferences = $this->as($this->alpha['admin'])->getJson('/api/v1/me')
            ->assertOk()->json('data.preferences');

        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $preferences);

        // The control: the key does appear once there is something to say, so
        // the assertion above is about absence and not about a payload that
        // never carries preferences at all.
        $this->assertArrayHasKey('appearance.theme', $preferences);
    }

    // ── what this endpoint deliberately does not do ──────────────

    /**
     * §10 records what people do to the BUSINESS. Where somebody parked a box is
     * not an action anyone will need to reconstruct, and writing it would dilute
     * a log whose whole value is that everything in it matters.
     *
     * Counted around the request rather than asserted absent, because signing in
     * writes audit entries of its own and this must not accidentally pass by
     * measuring those.
     */
    public function test_saving_an_arrangement_writes_no_audit_event(): void
    {
        $signedIn = $this->as($this->alpha['admin']);

        $before = AuditEvent::count();

        $signedIn->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertOk();

        $this->assertSame($before, AuditEvent::count());
    }

    /**
     * No `organization.canvas_arranged`, and the day somebody adds one this
     * fails.
     *
     * The reasons are in the controller's docblock at length — the key would
     * claim to be about an Organization while describing a row in `users`, the
     * engine would resolve no scope for it, and one person nudging a box in
     * their own browser would become tenant-wide queued work once per debounced
     * save. This is the part of that argument a test can hold.
     */
    public function test_saving_an_arrangement_raises_no_automation_event(): void
    {
        $signedIn = $this->as($this->alpha['admin']);

        $before = $this->inTenant(fn () => AutomationEvent::count());

        $signedIn->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertOk();

        $this->assertSame($before, $this->inTenant(fn () => AutomationEvent::count()));
    }

    /**
     * A canvas position cannot re-parent anything, and the payload is the reason:
     * there is no field it could arrive in.
     *
     * This is the §3 rule expressed on the server side of the feature. Dropping
     * a box on another box is a picture with two overlapping rectangles, and a
     * client that decided to mean more than that by it would have to send a
     * field this endpoint would refuse.
     */
    public function test_a_canvas_save_cannot_carry_a_parent(): void
    {
        // The control first: exactly this body without the extra field is
        // accepted, so the refusal below is about the field and not about the
        // rest of the request.
        $box = ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1];

        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => [$box]])
            ->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [$box + ['parent_company_id' => (string) Str::uuid()]],
        ])->assertOk();

        // Accepted and ignored — an unknown key in a validated array is not a
        // 422, and it must not become a stored one either. What matters is that
        // nothing about the company moved, which is what the tree still says.
        $this->assertSame(
            [$box],
            $this->preferencesOf($this->alpha['admin'])[Canvas::PREFERENCE],
        );

        $this->assertNull(
            $this->inTenant(fn () => Company::find($this->alpha['company']->id)->parent_company_id),
        );
        $this->assertSame(
            $this->alpha['company']->id,
            $this->inTenant(fn () => Franchise::find($this->alpha['franchise']->id)->company_id),
        );
    }

    // ── the edges of the route ───────────────────────────────────

    public function test_none_of_it_works_signed_out(): void
    {
        $this->putJson('/api/v1/organization/canvas', ['boxes' => []])->assertStatus(401);
    }

    /**
     * §13 makes a lapsed tenant read-only, and rearranging is not reading. The
     * exemptions cover billing, exports, privacy, auth and everything below /me;
     * this path is none of those, deliberately.
     */
    public function test_a_lapsed_subscription_cannot_save_an_arrangement(): void
    {
        $this->lapse();

        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', [
                'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
            ])
            ->assertStatus(402);

        $this->assertArrayNotHasKey(Canvas::PREFERENCE, $this->preferencesOf($this->alpha['admin']));
    }

    /** Reading is still reading: the structure is still there to look at. */
    public function test_a_lapsed_subscription_can_still_be_told_its_arrangement(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 8, 'y' => 9]],
        ])->assertOk();

        $this->lapse();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 8, 'y' => 9]],
            $this->asToldTo($this->alpha['admin']),
        );
    }
}
