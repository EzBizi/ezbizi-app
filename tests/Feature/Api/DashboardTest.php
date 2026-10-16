<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Support\Dashboard\ComingSoonGlance;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Modules\ModuleAvailability;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

/**
 * The dashboard as one glance per reachable module (spec §4–§6, §11).
 *
 * The two things a dashboard still gets wrong that a list screen does not:
 * showing somebody numbers from outside their reach, and adding up money that
 * should not be added up. Layout cannot hide a reachable box; omitting one
 * appends it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first. Without this the role gets
        // an empty permission set and every glance looks correctly hidden.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
     * outside a context is refused on purpose (BelongsToTenant) — a test that
     * reached for it directly would be exercising a path no request takes.
     *
     * @return array<string, mixed>
     */
    private function preferencesOf(User $user): array
    {
        return $this->inTenant(fn () => User::find($user->id)->preferences ?? []);
    }

    /**
     * Every permission the Administrator holds, without being one. Since
     * 2026-10-16 an Administrator reaches the whole organization whatever
     * their assignment (Jose: not "limited to that company"), so the reach
     * tests use somebody who is not.
     */
    private function notAdministratorRole(): \App\Models\Role
    {
        $role = \App\Models\Role::create([
            'organization_id' => $this->alpha['org']->id,
            'name' => 'Manager '.\Illuminate\Support\Str::random(4),
            'is_system' => false,
        ]);
        // Every permission there is: an Administrator holds them implicitly.
        $role->permissions()->sync(Permission::query()->pluck('id'));

        return $role;
    }

    /** A second franchise with its own user, reaching only that franchise. */
    private function secondBranch(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id' => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => $this->notAdministratorRole()->id,
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /**
     * Somebody holding exactly these permissions, assigned to the franchise.
     *
     * @param  string[]  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys, string $email = 'limited@alpha.test'): User
    {
        return $this->inTenant(function () use ($permissionKeys, $email) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Limited', 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', $permissionKeys)->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => $email,
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }

    private function customer(Franchise $franchise, string $name = 'Person'): Customer
    {
        return $this->inTenant(fn () => Customer::create([
            'owning_franchise_id' => $franchise->id,
            'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => $name,
        ]));
    }

    /**
     * @param  bool  $earned  Stamp `earned_at`, i.e. this job is revenue.
     *
     * Since 2026-09-23 a completed status alone is not revenue: the dashboard
     * reports against `earned_at`, which `JobsController` stamps once at the
     * transition, so a closed month cannot reopen (§8.1). `earned_at` is not
     * fillable for that reason, which is why this is a second write.
     */
    private function job(Franchise $franchise, array $attributes = [], bool $earned = false): Job
    {
        return $this->inTenant(function () use ($franchise, $attributes, $earned) {
            $job = Job::create($attributes + [
                'franchise_id' => $franchise->id,
                'customer_id' => $this->customer($franchise)->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
            ]);

            if ($earned) {
                $job->earned_at = $job->created_at;
                $job->save();
            }

            return $job;
        });
    }

    /**
     * What this organization counts as earned (§8.2).
     *
     * An organization setting since 2026-09-23, not a dashboard-layout option:
     * one person's panel cannot decide a company figure, and `earned_at` is
     * stamped from this same list, so a second source would disagree with the
     * history it reports.
     *
     * @param  list<string>  $statuses
     */
    private function earnedStatuses(array $statuses): void
    {
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('jobs.earned_statuses', $this->alpha['org'], $statuses));
    }

    private function glancesOf(User $user): array
    {
        return $this->as($user)->getJson('/api/v1/dashboard')->assertOk()->json('glances');
    }

    // ── reach ────────────────────────────────────────────────────

    public function test_a_branch_user_sees_only_their_own_franchises_jobs(): void
    {
        [$second, $branchUser] = $this->secondBranch();

        $this->job($this->alpha['franchise']);
        $this->job($this->alpha['franchise']);
        $this->job($second);

        $glances = $this->as($branchUser)->getJson('/api/v1/dashboard')->assertOk()->json('glances');
        $jobs = collect($glances)->firstWhere('key', 'jobs');

        // A franchise manager's dashboard showing organization-wide totals is a
        // data leak that looks like a feature — nobody reports a number that is
        // too big as a bug.
        $this->assertSame(1, array_sum(array_column($jobs['data']['slices'], 'total')));
    }

    public function test_the_administrator_sees_everything_they_reach(): void
    {
        [$second] = $this->secondBranch();

        $this->job($this->alpha['franchise']);
        $this->job($second);

        $glances = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('glances');

        $this->assertSame(
            2,
            array_sum(array_column(collect($glances)->firstWhere('key', 'jobs')['data']['slices'], 'total')),
        );
    }

    public function test_a_user_with_no_assignments_sees_nothing_rather_than_everything(): void
    {
        $stranger = $this->inTenant(fn () => User::create([
            'organization_id' => $this->alpha['org']->id,
            'role_id' => $this->notAdministratorRole()->id,
            'name' => 'Stranger', 'email' => 'stranger@alpha.test',
            'password' => 'a-real-password', 'is_active' => true,
        ]));

        $this->job($this->alpha['franchise']);

        $glances = $this->as($stranger)->getJson('/api/v1/dashboard')->assertOk()->json('glances');
        $jobs = collect($glances)->firstWhere('key', 'jobs');

        // `whereIn` on an empty list matches nothing. Writing it as a condition
        // that skips the clause when the list is empty would invert this into
        // "no assignments means see everything".
        $this->assertTrue($jobs['data']['empty']);

        /*
         * A sentence, not Jobs' sentence.
         *
         * This pinned the literal "No jobs in reach." — a Dashboard test
         * asserting another module's COPY, so a wording change Jobs is
         * entitled to make turns a file they do not own red, and the module
         * session finds a failure they have no business editing. The identical
         * coupling in GlanceRegistryTest held Marketing's exact words through a
         * release because correcting a stale sentence meant turning a
         * dashboard test red first.
         *
         * What this test is about is one row above: a stranger to every
         * franchise gets an EMPTY jobs box, because `whereIn` on an empty list
         * matches nothing. That the box also says something is worth keeping;
         * WHICH something is Jobs' business and changes without warning here.
         */
        $this->assertNotSame('', trim((string) $jobs['data']['sentence']));
    }

    public function test_inventory_view_returns_the_inventory_box(): void
    {
        $limited = $this->userWithPermissions(['inventory.view']);
        $keys = array_column($this->as($limited)->getJson('/api/v1/dashboard')->assertOk()->json('glances'), 'key');
        $this->assertSame(['inventory'], $keys);
    }

    // ── money ────────────────────────────────────────────────────

    /**
     * Money follows the role (Jose, 2026-09-29, refined 2026-10-05: "as for
     * the money remember its a role y permission things where ( can see
     * financials ) should be toggled"). A reader who holds every glance's
     * permission and none of the `*.financials` ones gets no amount in the
     * RESPONSE — not just on screen, since it is readable whatever draws it.
     *
     * Derived, not enumerated: the reader's permissions are every registered
     * glance's, so a glance added next month is in this response, and the
     * needles are searched in the raw body under any key. Only a branch the
     * fixture reaches is covered; seed here when a glance grows money.
     */
    public function test_no_glance_sends_money_without_financials(): void
    {
        $job = $this->seedMoney();
        $permissions = collect(app(GlanceRegistry::class)->all())
            ->map(fn (Glance $g) => $g->permission())->unique()->values()->all();
        $reader = $this->userWithPermissions($permissions, 'no-money@alpha.test');
        $this->assertFalse($this->inTenant(fn () => $reader->hasPermission('jobs.financials')));
        $this->assertFalse($this->inTenant(fn () => $reader->hasPermission('inventory.financials')));

        // Control: the money exists and somebody may read it elsewhere.
        $this->assertStringContainsString(
            '987654',
            $this->as($this->alpha['admin'])->getJson("/api/v1/jobs/{$job->id}")->assertOk()->getContent(),
        );

        $response = $this->as($reader)->getJson('/api/v1/dashboard')->assertOk();
        $glances = collect($response->json('glances'));
        $this->assertSame(1, array_sum(array_column($glances->firstWhere('key', 'jobs')['data']['slices'], 'total')), 'the job is on the dashboard');
        $this->assertFalse($glances->firstWhere('key', 'inventory')['data']['empty'], 'the stock is on the dashboard');

        $body = $response->getContent();
        foreach (['987654', '9876.54', '12963', '129.63', '"currency"', 'amount_minor', '"revenue"', 'stock_value'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    /** The pair: with the permission the amount arrives — as a figure, and nowhere else. */
    public function test_a_financials_holder_gets_money_only_as_a_figure(): void
    {
        $this->seedMoney();

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        $this->assertContains(
            ['key' => 'stock_value', 'label' => 'stock value', 'value' => 12963, 'previous' => null, 'currency' => 'USD'],
            $glances['inventory']['data']['figures'],
        );
        foreach ($glances as $key => $glance) {
            $words = json_encode([
                $glance['data']['sentences'] ?? [], $glance['data']['sentence'] ?? null,
                $glance['data']['notes'] ?? [], $glance['data']['attention'] ?? [],
            ]);
            $this->assertStringNotContainsString('12963', $words, $key);
            $this->assertStringNotContainsString('129.63', $words, $key);
        }
    }

    /** An earned job worth 9,876.54 and stock worth 3 x 43.21 = 129.63. */
    private function seedMoney(): Job
    {
        $this->earnedStatuses(['completed']);
        $job = $this->job($this->alpha['franchise'], ['amount_minor' => 987654, 'currency' => 'USD', 'status' => 'completed'], earned: true);

        // Without stock the Inventory box takes its "No items" branch and
        // never reaches its money line: the guard once stayed green with
        // stock value put back because of exactly that.
        $this->inTenant(function () {
            $item = InventoryItem::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
                'last_cost_minor' => 4321,
            ]);
            $location = InventoryLocation::factory()->create([
                'company_id' => $this->alpha['company']->id,
                'franchise_id' => $this->alpha['franchise']->id,
            ]);
            InventoryBalance::create(['item_id' => $item->id, 'location_id' => $location->id, 'qty_on_hand' => '3.000']);
        });

        return $job;
    }

    // ── catalogue ────────────────────────────────────────────────

    public function test_the_catalogue_lists_glances_not_widgets(): void
    {
        $body = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard/catalogue')
            ->assertOk()->json();

        $this->assertArrayNotHasKey('widgets', $body);
        $this->assertArrayHasKey('glances', $body);
        $keys = array_column($body['glances'], 'key');
        $this->assertContains('jobs', $keys);
        $this->assertContains('inventory', $keys);
        $this->assertNotContains('jobs.by_status', $keys);
        $first = $body['glances'][0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('allowed_presentations', $first);
        $this->assertArrayHasKey('default_presentation', $first);
    }

    public function test_the_catalogue_hides_glances_you_have_no_permission_for(): void
    {
        $limited = $this->userWithPermissions(['jobs.view']);

        $keys = array_column(
            $this->as($limited)->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('glances'),
            'key',
        );

        $this->assertContains('jobs', $keys);
        $this->assertNotContains('customers', $keys);
        $this->assertNotContains('automations', $keys);
    }

    // ── layout ───────────────────────────────────────────────────

    public function test_omitting_a_reachable_glance_appends_it(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
        ])->assertOk();
        $keys = array_column($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'), 'key');
        $this->assertSame('jobs', $keys[0]);
        $this->assertContains('hr', $keys);
        $this->assertContains('inventory', $keys);
    }

    public function test_old_dashboard_widgets_do_not_500(): void
    {
        $this->inTenant(fn () => User::withoutGlobalScopes()->find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.widgets' => [
                ['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed']]],
            ]]])->save());
        $jobs = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances'))
            ->firstWhere('key', 'jobs');
        $this->assertSame([], $jobs['options']);
    }

    public function test_surface_on_a_layout_save_is_ignored(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
        ])->assertOk();
    }

    public function test_an_unknown_surface_on_a_layout_save_is_not_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'somewhere_else',
            'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
        ])->assertOk();
    }

    public function test_naming_an_unreachable_glance_stores_it_but_does_not_draw_it(): void
    {
        $limited = $this->userWithPermissions(['jobs.view']);

        // Omitting is append; naming customers without permission stores the
        // key so a brief loss does not forget the place. It does not 422.
        $this->as($limited)->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie'],
                ['key' => 'customers', 'presentation' => 'line'],
            ],
        ])->assertOk();

        $drawn = array_column($this->glancesOf($limited), 'key');
        $this->assertContains('jobs', $drawn);
        $this->assertNotContains('customers', $drawn);

        $this->assertContains(
            'customers',
            array_column($this->preferencesOf($limited)['dashboard.glances'], 'key'),
        );
    }

    public function test_a_chosen_presentation_is_honoured(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'bar']],
        ])->assertOk();

        $jobs = collect($this->glancesOf($this->alpha['admin']))->firstWhere('key', 'jobs');
        $this->assertSame('bar', $jobs['presentation']);
    }

    public function test_a_presentation_not_offered_is_dropped_to_the_default(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'timeline']],
        ])->assertOk();

        $jobs = collect($this->glancesOf($this->alpha['admin']))->firstWhere('key', 'jobs');
        $this->assertSame('pie', $jobs['presentation']);
    }

    public function test_a_layout_survives_losing_the_permission_it_was_built_with(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie'],
                ['key' => 'customers', 'presentation' => 'line'],
            ],
        ])->assertOk();

        $this->inTenant(function () {
            $role = Role::find($this->alpha['admin']->role_id);

            // `is_administrator` is what demotes it. Renaming the role used to
            // do that on its own, which was the bug the 2026_08_10_010000
            // migration closed — a tenant who renamed Administrator lost every
            // permission it held. This test wants a genuinely narrowed role, so
            // it says so explicitly instead of relying on that accident.
            $role->forceFill([
                'is_system' => false, 'is_administrator' => false, 'name' => 'Jobs only',
            ])->save();
            $role->permissions()->sync(Permission::whereIn('key', ['jobs.view'])->pluck('id'));
        });

        $glances = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('glances');

        // A layout outlives the permission that allowed it. Refusing to load the
        // whole dashboard because one saved glance is now off-limits punishes
        // somebody for an administrator's decision.
        $this->assertSame(['jobs'], array_column($glances, 'key'));
        $this->assertContains(
            'customers',
            array_column($this->preferencesOf($this->alpha['admin'])['dashboard.glances'], 'key'),
        );
    }

    /**
     * §2.1 category 6. Not a tenant setting and not an organization setting:
     * nobody arranges somebody else's dashboard, and two people sharing a role
     * must be able to disagree about where Jobs sits.
     */
    public function test_one_persons_order_is_not_another_persons(): void
    {
        [, $other] = $this->secondBranch();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
        ])->assertOk();

        $this->assertArrayNotHasKey('dashboard.glances', $this->preferencesOf($other));

        $this->as($other)->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'hr', 'presentation' => 'bar']],
        ])->assertOk();

        $this->assertSame(
            'jobs',
            $this->preferencesOf($this->alpha['admin'])['dashboard.glances'][0]['key'],
        );
        $this->assertSame(
            'hr',
            $this->preferencesOf($other)['dashboard.glances'][0]['key'],
        );
    }

    /**
     * `users.preferences` is one blob shared with everything else somebody has
     * chosen. An order that overwrote it would take their theme with it.
     */
    public function test_saving_an_order_leaves_every_other_preference_alone(): void
    {
        $this->as($this->alpha['admin'])->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'pie']],
        ])->assertOk();

        $this->assertSame(
            'dark',
            $this->preferencesOf($this->alpha['admin'])['appearance.theme'],
        );
    }

    public function test_a_key_stored_twice_would_draw_one_card_not_two(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie'],
                ['key' => 'jobs', 'presentation' => 'bar'],
                ['key' => 'hr', 'presentation' => 'bar'],
            ],
        ])->assertOk();

        $stored = array_column($this->preferencesOf($this->alpha['admin'])['dashboard.glances'], 'key');
        $this->assertSame('jobs', $stored[0]);
        $this->assertSame('hr', $stored[1]);
        $this->assertSame(1, count(array_filter($stored, fn ($key) => $key === 'jobs')));
    }

    public function test_somebody_who_has_never_configured_it_still_gets_a_dashboard(): void
    {
        $keys = array_column(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances'),
            'key',
        );

        // A new organization's first sight of the product should not be a blank
        // screen with a "configure me" button. Default is every reachable tab,
        // sidebar order.
        $this->assertSame(GlanceRegistry::ORDER, $keys);
    }

    public function test_saving_only_the_period_does_not_replace_glances(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'presentation' => 'bar', 'options' => [
                'earned_statuses' => ['completed'],
            ]]],
        ])->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'period' => 'week',
        ])->assertOk();

        $stored = collect($this->preferencesOf($this->alpha['admin'])['dashboard.glances'])
            ->firstWhere('key', 'jobs');
        $this->assertSame('bar', $stored['presentation']);
        $this->assertArrayNotHasKey('options', $stored, 'no glance takes options, so none are stored');
        $this->assertSame('week', $this->preferencesOf($this->alpha['admin'])['dashboard.period']);
    }

    /** Nobody chose yet: today (Jose, 2026-10-01), in every sentence too. */
    public function test_with_no_choice_the_dashboard_is_today(): void
    {
        $payload = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json();

        $this->assertSame('day', $payload['period']);
        $hr = collect($payload['glances'])->firstWhere('key', 'hr');
        if ($hr !== null) {
            $words = implode(' ', $hr['data']['sentences'] ?? [$hr['data']['sentence'] ?? '']);
            $this->assertStringNotContainsString('this day', $words);
        }
    }

    public function test_the_dashboard_echoes_the_chosen_period(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'period' => 'week',
            'glances' => [],
        ])->assertOk();

        $payload = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('week', $payload['period']);
        $this->assertNotEmpty($payload['period_starts_on']);
        $this->assertNotEmpty($payload['period_ends_on']);
    }

    public function test_snapshot_glances_do_not_change_with_period(): void
    {
        $this->job($this->alpha['franchise']);
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', ['period' => 'week', 'glances' => []]);
        $week = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'))->firstWhere('key', 'jobs')['data']['slices'];
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', ['period' => 'year', 'glances' => []]);
        $year = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->json('glances'))->firstWhere('key', 'jobs')['data']['slices'];
        $this->assertSame($week, $year);
    }

    public function test_job_statuses_come_from_the_organizations_own_vocabulary(): void
    {
        $this->as($this->alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='
                .$this->alpha['org']->id, ['value' => ['booked', 'on site', 'invoiced']])
            ->assertOk();

        $this->job($this->alpha['franchise'], ['status' => 'booked']);

        $slices = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances')
        )->firstWhere('key', 'jobs')['data']['slices'];

        // A business that renamed its statuses would otherwise see a dashboard
        // using somebody else's words — and empty columns for statuses it does
        // not use.
        $this->assertSame(['booked', 'on site', 'invoiced'], array_column($slices, 'label'));
    }

    public function test_every_configured_status_appears_even_with_nothing_in_it(): void
    {
        $this->job($this->alpha['franchise'], ['status' => 'scheduled']);

        $slices = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances')
        )->firstWhere('key', 'jobs')['data']['slices'];

        // A board that hides empty columns reorders itself as work moves, which
        // is exactly when somebody is looking at it.
        $this->assertGreaterThan(1, count($slices));
        $this->assertContains(0, array_column($slices, 'total'));
    }

    public function test_the_dashboard_still_loads_when_the_subscription_has_lapsed(): void
    {
        $this->alpha['org']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        SubscriptionGuard::flush();

        // §13: the owner may still sign in and view everything. A dashboard is
        // the most-visited read in the product.
        $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk();
    }

    /**
     * Stand a registry in the container whose available glances are exactly
     * these, so a test can put a glance that throws beside one that does not.
     *
     * @param  Glance[]  $glances
     */
    private function registryOf(array $glances): void
    {
        $this->app->instance(GlanceRegistry::class, new class($glances, app(ModuleAvailability::class)) extends GlanceRegistry
        {
            /** @param  Glance[]  $glances */
            public function __construct(private array $only, ModuleAvailability $availability)
            {
                parent::__construct($availability);
            }

            public function availableTo(User $user): array
            {
                return $this->only;
            }
        });
    }

    /**
     * A glance whose resolve() throws, as one does when its table is not
     * migrated yet — which is how UI/UX met this, live, on 2026-09-28.
     */
    private function brokenGlance(): Glance
    {
        return new class implements Glance
        {
            public function key(): string { return 'broken'; }
            public function label(): string { return 'Broken Module'; }
            public function permission(): string { return 'dashboard.view'; }
            public function allowedPresentations(): array { return ['pie', 'text']; }
            public function defaultPresentation(): string { return 'pie'; }
            public function snapshot(): bool { return true; }
            public function defaultSize(): string { return 'medium'; }

            public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
            {
                throw new RuntimeException("SQLSTATE[42S02]: Base table or view not found: 'ads_account_states'");
            }
        };
    }

    /**
     * ONE GLANCE MUST NOT TAKE THE DASHBOARD DOWN.
     *
     * Before 2026-09-28 a throw in any single resolve() became a 500 for the
     * whole endpoint, and every card vanished behind one error. The broken
     * module is rarely the one the reader came to see.
     */
    public function test_one_failing_glance_leaves_every_other_box_standing(): void
    {
        Exceptions::fake();
        $this->registryOf([
            new ComingSoonGlance('newsletter', 'Newsletter', 'A healthy box.'),
            $this->brokenGlance(),
        ]);

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        // The healthy box is untouched — the control, without which "200"
        // could mean the failure was swallowed along with everything else.
        $this->assertSame('A healthy box.', $glances['newsletter']['data']['sentence']);

        // The broken one is still THERE, saying what happened, with no chart
        // menu on a box that has nothing to chart.
        $broken = $glances['broken'];
        $this->assertSame('Broken Module', $broken['label']);
        $this->assertTrue($broken['data']['empty']);
        $this->assertSame('This panel could not load right now.', $broken['data']['sentence']);
        $this->assertSame([], $broken['allowed_presentations']);
        $this->assertFalse($broken['coming_soon']);
        $this->assertTrue($broken['failed']);
        $this->assertArrayNotHasKey('failed', $glances['newsletter'], 'only the broken box is marked');

        // Not silent to us either.
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'ads_account_states'));
    }

    /**
     * THE FAILED PANEL MUST NOT ERASE WHAT THIS PERSON CHOSE.
     *
     * The client echoes `presentation` and `options` straight back on the next
     * reorder and `saveLayout` stores them. A failed panel that came back as
     * `text` would, the moment somebody dragged any card during an outage,
     * overwrite their chart choice for that box — permanently, over a fault
     * they did not cause and cannot see. So the person's own value wins even
     * on a panel that could not be computed.
     */
    public function test_a_failed_panel_keeps_the_readers_own_chart_choice(): void
    {
        Exceptions::fake();
        $this->registryOf([$this->brokenGlance()]);

        $this->inTenant(fn () => User::find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.glances' => [
                ['key' => 'broken', 'presentation' => 'pie', 'size' => 'large'],
            ]]])->save());

        $broken = collect($this->glancesOf($this->alpha['admin']))->firstWhere('key', 'broken');

        $this->assertSame('pie', $broken['presentation']);
        $this->assertSame('large', $broken['size']);
    }

    /**
     * Every glance that sends figures or attention rows sends the shape the
     * sized dashboard reads. Derived: the administrator reaches every glance,
     * so a module that adds figures next month is checked without anybody
     * listing it here. Jobs is the control — seeded so its figure is known,
     * and with a previous value different from its value.
     */
    public function test_every_figure_and_attention_row_keeps_the_contract(): void
    {
        // Evening in the franchise's zone and already tomorrow in UTC: the
        // window this failed in at the gate every night, 00:00-04:00 UTC.
        // scheduled_for is a franchise WALL time (FranchiseWallTime), so the
        // fixtures are wall times there; seeding now() in UTC put "today" on
        // tomorrow's date and yesterday's jobs on today's.
        $this->travelTo(Carbon::parse('2026-10-06 01:30:00', 'UTC'));
        $wall = now($this->alpha['franchise']->timezone ?: 'UTC');
        $this->job($this->alpha['franchise'], ['scheduled_for' => $wall->copy()]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => $wall->copy()->subDay()]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => $wall->copy()->subDay()]);

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        $this->assertSame(
            ['key' => 'today', 'label' => 'today', 'value' => 1, 'previous' => 2],
            $glances['jobs']['data']['figures'][0],
        );

        foreach ($glances as $key => $glance) {
            foreach ($glance['data']['figures'] ?? [] as $figure) {
                $this->assertSame(['key', 'label', 'value', 'previous'], array_slice(array_keys($figure), 0, 4), $key);
                $this->assertTrue(count($figure) === 4 || array_keys($figure)[4] === 'currency', $key);
                $this->assertIsString($figure['key'], $key);
                $this->assertNotSame('', trim($figure['label']), $key);
                $this->assertIsInt($figure['value'], $key);
                $this->assertTrue($figure['previous'] === null || is_int($figure['previous']), $key);
            }
            $attention = $glance['data']['attention'] ?? [];
            $this->assertLessThanOrEqual(3, count($attention), $key);
            foreach ($attention as $row) {
                $this->assertContains($row['tone'], ['caution', 'bad'], $key);
                $this->assertNotSame('', trim($row['text']), $key);
            }
        }
    }

    /**
     * `notes` are the sentences that are not a figure: the sized client draws
     * figures + notes, so a line that repeats a figure must not be a note,
     * and a line that is not one must not be lost.
     */
    public function test_notes_are_the_sentences_that_are_not_figures(): void
    {
        // Same evening window and wall-time fixtures as the contract test.
        $this->travelTo(Carbon::parse('2026-10-06 01:30:00', 'UTC'));
        $wall = now($this->alpha['franchise']->timezone ?: 'UTC');
        $this->job($this->alpha['franchise'], ['scheduled_for' => $wall->copy()]);
        $this->job($this->alpha['franchise'], ['scheduled_for' => $wall->copy()->addDays(2)]);

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        // Jobs says exactly its two figures ("1 job today.", "... coming up.").
        $this->assertNotEmpty($glances['jobs']['data']['sentences']);
        $this->assertSame([], $glances['jobs']['data']['notes']);

        // HR says more than its figures: those lines stay, the figure lines go.
        $hr = $glances['hr']['data'];
        $this->assertContains('0 onboardings in flight.', $hr['notes']);
        $this->assertNotContains('1 person on the books.', $hr['notes']);
        $this->assertContains('1 person on the books.', $hr['sentences'], 'old builds keep every sentence');
    }

    /** Customize: a hidden box is listed for "Add a box" and not computed. */
    public function test_a_hidden_box_is_listed_without_being_resolved(): void
    {
        Exceptions::fake();
        $this->registryOf([
            new ComingSoonGlance('newsletter', 'Newsletter', 'Soon.'),
            $this->brokenGlance(),
        ]);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'broken', 'hidden' => true, 'size' => 'large'],
                ['key' => 'newsletter'],
            ],
        ])->assertOk();

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        $this->assertSame(
            ['key' => 'broken', 'label' => 'Broken Module', 'hidden' => true, 'size' => 'large', 'presentation' => 'pie'],
            $glances['broken'],
        );
        // The broken glance throws when resolved; nothing reported means it
        // never ran. The control: the visible box did.
        Exceptions::assertNothingReported();
        $this->assertFalse($glances['newsletter']['hidden']);
        $this->assertSame('Soon.', $glances['newsletter']['data']['sentence']);

        // Shown again by saying so; leaving the key out keeps it hidden.
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'broken', 'hidden' => false], ['key' => 'newsletter']],
        ])->assertOk();
        $this->assertFalse(collect($this->glancesOf($this->alpha['admin']))->firstWhere('key', 'broken')['hidden']);
    }

    // ── templates (Jose, 2026-10-05: "recommended layouts … a user can apply") ──

    /**
     * Templates are built for the reader's reach: a box they cannot open is
     * never named, and a template left with fewer than two boxes is not
     * offered. Two keys reachable, so only the template naming both survives.
     */
    public function test_templates_follow_the_readers_reach(): void
    {
        $limited = $this->userWithPermissions(['hr.view', 'jobs.view']);

        $templates = $this->as($limited)->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('templates');

        $this->assertSame(['owner'], array_column($templates, 'key'));
        $this->assertSame([
            ['key' => 'jobs', 'size' => 'large', 'presentation' => 'pie', 'hidden' => false],
            ['key' => 'hr', 'size' => 'small', 'hidden' => false],
        ], $templates[0]['glances']);
    }

    /**
     * Applying a template is a plain layout save: the named boxes in order,
     * sized and drawn as the template says (Jose, 2026-10-07: "order and size
     * of widgets and modes"), the rest hidden so "Add a box" can bring them
     * back. A Small box gets no mode from the template, so its stored one
     * stays.
     */
    public function test_applying_a_template_sizes_orders_hides_and_sets_modes(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie'],
                ['key' => 'customers', 'presentation' => 'bar'],
            ],
        ])->assertOk();

        $office = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard/catalogue')->json('templates'))
            ->firstWhere('key', 'office');
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', ['glances' => $office['glances']])->assertOk();

        $glances = collect($this->glancesOf($this->alpha['admin']));
        $this->assertSame('jobs', $glances->first()['key'], 'the template order');
        $jobs = $glances->firstWhere('key', 'jobs');
        $this->assertSame('large', $jobs['size']);
        $this->assertSame('bar', $jobs['presentation'], 'Office draws jobs as bars, over the pie chosen before');
        $customers = $glances->firstWhere('key', 'customers');
        $this->assertSame('small', $customers['size']);
        $this->assertSame('bar', $customers['presentation'], 'small in Office, so the chart chosen before stays');
        $this->assertTrue($glances->firstWhere('key', 'hr')['hidden'], 'not in Office, so hidden');
        $this->assertFalse($glances->firstWhere('key', 'phone')['hidden']);
    }

    // ── size (Jose, 2026-10-05: "change the widget sizes") ─────────────

    public function test_a_box_is_its_glances_default_size_until_somebody_picks_one(): void
    {
        $this->registryOf([
            new ComingSoonGlance('newsletter', 'Newsletter', 'Soon.'),
            $this->brokenGlance(),
        ]);
        Exceptions::fake();

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');

        // Two different defaults, so a hard-coded size cannot pass.
        $this->assertSame('small', $glances['newsletter']['size']);
        $this->assertSame('medium', $glances['broken']['size']);
    }

    /**
     * Builds that predate sizes (and the HR tab's chip) save {key,
     * presentation} on a reorder. That must not undo a size or a hide made on
     * a newer build: an absent key is "unchanged".
     */
    public function test_a_save_without_size_or_hidden_keeps_the_stored_ones(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie', 'size' => 'large'],
                ['key' => 'hr', 'hidden' => true],
            ],
        ])->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'hr', 'presentation' => 'bar'],
                ['key' => 'jobs', 'presentation' => 'bar'],
            ],
        ])->assertOk();

        $stored = collect($this->preferencesOf($this->alpha['admin'])['dashboard.glances'])->keyBy('key');
        $this->assertSame('large', $stored['jobs']['size']);
        $this->assertSame('bar', $stored['jobs']['presentation'], 'the change that WAS sent still lands');
        $this->assertTrue($stored['hr']['hidden']);

        // And a present value still replaces: shown again, sized down.
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'hr', 'hidden' => false], ['key' => 'jobs', 'size' => 'small']],
        ])->assertOk();
        $stored = collect($this->preferencesOf($this->alpha['admin'])['dashboard.glances'])->keyBy('key');
        $this->assertArrayNotHasKey('hidden', $stored['hr']);
        $this->assertSame('small', $stored['jobs']['size']);
    }

    public function test_a_chosen_size_is_stored_and_returned_and_an_unknown_one_falls_back(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [
                ['key' => 'jobs', 'presentation' => 'pie', 'size' => 'large'],
                ['key' => 'hr', 'size' => 'enormous'],
            ],
        ])->assertOk();

        $stored = collect($this->preferencesOf($this->alpha['admin'])['dashboard.glances'])->keyBy('key');
        $this->assertSame('large', $stored['jobs']['size']);

        $glances = collect($this->glancesOf($this->alpha['admin']))->keyBy('key');
        $this->assertSame('large', $glances['jobs']['size']);
        $this->assertSame(app(\App\Support\Dashboard\Glances\HrGlance::class)->defaultSize(), $glances['hr']['size']);
        $this->assertNotSame('enormous', $stored['hr']['size']);
    }
}
