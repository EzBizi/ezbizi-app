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
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard (plan §18 Phase 4).
 *
 * The plan says dashboards come AFTER modules produce data worth showing, and
 * warns that "a page of empty widgets teaches you nothing about whether the
 * foundation works". So this file tests the two things a dashboard gets wrong
 * that a list screen does not: showing somebody numbers from outside their
 * reach, and adding up money that should not be added up.
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
        // an empty permission set and every widget looks correctly hidden.
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
                'company_id'      => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
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
            'customer_id'  => $this->customer($franchise)->id,
            'number' => 'J-'.bin2hex(random_bytes(3)),
            'title' => 'Work', 'status' => 'scheduled', 'currency' => 'USD',
        ]));
    }

    // ── reach ────────────────────────────────────────────────────

    public function test_a_branch_user_sees_only_their_own_franchises_jobs(): void
    {
        [$second, $branchUser] = $this->secondBranch();

        $this->job($this->alpha['franchise']);
        $this->job($this->alpha['franchise']);
        $this->job($second);

        $panels = $this->as($branchUser)->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        $byStatus = collect($panels)->firstWhere('key', 'jobs.by_status');

        // A franchise manager's dashboard showing organization-wide totals is a
        // data leak that looks like a feature — nobody reports a number that is
        // too big as a bug.
        $this->assertSame(1, $byStatus['data']['total']);
    }

    public function test_the_administrator_sees_everything_they_reach(): void
    {
        [$second] = $this->secondBranch();

        $this->job($this->alpha['franchise']);
        $this->job($second);

        $panels = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        $this->assertSame(2, collect($panels)->firstWhere('key', 'jobs.by_status')['data']['total']);
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

        $panels = $this->as($stranger)->getJson('/api/v1/dashboard')->assertOk()->json('panels');

        // `whereIn` on an empty list matches nothing. Writing it as a condition
        // that skips the clause when the list is empty would invert this into
        // "no assignments means see everything".
        $this->assertSame(0, collect($panels)->firstWhere('key', 'jobs.by_status')['data']['total']);
    }

    // ── money ────────────────────────────────────────────────────

    public function test_currencies_are_never_added_together(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'completed']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'CAD', 'status' => 'completed']);

        $this->inTenant(fn () => Job::query()->update(['status' => 'completed']));

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed'], 'days' => 30]]],
        ])->assertOk();

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'jobs.revenue')['data']['rows'];

        // Two rows, not one total. Adding 100 USD to 200 CAD gives a number that
        // is wrong in a way nobody notices until a client reconciles it.
        $this->assertCount(2, $rows);
        $this->assertSame(['CAD', 'USD'], array_column($rows, 'currency'));
        $this->assertSame([20000, 10000], array_column($rows, 'amount_minor'));
    }

    public function test_revenue_counts_nothing_until_the_statuses_are_chosen(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 50000, 'status' => 'cancelled']);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => []]],
        ])->assertOk();

        $data = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'jobs.revenue')['data'];

        // Counting everything by default would put cancelled work in a revenue
        // figure. A blank number somebody has to configure is better than a
        // confident wrong one.
        $this->assertTrue($data['needs_configuring']);
        $this->assertSame([], $data['rows']);
    }

    public function test_amounts_stay_in_minor_units(): void
    {
        $this->job($this->alpha['franchise'], ['amount_minor' => 12345, 'status' => 'completed']);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => ['statuses' => ['completed']]]],
        ])->assertOk();

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'jobs.revenue')['data']['rows'];

        // Dividing by 100 on the server assumes two decimal places, which is
        // wrong for JPY and wrong for the day this sells abroad.
        $this->assertSame(12345, $rows[0]['amount_minor']);
    }

    // ── the registry and the layout ──────────────────────────────

    public function test_the_catalogue_hides_widgets_you_have_no_permission_for(): void
    {
        $limited = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Jobs only', 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', ['jobs.view'])->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });

        $keys = array_column(
            $this->as($limited)->getJson('/api/v1/dashboard/catalogue')->assertOk()->json('widgets'),
            'key',
        );

        $this->assertContains('jobs.by_status', $keys);
        $this->assertNotContains('customers.added', $keys);
        $this->assertNotContains('automations.health', $keys);
    }

    public function test_a_layout_cannot_name_a_widget_you_cannot_see(): void
    {
        $limited = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Jobs only', 'is_system' => false,
            ]);

            $role->permissions()->sync(Permission::whereIn('key', ['jobs.view'])->pluck('id'));

            return User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);
        });

        // Otherwise the permission check lives only in the catalogue, and the
        // catalogue is a suggestion.
        $this->as($limited)->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'customers.added', 'options' => []]],
        ])->assertStatus(422);
    }

    public function test_undeclared_options_are_discarded_rather_than_stored(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [[
                'key' => 'jobs.upcoming',
                'options' => ['limit' => 5, 'sneaky' => 'DROP TABLE jobs'],
            ]],
        ])->assertOk()->assertJsonPath('widgets.0.options', ['limit' => 5]);

        // A stored preference is user input that happens to have been saved
        // earlier, and it is handed back to resolve() on every later request.
        $this->assertSame(
            ['limit' => 5],
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['dashboard.widgets'][0]['options'],
        );
    }

    /**
     * A widget publishes the values it can be asked for. Those are the only
     * values that may be stored.
     *
     * Filtering by option NAME was the whole of this, and it let the value
     * through untouched. `days` is declared as one of 7, 30, 90 or 365; nothing
     * offers 1, and a stored 1 comes back to resolve() on every later request
     * and renders as "Joined in the last 1 days".
     */
    public function test_a_value_the_widget_never_offered_is_not_stored(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'hr.starters_and_leavers', 'options' => ['days' => 1]]],
        ])->assertOk()->assertJsonPath('widgets.0.options', []);

        $this->assertSame(
            [],
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['dashboard.widgets'][0]['options'],
        );
    }

    /**
     * The half a write-time check cannot reach.
     *
     * Every layout saved before that check existed is still in the database,
     * and layoutFor() hands it straight to resolve(). The widget KEY has always
     * been re-checked on the way out for exactly that reason; the values were
     * not, so a `days` nobody may choose any more still drew the panel.
     */
    public function test_a_value_stored_before_the_check_is_not_handed_to_the_widget(): void
    {
        // Written straight into the preference — which is what a layout saved by
        // the previous version of this controller looks like.
        $this->inTenant(fn () => User::withoutGlobalScopes()
            ->find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.widgets' => [
                ['key' => 'hr.starters_and_leavers', 'options' => ['days' => 1]],
            ]]])->save());

        $panel = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'hr.starters_and_leavers');

        // The widget's own default, not the number somebody stored.
        $this->assertSame(30, $panel['data']['days']);

        // And echoed back cleaned, so the client's next save writes the
        // corrected layout rather than handing the 1 straight back in.
        $this->assertSame([], $panel['options']);
    }

    /**
     * Dropped, not refused — and this is the case that decides which.
     *
     * A choice list can be the organization's own data: jobs.revenue offers the
     * statuses that organization configured. The client sends back the options
     * it was handed, so a status renamed since would refuse the save of
     * somebody who had merely ticked a different panel, over a value they never
     * chose and cannot see. The stale member goes; the rest of the save stands.
     */
    public function test_a_choice_no_longer_offered_is_dropped_rather_than_refusing_the_save(): void
    {
        $this->as($this->alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='
                .$this->alpha['org']->id, ['value' => ['booked', 'invoiced']])
            ->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => [
                'statuses' => ['completed', 'invoiced'], 'days' => 30,
            ]]],
        ])
            ->assertOk()
            ->assertJsonPath('widgets.0.options.statuses', ['invoiced'])
            ->assertJsonPath('widgets.0.options.days', 30);
    }

    public function test_a_layout_survives_losing_the_permission_it_was_built_with(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [
                ['key' => 'jobs.by_status', 'options' => []],
                ['key' => 'customers.added', 'options' => []],
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

        $panels = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        // A layout outlives the permission that allowed it. Refusing to load the
        // whole dashboard because one saved widget is now off-limits punishes
        // somebody for an administrator's decision.
        $this->assertSame(['jobs.by_status'], array_column($panels, 'key'));
    }

    // ── the order of a dashboard that chooses nothing ────────────

    /**
     * The main dashboard's order is its layout — reordering it is a save of the
     * list somebody already had, which the tests above already exercise. The
     * dashboards whose panels are FIXED, Human Resources today, have an order
     * and no list to keep it in, and that is what these are about.
     */
    public function test_a_fixed_dashboards_order_is_kept_without_touching_the_layout(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.by_status', 'options' => []]],
        ])->assertOk();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [
                ['key' => 'hr.starters_and_leavers'],
                ['key' => 'hr.headcount'],
            ],
        ])->assertOk()->assertJsonPath('surface', 'hr');

        $preferences = $this->preferencesOf($this->alpha['admin']);

        $this->assertSame(
            ['hr.starters_and_leavers', 'hr.headcount'],
            $preferences['dashboard.order']['hr'],
        );

        // The two are different facts about different screens. Folding the HR
        // order into `dashboard.widgets` would have made arranging one tab
        // rewrite the other tab's chosen panels.
        $this->assertSame(
            ['jobs.by_status'],
            array_column($preferences['dashboard.widgets'], 'key'),
        );
    }

    /**
     * §2.1 category 6. Not a tenant setting and not an organization setting:
     * nobody arranges somebody else's dashboard, and two people sharing a role
     * must be able to disagree about where headcount sits.
     */
    public function test_one_persons_order_is_not_another_persons(): void
    {
        [, $other] = $this->secondBranch();

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [['key' => 'hr.headcount']],
        ])->assertOk();

        $this->assertArrayNotHasKey('dashboard.order', $this->preferencesOf($other));

        $this->as($other)->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [['key' => 'hr.invitations_stalled']],
        ])->assertOk();

        $this->assertSame(
            ['hr.headcount'],
            $this->preferencesOf($this->alpha['admin'])['dashboard.order']['hr'],
        );
    }

    /**
     * The main layout refuses a key nothing declares, because index() hands its
     * keys to resolve() and an unchecked one is a widget computed for somebody
     * who may not see it. A surface order resolves nothing — it says what
     * sequence to draw whatever the surface's own endpoint allowed — so it may
     * name a panel with no registry widget behind it at all.
     *
     * The unknown name used to be `hr.certifications`. That panel now has a
     * widget, so putting it on the main dashboard is a composition the registry
     * allows. The assertion still needs a key nothing declares, or it would pass
     * for the opposite reason the day the panel shipped.
     */
    public function test_a_surface_order_may_name_a_panel_the_registry_has_no_widget_for(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'hr.not_a_widget', 'options' => []]],
        ])->assertStatus(422);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [['key' => 'hr.not_a_widget'], ['key' => 'hr.headcount']],
        ])->assertOk();

        $this->assertSame(
            ['hr.not_a_widget', 'hr.headcount'],
            $this->preferencesOf($this->alpha['admin'])['dashboard.order']['hr'],
        );
    }

    /**
     * A surface name is a screen this app has, not anything a client cares to
     * send. Every one of these lands in `users.preferences`, which travels with
     * /me on every request; an open set would let a client grow that blob with
     * entries naming screens that will never exist and nothing to clear them.
     */
    public function test_an_order_for_a_dashboard_that_does_not_exist_is_refused(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'somewhere_else',
            'widgets' => [['key' => 'hr.headcount']],
        ])->assertStatus(422);
    }

    public function test_a_key_stored_twice_would_draw_one_card_not_two(): void
    {
        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [
                ['key' => 'hr.headcount'],
                ['key' => 'hr.headcount'],
                ['key' => 'hr.invitations_stalled'],
            ],
        ])->assertOk();

        $this->assertSame(
            ['hr.headcount', 'hr.invitations_stalled'],
            $this->preferencesOf($this->alpha['admin'])['dashboard.order']['hr'],
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
            'surface' => 'hr',
            'widgets' => [['key' => 'hr.headcount']],
        ])->assertOk();

        $this->assertSame(
            'dark',
            $this->preferencesOf($this->alpha['admin'])['appearance.theme'],
        );
    }

    /**
     * A stored preference is user input somebody saved earlier, and this one is
     * merged INTO rather than replaced. A value of the wrong shape — written by
     * an older version, or by hand — would otherwise be a 500 on a save nobody
     * could get past.
     */
    public function test_an_order_stored_in_the_wrong_shape_is_replaced_rather_than_merged_into(): void
    {
        $this->inTenant(fn () => User::find($this->alpha['admin']->id)
            ->forceFill(['preferences' => ['dashboard.order' => 'nonsense']])->save());

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'surface' => 'hr',
            'widgets' => [['key' => 'hr.headcount']],
        ])->assertOk();

        $this->assertSame(
            ['hr' => ['hr.headcount']],
            $this->preferencesOf($this->alpha['admin'])['dashboard.order'],
        );
    }

    public function test_somebody_who_has_never_configured_it_still_gets_a_dashboard(): void
    {
        $panels = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        // A new organization's first sight of the product should not be a blank
        // screen with a "configure me" button.
        $this->assertNotEmpty($panels);
    }

    /**
     * Registering a widget OFFERS it. It does not switch it on.
     *
     * The default layout was "everything you have permission for", which nobody
     * noticed while the registry held five widgets. Declaring HR's four in the
     * same registry then put four HR panels on the dashboard of every user in
     * every organization who had never opened the panel picker — an existing
     * screen rearranged by a registration rather than by a decision.
     *
     * Pinned here rather than in the HR file because it is not about HR: the
     * next module to add a widget repeats it otherwise.
     */
    public function test_a_newly_registered_widget_is_offered_rather_than_switched_on(): void
    {
        $catalogue = array_column(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard/catalogue')
                ->assertOk()->json('widgets'),
            'key',
        );

        $defaulted = array_column(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
                ->assertOk()->json('panels'),
            'key',
        );

        // Offered to an administrator...
        $this->assertContains('hr.headcount', $catalogue);
        $this->assertContains('hr.starters_and_leavers', $catalogue);

        // ...and not already on their dashboard. Starters and leavers matters
        // most: its leaving half is deliberately undated, and the sentences that
        // say so live on the HR tab. Turned on here unasked it reads as a
        // 30-day figure.
        $this->assertNotContains('hr.headcount', $defaulted);
        $this->assertNotContains('hr.starters_and_leavers', $defaulted);

        // Exactly what was there before HR existed, in the registry's order.
        $this->assertSame([
            'jobs.by_status', 'jobs.upcoming', 'jobs.revenue',
            'customers.added', 'automations.health',
        ], $defaulted);
    }

    /**
     * The default is a subset, so a role can hold none of it — and a blank first
     * screen is the one outcome the default exists to prevent.
     */
    public function test_a_role_the_default_set_does_not_reach_still_opens_on_something(): void
    {
        $hrOnly = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'People only', 'is_system' => false,
            ]);

            $role->permissions()->sync(Permission::whereIn('key', ['hr.view'])->pluck('id'));

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'People', 'email' => 'people@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });

        $keys = array_column(
            $this->as($hrOnly)->getJson('/api/v1/dashboard')->assertOk()->json('panels'),
            'key',
        );

        // Not one of the five defaults is theirs to see, so they fall back to
        // everything they CAN see rather than to nothing.
        $this->assertContains('hr.headcount', $keys);
    }

    public function test_job_statuses_come_from_the_organizations_own_vocabulary(): void
    {
        $this->as($this->alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.statuses?scope_type=organization&scope_id='
                .$this->alpha['org']->id, ['value' => ['booked', 'on site', 'invoiced']])
            ->assertOk();

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'jobs.by_status')['data']['rows'];

        // A business that renamed its statuses would otherwise see a dashboard
        // using somebody else's words — and empty columns for statuses it does
        // not use.
        $this->assertSame(['booked', 'on site', 'invoiced'], array_column($rows, 'status'));
    }

    public function test_every_configured_status_appears_even_with_nothing_in_it(): void
    {
        $this->job($this->alpha['franchise'], ['status' => 'scheduled']);

        $rows = collect(
            $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk()->json('panels')
        )->firstWhere('key', 'jobs.by_status')['data']['rows'];

        // A board that hides empty columns reorders itself as work moves, which
        // is exactly when somebody is looking at it.
        $this->assertGreaterThan(1, count($rows));
        $this->assertContains(0, array_column($rows, 'total'));
    }

    public function test_the_dashboard_still_loads_when_the_subscription_has_lapsed(): void
    {
        $this->alpha['org']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        \App\Support\Subscriptions\SubscriptionGuard::flush();

        // §13: the owner may still sign in and view everything. A dashboard is
        // the most-visited read in the product.
        $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')->assertOk();
    }
}
