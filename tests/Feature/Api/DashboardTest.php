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
        $this->job($this->alpha['franchise'], ['amount_minor' => 10000, 'currency' => 'USD', 'status' => 'done']);
        $this->job($this->alpha['franchise'], ['amount_minor' => 20000, 'currency' => 'CAD', 'status' => 'done']);

        $this->inTenant(fn () => Job::query()->update(['status' => 'done']));

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => ['statuses' => ['done'], 'days' => 30]]],
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
        $this->job($this->alpha['franchise'], ['amount_minor' => 12345, 'status' => 'done']);

        $this->as($this->alpha['admin'])->putJson('/api/v1/dashboard/layout', [
            'widgets' => [['key' => 'jobs.revenue', 'options' => ['statuses' => ['done']]]],
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
                'name' => 'Jobs only', 'is_administrator' => false,
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
        $this->assertNotContains('workflows.health', $keys);
    }

    public function test_a_layout_cannot_name_a_widget_you_cannot_see(): void
    {
        $limited = $this->inTenant(function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Jobs only', 'is_administrator' => false,
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
            $role->forceFill(['is_administrator' => false])->save();
            $role->permissions()->sync(Permission::whereIn('key', ['jobs.view'])->pluck('id'));
        });

        $panels = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        // A layout outlives the permission that allowed it. Refusing to load the
        // whole dashboard because one saved widget is now off-limits punishes
        // somebody for an administrator's decision.
        $this->assertSame(['jobs.by_status'], array_column($panels, 'key'));
    }

    public function test_somebody_who_has_never_configured_it_still_gets_a_dashboard(): void
    {
        $panels = $this->as($this->alpha['admin'])->getJson('/api/v1/dashboard')
            ->assertOk()->json('panels');

        // A new organization's first sight of the product should not be a blank
        // screen with a "configure me" button.
        $this->assertNotEmpty($panels);
    }

    public function test_job_statuses_come_from_the_organizations_own_vocabulary(): void
    {
        $this->as($this->alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.statuses', ['value' => ['booked', 'on site', 'invoiced']])
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
