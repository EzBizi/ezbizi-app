<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrganizationCanvasController as Canvas;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Structure\PromoteCanvasArrangements;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What becomes of the arrangements people had already made.
 *
 * The structure canvas was one person's preference and is now one
 * organization's setting. There are real per-user arrangements in production,
 * and the migration that retires the old key has to decide what happens to
 * them. The decision — promote where exactly ONE person in an organization had
 * one, promote nothing where two or more did — is argued in
 * {@see PromoteCanvasArrangements}. This is where each branch of it is held
 * down.
 *
 * ── Why the class and not the migration ──────────────────────────
 *
 * A migration is hard to run twice on purpose, and "twice" is half of what
 * needs asserting here: a re-run must not put yesterday's private picture back
 * over the one the office has been using since. Invoking the class directly is
 * what makes that a two-line test.
 *
 * ── Engine safety ────────────────────────────────────────────────
 *
 * MySQL sorts a JSON object's keys by length then lexicographically; sqlite
 * keeps insertion order. So nothing here compares a decoded object by
 * whole-value equality. Both the promoted arrangement (a list of `{kind,id,x,y}`
 * objects) and a user's surviving `preferences` (a map) are asserted field by
 * field or by array_key_exists. Array ELEMENT order is preserved by both and is
 * relied on.
 */
class PromoteCanvasArrangementsTest extends TestCase
{
    use RefreshDatabase;

    /** The key as it was, spelled out — the class froze its own copy for the same reason. */
    private const OLD_KEY = 'organization.canvas';

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** Somebody else in Alpha, so an organization can have two arrangers. */
    private function second(string $email = 'other@alpha.test'): User
    {
        return $this->inTenant(fn () => User::create([
            'organization_id' => $this->alpha['org']->id,
            'role_id'         => $this->alpha['admin']->role_id,
            'name' => 'Other', 'email' => $email,
            'password' => 'a-real-password', 'is_active' => true,
        ]));
    }

    /** Write straight into the column, the way the old endpoint did. */
    private function givePreferences(User $user, array $preferences): void
    {
        $this->inTenant(function () use ($user, $preferences) {
            $found = User::find($user->id);

            $found->forceFill([
                'preferences' => array_merge($found->preferences ?? [], $preferences),
            ])->save();
        });
    }

    /** @return array<string, mixed> */
    private function preferencesOf(User $user): array
    {
        return $this->inTenant(fn () => User::find($user->id)->preferences ?? []);
    }

    /** The organization's shared arrangement, straight out of the settings row. */
    private function sharedArrangement(?string $organizationId = null): ?array
    {
        $raw = DB::table('settings')
            ->where('scope_type', 'organization')
            ->where('scope_id', $organizationId ?? $this->alpha['org']->id)
            ->where('key', Canvas::SETTING)
            ->value('value');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    private function box(string $kind, string $id, int $x, int $y): array
    {
        return ['kind' => $kind, 'id' => $id, 'x' => $x, 'y' => $y];
    }

    // ── the common case, and the owner's own ─────────────────────

    /**
     * One person arranged it, so it was already the organization's picture in
     * everything but name. Promoting it invents nothing and loses nothing.
     */
    public function test_a_lone_arrangement_becomes_the_organizations(): void
    {
        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [
                $this->box('organization', $this->alpha['org']->id, 0, 0),
                $this->box('company', $this->alpha['company']->id, 232, 156),
                $this->box('franchise', $this->alpha['franchise']->id, 464, 312),
            ],
        ]);

        app(PromoteCanvasArrangements::class)();

        $shared = $this->sharedArrangement();

        // Field by field: the list's element order is guaranteed on both
        // engines, the key order inside each box is not.
        $this->assertNotNull($shared, 'The lone arrangement was not promoted.');
        $this->assertCount(3, $shared);
        $this->assertSame('organization', $shared[0]['kind']);
        $this->assertSame($this->alpha['company']->id, $shared[1]['id']);
        $this->assertSame(232, $shared[1]['x']);
        $this->assertSame(156, $shared[1]['y']);
        $this->assertSame('franchise', $shared[2]['kind']);

