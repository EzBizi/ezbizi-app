<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\OrganizationCanvasController as Canvas;
use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Access\Refusal;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Organization tab's structure canvas — where the business put the boxes.
 *
 * Three kinds of test, and the second and third are the point of the file.
 *
 * The first kind is the ordinary discipline of this write: a coordinate is
 * clamped rather than refused, a fraction is rounded, a box named twice is
 * stored once, a blob written by an older build cannot make the endpoint fall
 * over.
 *
 * The second kind is about what changed today: the arrangement is ONE
 * organization's rather than one person's, so a colleague's save is what
 * everybody else is told, it is one row rather than one per user, no other
 * tenant can see it, and saving it needs a permission AND an assignment at the
 * whole organization — while somebody who holds neither still sees the picture.
 *
 * The third kind is about what this feature deliberately is NOT. §3 says
 * entities are never moved between parents, and this canvas is a picture of
 * that structure that a person drags around. So there are tests here asserting
 * that a stored position grants nothing and that no automation event is raised
 * — claims the controller's docblock makes at length, which would otherwise be
 * prose nobody can check.
 *
 * ── The one engine rule everything here obeys ────────────────────
 *
 * MySQL does not preserve a JSON object's key order — it sorts keys by length
 * then lexicographically — while sqlite keeps insertion order. Nothing below
 * compares a JSON OBJECT read back out of the database by whole-value equality.
 * Array element order is safe and is relied on. Concretely: raw column reads go
 * through [inAuthoredKeyOrder]; `audit_events.changes` is asserted key by key;
 * and every RESPONSE assertion is engine-independent for free, because
 * everything a client sees is rebuilt by Canvas::clean() in one fixed order.
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
        // almost every test here leans on would refuse for a reason that has
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
     * The organization's stored arrangement, straight out of the column.
     *
     * DB::table rather than the Setting model, because that model is
     * tenant-scoped and this is a test asserting about a row rather than code
     * taking a path a request takes.
     *
     * @return array<mixed>|null null when no row exists at all
     */
    private function storedArrangement(?string $organizationId = null): ?array
    {
        $raw = DB::table('settings')
            ->where('scope_type', 'organization')
            ->where('scope_id', $organizationId ?? $this->alpha['org']->id)
            ->where('key', Canvas::SETTING)
            ->value('value');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? self::inAuthoredKeyOrder($decoded) : $decoded;
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
     * column. Everything the CLIENT sees goes through Canvas::clean(), which
     * rebuilds each box in a fixed order, so a response is deterministic
     * whatever the engine did. ANY new test that reads `settings.value`
     * directly must come through here; a response assertion must not, because
     * it would then be asserting about this helper rather than about the
     * server.
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

    /** Put a blob into the organization's setting the way an older build, or a hand edit, might have. */
    private function storeRaw(mixed $value, ?string $organizationId = null): void
    {
        $organizationId ??= $this->alpha['org']->id;

        DB::table('settings')->updateOrInsert(
            ['scope_type' => 'organization', 'scope_id' => $organizationId, 'key' => Canvas::SETTING],
            [
                'id'              => (string) Str::uuid(),
                'organization_id' => $organizationId,
                // Encoded rather than handed over raw: the column is `json`, and
                // MySQL refuses text that is not valid JSON outright — which
                // would make this helper fail on the real engine while passing
                // on sqlite, for reasons that have nothing to do with the test.
                'value'           => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        );
    }

    /**
     * The arrangement as the CLIENT is told it, on the tree it describes.
     *
     * `json('canvas')` is safe here where a dotted preference key was not: this
     * is a top-level key with no dot in it.
     *
     * @return array<mixed>|null
     */
    private function asToldTo(User $user): ?array
    {
        return $this->as($user)->getJson('/api/v1/companies')->assertOk()->json('canvas');
    }

    /** A second person in the same organization, assigned organization-wide like the first. */
    private function secondPerson(): User
    {
        return $this->inTenant(function () {
            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name' => 'Other', 'email' => 'other@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'          => $this->alpha['org']->id,
                'user_id'                  => $user->id,
                'assigned_organization_id' => $this->alpha['org']->id,
            ]);

            return $user;
        });
    }

    /**
     * Somebody who can see the structure and may not rearrange it.
     *
     * Assigned at the WHOLE organization deliberately, so the only thing they
     * are missing is the permission — otherwise the test could not tell which
     * of the two halves of the gate refused them.
     */
    private function viewerWithoutThePermission(): User
    {
        return $this->personWith(
            name: 'Viewer', email: 'viewer@alpha.test',
            permissions: ['companies.view'],
            assignment: ['assigned_organization_id' => $this->alpha['org']->id],
        );
    }

    /**
     * Somebody who holds the permission and stands at one branch.
     *
     * The shape SettingsController::reaches records the cost of: a plain role
     * carrying only `organizations.edit`, assigned to a single franchise, was
     * measured rewriting the whole tenant's security policy.
     */
    private function branchManager(): User
    {
        return $this->personWith(
            name: 'Branch Manager', email: 'bm@alpha.test',
            permissions: ['companies.view', 'organizations.edit'],
            assignment: ['assigned_franchise_id' => $this->alpha['franchise']->id],
        );
    }

    /** @param  string[]  $permissions */
    private function personWith(string $name, string $email, array $permissions, array $assignment): User
    {
        return $this->inTenant(function () use ($name, $email, $permissions, $assignment) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => $name, 'is_administrator' => false,
            ]);

            foreach ($permissions as $key) {
                // The catalogue is synced in setUp, so these all exist. Asserted
                // rather than skipped: a missing row would silently turn this
                // into a role with no permissions and make a refusal test pass
                // for the wrong reason.
                $permission = Permission::where('key', $key)->first();

                $this->assertNotNull($permission, "The catalogue has no '{$key}'.");

                $role->permissions()->attach($permission->getKey());
            }

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $role->getKey(),
                'name' => $name, 'email' => $email,
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id'         => $user->id,
            ] + $assignment);

            return $user;
        });
    }

    private function lapse(): void
    {
        $this->alpha['org']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->alpha['org']->refresh();

        SubscriptionGuard::flush();
    }

    /** The three boxes most of these tests save. */
    private function threeBoxes(): array
    {
        return [
            ['kind' => 'organization', 'id' => $this->alpha['org']->id,       'x' => 0,   'y' => 0],
            ['kind' => 'company',      'id' => $this->alpha['company']->id,   'x' => 232, 'y' => 156],
            ['kind' => 'franchise',    'id' => $this->alpha['franchise']->id, 'x' => 464, 'y' => 312],
        ];
    }

    // ── it is the organization's, not one person's ───────────────

    /**
     * The change, in one test: what one person saved is what the next person
     * opens.
     *
     * The owner's words were "should be shared obviously". Before today the
     * second read here would have been empty, because the arrangement lived in
     * the first person's own `users.preferences` row.
     */
    public function test_an_arrangement_one_person_saved_is_what_everybody_else_is_told(): void
    {
        $other = $this->secondPerson();

        // The control first: nobody has arranged anything, so the colleague
        // sees an empty list rather than an arrangement that happened to be
        // theirs already.
        $this->assertSame([], $this->asToldTo($other));

        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        $this->assertSame($this->threeBoxes(), $this->asToldTo($other));
    }

    /**
     * One row for the whole business, at organization scope.
     *
     * Asserted column by column rather than by comparing the decoded blob: the
     * shape of the row is what this test is about, and the value is a JSON
     * object whose key order MySQL does not preserve.
     */
    public function test_an_arrangement_is_one_row_for_the_whole_organization(): void
    {
        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        // Save twice: two rows would make resolution order-dependent, and
        // updateOrCreate is the only thing standing between one arrangement and
        // a table that grows a row per debounced drag.
        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        $rows = DB::table('settings')->where('key', Canvas::SETTING)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('organization', $rows[0]->scope_type);
        $this->assertSame($this->alpha['org']->id, $rows[0]->scope_id);
        $this->assertSame($this->alpha['org']->id, $rows[0]->organization_id);

        // And the value is the three boxes — element by element, so nothing here
        // depends on how the engine ordered the keys inside one of them.
        $stored = $this->storedArrangement();

        $this->assertCount(3, $stored);
        $this->assertSame($this->alpha['company']->id, $stored[1]['id']);
        $this->assertSame(232, $stored[1]['x']);
        $this->assertSame(156, $stored[1]['y']);
    }

    /**
     * Shared means shared with THIS organization, and no further.
     *
     * The resolver is scoped by (scope_type, scope_id) and the model is
     * tenant-scoped on top of that; this is the assertion that says so out
     * loud, because "everybody sees the same picture" is a sentence one bad
     * argument away from meaning everybody on the platform.
     */
    public function test_another_organizations_arrangement_is_not_visible(): void
    {
        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 11, 'y' => 22]],
        ])->assertOk();

        $this->as($beta['administrator'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $beta['company']->id, 'x' => 77, 'y' => 88]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 11, 'y' => 22]],
            $this->asToldTo($this->alpha['admin']),
        );

        $this->assertSame(
            [['kind' => 'company', 'id' => $beta['company']->id, 'x' => 77, 'y' => 88]],
            $this->as($beta['administrator'])->getJson('/api/v1/companies')->assertOk()->json('canvas'),
        );
    }

    /** Nobody has arranged anything: a list, present and empty, rather than a missing key. */
    public function test_a_canvas_nobody_has_arranged_is_an_empty_list_on_the_tree(): void
    {
        $tree = $this->as($this->alpha['admin'])->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertArrayHasKey('canvas', $tree);
        $this->assertSame([], $tree['canvas']);
    }

    // ── the gate, and what it does not take away ─────────────────

    /**
     * Refused the SAVE, and told why in the server's own words — while still
     * being shown the picture.
     *
     * The whole shape of §4 for this feature in one test. A refusal that also
     * hid the arrangement would be a permission doing two jobs, and one of them
     * would be taking away a read nobody decided to take away.
     */
    public function test_somebody_without_the_permission_is_refused_the_save_and_still_gets_the_arrangement(): void
    {
        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        $viewer   = $this->viewerWithoutThePermission();
        $expected = Refusal::permission('rearrange the structure everyone sees')->message();

        $this->as($viewer)->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertForbidden()->assertJson(['message' => $expected]);

        // Nothing was written. Read through the raw column rather than the tree,
        // because a save that half-worked would otherwise be invisible.
        $this->assertCount(3, $this->storedArrangement());

        $tree = $this->as($viewer)->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertSame($this->threeBoxes(), $tree['canvas']);
        $this->assertFalse($tree['organization']['may_arrange']);
        $this->assertSame($expected, $tree['organization']['may_arrange_reason']);
    }

    /**
     * The reach half, which is the one that would have been forgotten.
     *
     * SettingsController records what it cost when it was: a plain role holding
     * only `organizations.edit`, assigned to a single franchise, rewrote the
     * whole tenant's security policy. The same shape here is somebody who runs
     * one branch rearranging the chart of the entire business for everybody in
     * it.
     */
    public function test_somebody_assigned_to_one_franchise_cannot_rearrange_the_whole_business(): void
    {
        $manager  = $this->branchManager();
        $expected = Refusal::abovePosition('rearrange the structure everyone sees')->message();

        $this->as($manager)->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertForbidden()->assertJson(['message' => $expected]);

        $this->assertNull($this->storedArrangement());

        // And the control that would have produced it was never offered.
        $tree = $this->as($manager)->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertFalse($tree['organization']['may_arrange']);
        $this->assertSame($expected, $tree['organization']['may_arrange_reason']);

        // The refusal is about POSITION, not permission — so it must not send
        // them to ask for a permission they already hold.
        $this->assertStringContainsString('assigned at the whole organization', $expected);
    }

    /**
     * The sentence on the tree and the sentence in the 403 are one string.
     *
     * A client that worded its own refusal is how a dead control and the server
     * come to disagree. This is the assertion that they are computed once
     * rather than twice: reword the act inside refusalToArrange and both sides
     * move together, which is only possible because there is one of them.
     */
    public function test_the_sentence_on_the_tree_is_the_sentence_the_save_returns(): void
    {
        $viewer = $this->viewerWithoutThePermission();

        $refused = $this->as($viewer)->putJson('/api/v1/organization/canvas', [
            'boxes' => [],
        ])->assertForbidden()->json('message');

        $tree = $this->as($viewer)->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertNotEmpty($refused);
        $this->assertSame($tree['organization']['may_arrange_reason'], $refused);
    }

    /**
     * `may_arrange` is not `may_edit`.
     *
     * They are two different questions — one permission, one permission plus a
     * position — and a client that read the wrong one would draw a live canvas
     * for the branch manager above.
     */
    public function test_may_arrange_is_a_different_question_from_may_edit(): void
    {
        $tree = $this->as($this->branchManager())->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertTrue($tree['organization']['may_edit']);
        $this->assertFalse($tree['organization']['may_arrange']);
    }

    /** Somebody who holds both halves is told so, with nothing to explain. */
    public function test_an_administrator_is_told_they_may_arrange(): void
    {
        $tree = $this->as($this->alpha['admin'])->getJson('/api/v1/companies')->assertOk()->json();

        $this->assertTrue($tree['organization']['may_arrange']);
        $this->assertNull($tree['organization']['may_arrange_reason']);
    }

    // ── the audit decision ───────────────────────────────────────

    /**
     * Audited, and audited THIN.
     *
     * The old per-user save wrote nothing and was right to: §10 records what
     * people do to the BUSINESS, and where somebody parked a box in their own
     * browser was not that. It is that now — one drag changes what every
     * colleague opens tomorrow — so "who rearranged the chart, and when" is
     * recorded, and it is the only question anybody will ever ask about this.
     *
     * The coordinates are NOT recorded, which is the other half of the decision
     * and the reason this write does not go through PATCH /settings/{key}: that
     * route would have written the whole before-blob and the whole after-blob
     * into `changes` on every debounced save.
     *
     * `changes` is asserted key by key. It is a JSON object, and MySQL sorts an
     * object's keys by length then lexicographically — so `assertSame` on the
     * whole map would compare something the engine does not guarantee.
     */
    public function test_saving_an_arrangement_is_audited_without_its_coordinates(): void
    {
        $signedIn = $this->as($this->alpha['admin']);

        AuditEvent::query()->delete();   // signing in writes rows of its own

        $signedIn->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        $events = AuditEvent::where('action', 'organization.structure_arranged')->get();

        $this->assertCount(1, $events);

        $event = $events->first();

        $this->assertSame(\App\Models\Organization::class, $event->target_type);
        $this->assertSame($this->alpha['org']->id, $event->target_id);
        $this->assertSame($this->alpha['admin']->id, $event->actor_id);

        // The shape first, so a row carrying something else entirely fails as a
        // failure rather than erroring on a missing key.
        $this->assertArrayHasKey('boxes_before', $event->changes);
        $this->assertArrayHasKey('boxes_after', $event->changes);

        $this->assertSame(0, $event->changes['boxes_before']);
        $this->assertSame(3, $event->changes['boxes_after']);

        // Counts, never positions. A row carrying x and y is the fat variant
        // this endpoint exists to avoid.
        $encoded = json_encode($event->changes, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"x"', $encoded);
        $this->assertStringNotContainsString('"y"', $encoded);
    }

    /** The before-count is the previous arrangement's, not zero forever. */
    public function test_the_audit_row_counts_what_was_there_before(): void
    {
        $signedIn = $this->as($this->alpha['admin']);

        $signedIn->putJson('/api/v1/organization/canvas', ['boxes' => $this->threeBoxes()])
            ->assertOk();

        $signedIn->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 5, 'y' => 5]],
        ])->assertOk();

        $latest = AuditEvent::where('action', 'organization.structure_arranged')
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();

        $this->assertSame(3, $latest->changes['boxes_before']);
        $this->assertSame(1, $latest->changes['boxes_after']);
    }

    /** A refused save writes no audit row: nothing happened to record. */
    public function test_a_refused_save_is_not_audited(): void
    {
        $viewer = $this->viewerWithoutThePermission();
        $signedIn = $this->as($viewer);

        AuditEvent::query()->delete();

        $signedIn->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, AuditEvent::where('action', 'organization.structure_arranged')->count());
    }

    // ── ids this server cannot resolve ───────────────────────────

    /**
     * The tempting `Rule::exists`, and the arrangement it would lose.
     *
     * A company can be missing for the afternoon — somebody's role is being
     * edited, a module is switched off and back on, a scope is narrowed — and
     * forgetting where it sat because it happened to be absent when somebody
     * nudged a different box loses an arrangement nobody changed. It would now
     * lose it for the whole office rather than for one person.
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
            $this->storedArrangement(),
        );
    }

    /**
     * The other half of keeping an id nobody here can resolve: it must grant
     * nothing.
     *
     * The controller's defence for storing an unresolvable id is that nothing
     * downstream ever resolves one — the tree comes from CompaniesController
     * under the Company policy, and this list only says where to draw whatever
     * survives that. This asserts it rather than trusting the paragraph.
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
            $this->storedArrangement(),
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

        $this->assertSame($clamped, $this->storedArrangement());

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
            $this->storedArrangement(),
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

        $this->assertNull($this->storedArrangement());
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
            $this->storedArrangement(),
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

        $this->assertCount(2, $this->storedArrangement());
    }

    /**
     * §3 fixes the depth at Organization → Company → Sub Company → Franchise,
     * and a sub company is a company row with a parent. An open vocabulary here
     * would let a client grow a column the whole organization reads with shapes
     * that will never exist and nothing to clear them.
     */
    public function test_an_unknown_kind_is_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'sub_company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => 0]],
        ])->assertStatus(422);

        $this->assertNull($this->storedArrangement());
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

        $this->assertNull($this->storedArrangement());
    }

    /**
     * "I put everything back" is a real answer, and `required` would make it
     * unsayable — leaving somebody unable to undo an arrangement.
     */
    public function test_an_empty_arrangement_is_a_legal_save(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 1, 'y' => 1]],
        ])->assertOk();

        $this->as($this->alpha['admin'])
            ->putJson('/api/v1/organization/canvas', ['boxes' => []])
            ->assertOk();

        $this->assertSame([], $this->storedArrangement());
    }

    // ── a blob written before any of this existed ────────────────

    /**
     * Cleaned on the way OUT as well as on the way in.
     *
     * `settings.value` is a JSON column: it can be hand-edited, restored from
     * an older backup, or written by a build before a rule existed. It is worse
     * than that here — SettingDefinition::castJson THROWS on a value that is
     * neither an array nor valid JSON, so without the out-clean a
     * wrong-shaped row is not junk on the tree, it is a 500 on the endpoint the
     * whole Organization screen is drawn from, for every person in the
     * organization at once.
     */
    public function test_an_arrangement_stored_in_the_wrong_shape_reaches_the_client_as_no_arrangement(): void
    {
        $this->storeRaw('nonsense');

        $this->assertSame([], $this->asToldTo($this->alpha['admin']));
    }

    /**
     * One unusable entry is a box in the wrong place. Bailing on the whole blob
     * is the office's afternoon.
     */
    public function test_a_stored_entry_that_is_missing_a_coordinate_does_not_lose_the_others(): void
    {
        $this->storeRaw([
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
        $this->storeRaw([
            ['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => -4000, 'y' => 1.0e300],
        ]);

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 0, 'y' => Canvas::MAX_COORDINATE]],
            $this->asToldTo($this->alpha['admin']),
        );
    }

    /**
     * A stored value that is a bare SCALAR still opens the tab.
     *
     * ── Why this is its own test and not a case of the one below ──
     *
     * Because that one saves OVER the bad value, so it never reads one. This
     * one only reads, which is the direction nobody controls: the column can
     * hold `4`, or `"nonsense"`, after a restore from an older build or a hand
     * edit, and the first person to open the tab that morning is not the person
     * who is about to save.
     *
     * The guard is the `is_array($stored)` on the way into clean(), and it is
     * doing real work rather than being defensive punctuation — without it a
     * scalar reaches a function that iterates it.
     *
     * A review of this change claimed the guard was insufficient: that a scalar
     * raises TypeError inside the cast, before the catch, and takes the tab
     * down with a 500. Two agents agreed. This test was written to prove it and
     * proved the opposite — it passes against the unmodified code, and adding a
     * TypeError to the catch changed nothing. The claim was wrong; the test is
     * kept because the guarantee is worth pinning either way, and because it now
     * records that this path was checked rather than assumed.
     *
     * Asserted through the TREE endpoint rather than by calling the helper,
     * because a failure here is not an exception somebody finds in a log; it is
     * the screen failing to open, for everybody in the organization at once,
     * now that the arrangement is one shared row.
     */
    public function test_an_arrangement_stored_as_a_scalar_reads_as_no_arrangement(): void
    {
        $this->storeRaw('nonsense');

        $this->assertSame([], $this->asToldTo($this->alpha['admin']));

        // A number as well as a string, because they fail identically and the
        // guard must not be a special case for one of them.
        $this->storeRaw(4);

        $this->assertSame([], $this->asToldTo($this->alpha['admin']));
    }

    /**
     * A stored blob of the wrong shape is REPLACED by a save rather than
     * merged into or thrown over.
     */
    public function test_an_arrangement_stored_in_the_wrong_shape_is_replaced_by_a_save(): void
    {
        $this->storeRaw('nonsense');

        $this->as($this->alpha['admin'])->putJson('/api/v1/organization/canvas', [
            'boxes' => [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 3, 'y' => 4]],
        ])->assertOk();

        $this->assertSame(
            [['kind' => 'company', 'id' => $this->alpha['company']->id, 'x' => 3, 'y' => 4]],
            $this->storedArrangement(),
        );
    }

    // ── it is not on the settings screen, and it is still a setting ──

    /**
     * The Settings screen does not draw a box of raw coordinates.
     *
     * `editedOn` moves the FORM, not the setting. The row is left out of
     * index() and out of nothing else — which the next test asserts, so this
     * one cannot be satisfied by refusing the key outright.
     */
    public function test_the_arrangement_is_not_a_row_on_the_settings_screen(): void
    {
        $settings = $this->as($this->alpha['admin'])
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$this->alpha['org']->id)
            ->assertOk()->json('data');

        $this->assertNotEmpty($settings);
        $this->assertNotContains(Canvas::SETTING, array_column($settings, 'key'));

        // The control: the rest of the catalogue is still there, so the
        // assertion above is about one row and not about an empty payload.
        $this->assertContains('timezone', array_column($settings, 'key'));
    }

    // ── what this endpoint deliberately does not do ──────────────

    /**
     * No `organization.structure_arranged` TRIGGER, and the day somebody adds
     * one this fails.
     *
     * `organization.settings_changed` already describes this write — the
     * arrangement is a setting saved at organization level — and it is
     * isRaised: false because settings are saved without going through the
     * outbox. Making it fire for this one setting alone would be a trigger that
     * answers for the org chart and stays silent for the tax rate.
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
        $this->assertSame([$box], $this->storedArrangement());

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

        $this->assertNull($this->storedArrangement());
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
