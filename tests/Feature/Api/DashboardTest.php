<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\GlanceRegistry;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
                'role_id' => $this->alpha['admin']->role_id,
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

    private function job(Franchise $franchise, array $attributes = []): Job
    {
        return $this->inTenant(fn () => Job::create($attributes + [
            'franchise_id' => $franchise->id,
            'customer_id' => $this->customer($franchise)->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
        ]));
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
            'role_id' => $this->alpha['admin']->role_id,
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

    public function test_currencies_are_never_added_together(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'CAD', 'status' => 'completed']);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'options' => ['earned_statuses' => ['completed']]]],
        ])->assertOk();

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances')
        )->firstWhere('key', 'jobs')['data']['revenue'];

        // Two rows, not one total. Adding 100 USD to 200 CAD gives a number that
        // is wrong in a way nobody notices until a client reconciles it.
        $this->assertCount(2, $rows);
        $this->assertSame(['CAD', 'USD'], array_column($rows, 'currency'));
        $this->assertSame([20000, 10000], array_column($rows, 'amount_minor'));
    }

    public function test_revenue_counts_nothing_until_the_statuses_are_chosen(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 50000, 'status' => 'cancelled']);

        $data = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances')
        )->firstWhere('key', 'jobs')['data'];

        // Counting everything by default would put cancelled work in a revenue
        // figure. A blank number somebody has to configure is better than a
        // confident wrong one.
        $this->assertContains(
            'Choose which of your job statuses count as earned.',
            $data['sentences'] ?? [],
        );
        $this->assertArrayNotHasKey('revenue', $data);
    }

    public function test_amounts_stay_in_minor_units(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 12345, 'status' => 'completed']);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'options' => ['earned_statuses' => ['completed']]]],
        ])->assertOk();

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances')
        )->firstWhere('key', 'jobs')['data']['revenue'];

        // Dividing by 100 on the server assumes two decimal places, which is
        // wrong for JPY and wrong for the day this sells abroad.
        $this->assertSame(12345, $rows[0]['amount_minor']);
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

    public function test_old_dashboard_widgets_do_not_500_and_copy_earned_statuses(): void
    {
        $this->inTenant(fn () => User::withoutGlobalScopes()->find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.widgets' => [
                ['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed']]],
            ]]])->save());
        $jobs = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances'))
            ->firstWhere('key', 'jobs');
        $this->assertSame(['completed'], $jobs['options']['earned_statuses'] ?? null);
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

    public function test_a_choice_no_longer_offered_is_dropped_rather_than_refusing_the_save(): void
    {
        $this->as($this->alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='
                .$this->alpha['org']->id, ['value' => ['booked', 'invoiced']])
            ->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'glances' => [['key' => 'jobs', 'options' => [
                'earned_statuses' => ['completed', 'invoiced'],
            ]]],
        ])->assertOk();

        $stored = collect($this->preferencesOf($this->alpha['admin'])['dashboard.glances'])
            ->firstWhere('key', 'jobs');
        $this->assertSame(['invoiced'], $stored['options']['earned_statuses']);
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
        $this->assertSame(['completed'], $stored['options']['earned_statuses']);
        $this->assertSame('week', $this->preferencesOf($this->alpha['admin'])['dashboard.period']);
    }

    public function test_a_period_save_does_not_drop_earned_statuses_copied_from_widgets(): void
    {
        $this->inTenant(fn () => User::withoutGlobalScopes()->find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.widgets' => [
                ['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed']]],
            ]]])->save());

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'period' => 'year',
        ])->assertOk();

        $this->assertArrayNotHasKey(
            'dashboard.glances',
            $this->preferencesOf($this->alpha['admin']),
        );

        $jobs = collect($this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('glances'))
            ->firstWhere('key', 'jobs');
        $this->assertSame(['completed'], $jobs['options']['earned_statuses'] ?? null);
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
}