        // One row, at organization scope, stamped with the tenant.
        $rows = DB::table('settings')->where('key', Canvas::SETTING)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('organization', $rows[0]->scope_type);
        $this->assertSame($this->alpha['org']->id, $rows[0]->scope_id);
        $this->assertSame($this->alpha['org']->id, $rows[0]->organization_id);
    }

    /**
     * Two arrangers, and no way to elect between them.
     *
     * Every heuristic considered — most boxes, most recently updated, oldest
     * account, the administrator — answers a question nobody asked and hands
     * one person's private picture to the whole office with nothing on screen
     * saying whose it was. Nothing is promoted; the tab opens on the automatic
     * layout, which is a DIFFERENT layout as of this release anyway, because
     * the level rule the old arrangement encodes is the bug being fixed.
     */
    public function test_two_arrangements_in_one_organization_promote_neither(): void
    {
        $other = $this->second();

        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        $this->givePreferences($other, [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 900, 900)],
        ]);

        app(PromoteCanvasArrangements::class)();

        $this->assertNull($this->sharedArrangement());
    }

    /**
     * An empty stored list is not an arrangement.
     *
     * Somebody who dragged a box and put it back has a key holding `[]`.
     * Counting them as an arranger would produce a second claimant and block a
     * promotion that should have happened — and the arrangement it blocked
     * would be the only real one in the organization.
     */
    public function test_an_empty_stored_list_does_not_count_as_a_second_arranger(): void
    {
        $other = $this->second();

        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        $this->givePreferences($other, [self::OLD_KEY => []]);

        app(PromoteCanvasArrangements::class)();

        $shared = $this->sharedArrangement();

        // Asserted present before it is measured, so a promotion that did not
        // happen at all fails as a failure rather than erroring on a null.
        $this->assertNotNull($shared, 'The one real arrangement was not promoted.');
        $this->assertCount(1, $shared);
        $this->assertSame(10, $shared[0]['x']);
    }

    /**
     * A blob nothing can read is not an arrangement either, and it must not be
     * promoted as-is.
     *
     * The client hands these numbers to a transform that throws on a value it
     * cannot use, so a bad promotion would be a screen nobody in the
     * organization could open — where before it was one person's own problem.
     */
    public function test_junk_is_cleaned_on_the_way_across_rather_than_promoted(): void
    {
        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [
                $this->box('company', $this->alpha['company']->id, -4000, 1_000_000),
                'not even a map',
                ['kind' => 'somewhere', 'id' => (string) Str::uuid(), 'x' => 1, 'y' => 1],
                ['kind' => 'company', 'id' => 'a whole sentence where an id should be', 'x' => 1, 'y' => 1],
            ],
        ]);

        app(PromoteCanvasArrangements::class)();

        $shared = $this->sharedArrangement();

        $this->assertNotNull($shared, 'The arrangement was not promoted at all.');
        $this->assertCount(1, $shared);
        $this->assertSame(0, $shared[0]['x']);
        $this->assertSame(Canvas::MAX_COORDINATE, $shared[0]['y']);
    }

    /** A blob that cleans down to nothing is nobody arranging anything. */
    public function test_an_arrangement_that_is_entirely_junk_promotes_nothing(): void
    {
        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => ['not even a map', ['kind' => 'nope', 'id' => 'nope']],
        ]);

        app(PromoteCanvasArrangements::class)();

        $this->assertNull($this->sharedArrangement());
    }

    // ── the old key is gone, either way ──────────────────────────

    /**
     * Dead the moment this returns.
     *
     * Leaving it inert would mean the out-clean has to be kept alive to keep it
     * safe, which is exactly the code-with-no-job the house rules say to
     * delete. Asserted in BOTH cases, because the promoted user is the one
     * whose key it is most tempting to leave behind "in case".
     */
    public function test_the_old_key_is_removed_from_everybody_either_way(): void
    {
        $other = $this->second();

        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        // The control: it really is there before the run, so the assertions
        // below are about a removal rather than about a key that never existed.
        $this->assertArrayHasKey(self::OLD_KEY, $this->preferencesOf($this->alpha['admin']));

        app(PromoteCanvasArrangements::class)();

        // The promoted one.
        $this->assertArrayNotHasKey(self::OLD_KEY, $this->preferencesOf($this->alpha['admin']));
        $this->assertNotNull($this->sharedArrangement());

        // And a second organization where nothing was promoted, so the strip is
        // not a side effect of the promotion.
        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        app(TenantContext::class)->run($beta['organization'], function () use ($beta) {
            $second = User::create([
                'organization_id' => $beta['organization']->id,
                'role_id'         => $beta['administrator']->role_id,
                'name' => 'B2', 'email' => 'b2@beta.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            foreach ([$beta['administrator'], $second] as $index => $person) {
                User::find($person->id)->forceFill([
                    'preferences' => [
                        self::OLD_KEY => [[
                            'kind' => 'company', 'id' => $beta['company']->id,
                            'x' => $index, 'y' => $index,
                        ]],
                    ],
                ])->save();
            }
        });

        app(PromoteCanvasArrangements::class)();

        $this->assertNull($this->sharedArrangement($beta['organization']->id));

        app(TenantContext::class)->run($beta['organization'], function () use ($beta) {
            foreach (User::all() as $person) {
                $this->assertArrayNotHasKey(self::OLD_KEY, $person->preferences ?? []);
            }
        });

        // Untouched by all of it: `other` never had the key at all, and a strip
        // that wrote to them anyway would be a strip rewriting rows it has no
        // business in.
        $this->assertSame([], $this->preferencesOf($other));
    }

    /**
     * `preferences` is one blob shared with everything else somebody chose.
     *
     * A strip that took their theme with it would be a bug found days later by
     * somebody who never connected the two.
     */
    public function test_every_other_preference_survives(): void
    {
        $this->givePreferences($this->alpha['admin'], [
            'appearance.theme'   => 'dark',
            'appearance.density' => 'compact',
            self::OLD_KEY        => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        app(PromoteCanvasArrangements::class)();

        $preferences = $this->preferencesOf($this->alpha['admin']);

        // Key by key. This is a JSON OBJECT, and MySQL does not keep the order
        // the keys were written in, so assertSame on the whole map would be
        // comparing something the engine never promised.
        $this->assertSame('dark', $preferences['appearance.theme']);
        $this->assertSame('compact', $preferences['appearance.density']);
        $this->assertArrayNotHasKey(self::OLD_KEY, $preferences);
    }

    // ── running it twice ─────────────────────────────────────────

    /**
     * A re-run must not destroy an arrangement somebody has saved since.
     *
     * The realistic shape of this: the migration runs, the office rearranges
     * the chart, and then a restore or a re-deploy replays the migration. If
     * the second run promoted again, the office would arrive to find their
     * chart replaced by a picture one colleague made before the change.
     */
    public function test_an_organization_that_already_has_a_shared_arrangement_is_left_alone(): void
    {
        // What the office saved after the change.
        DB::table('settings')->insert([
            'id'              => (string) Str::uuid(),
            'organization_id' => $this->alpha['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $this->alpha['org']->id,
            'key'             => Canvas::SETTING,
            'value'           => json_encode(
                [$this->box('company', $this->alpha['company']->id, 500, 600)],
                JSON_THROW_ON_ERROR,
            ),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // And a stale per-user blob that survived somehow.
        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        app(PromoteCanvasArrangements::class)();

        $shared = $this->sharedArrangement();

        $this->assertNotNull($shared, 'The arrangement the office saved is gone.');
        $this->assertCount(1, $shared);
        $this->assertSame(500, $shared[0]['x']);
        $this->assertSame(600, $shared[0]['y']);

        // The dead key still goes, because it is dead either way.
        $this->assertArrayNotHasKey(self::OLD_KEY, $this->preferencesOf($this->alpha['admin']));
    }

    /** Running it twice from scratch is the same as running it once. */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->givePreferences($this->alpha['admin'], [
            self::OLD_KEY => [$this->box('company', $this->alpha['company']->id, 10, 20)],
        ]);

        app(PromoteCanvasArrangements::class)();

        $first = $this->sharedArrangement();

        app(PromoteCanvasArrangements::class)();

        $this->assertCount(1, DB::table('settings')->where('key', Canvas::SETTING)->get());
        $this->assertSame($first, $this->sharedArrangement());
    }

    /** Nothing to do at all, on a platform where nobody ever arranged anything. */
    public function test_an_organization_with_no_arrangements_gets_no_row(): void
    {
        app(PromoteCanvasArrangements::class)();

        $this->assertNull($this->sharedArrangement());
        $this->assertSame(0, DB::table('settings')->where('key', Canvas::SETTING)->count());
    }
}
