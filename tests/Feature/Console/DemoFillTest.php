<?php

namespace Tests\Feature\Console;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleServiceVisit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `demo:fill` is the only command here written to run against the production
 * database on purpose, so the things worth pinning are the ones that would hurt:
 * that it refuses anything but the demo organization, and that running it twice
 * does not double the tenant's data.
 */
class DemoFillTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DEMO_ID = '01a03e4f-f9a1-7012-9515-96bc9260d2f8';

    private const DEMO_NAME = 'EzBizi Demo';

    /**
     * The demo tenant, built directly rather than through provisionTenant().
     *
     * The id is part of the guard, so the fixture has to hold it from the start.
     * Repointing a provisioned organization afterwards is not open to us: rows
     * already reference it and MySQL refuses the update outright — 1451, on
     * assignments_assigned_organization_id_foreign. So this creates only what
     * demo:fill reads: an organization, a company to own a franchise, a
     * franchise to own the areas, and two active people.
     *
     * @return array{org: Organization, company: Company, franchise: Franchise}
     */
    private function demoTenant(string $name = self::DEMO_NAME, bool $isDemo = true): array
    {
        // `id` is not fillable, so create() would drop it silently - and that
        // is not a small miss here, because the id is half of what demo:fill
        // checks. Set it on the instance instead.
        $org = new Organization([
            'name' => $name,
            'slug' => 'ezbizi-demo',
            'reporting_currency' => 'USD',
            'franchise_label' => 'Franchise',
            'is_active' => true,
            'is_demo' => $isDemo,
        ]);
        $org->id = self::DEMO_ID;
        $org->save();

        return app(TenantContext::class)->run($org, function () use ($org) {
            $company = Company::query()->create([
                'organization_id' => $org->id,
                'name' => 'Demo Co',
                'is_active' => true,
            ]);

            $franchise = Franchise::query()->create([
                'organization_id' => $org->id,
                'company_id' => $company->id,
                'name' => 'Demo — Orlando',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'is_active' => true,
            ]);

            foreach (['Ada Vance', 'Ivan Petrossian'] as $i => $person) {
                User::query()->create([
                    'organization_id' => $org->id,
                    'name' => $person,
                    'email' => 'demo'.$i.'@example.test',
                    'password' => 'a-real-password',
                    'is_active' => true,
                ]);
            }

            return ['org' => $org, 'company' => $company, 'franchise' => $franchise];
        });
    }

    public function test_it_refuses_an_organization_that_is_not_the_demo_one(): void
    {
        $tenant = $this->provisionTenant('Northgate Garage Doors');
        $this->makeVehicle($tenant, 'V-00001');

        $code = $this->artisan('demo:fill')->run();

        // A real tenant, and the property is that it is untouched. This test
        // asserted only the message and the exit code until 2026-09-04, which
        // is to say it never once looked at the thing it was named after.
        $this->assertSame($this->emptyCounts(), $this->counts(), 'a tenant that is not the demo one must be untouched');
        $this->assertSame(1, $code);
    }

    /**
     * The refusals assert on ROWS first and never on the message.
     *
     * expectsOutputToContain() is checked as the command runs, so a test written
     * message-first stops at execution when the wording changes and the row
     * assertion — the one carrying the actual constraint — never runs at all. It
     * looks like a guard test and is really a spelling test. The Inspection
     * session hit exactly this on 2026-09-04 sabotaging its own equivalent.
     *
     * assertFailed() is not enough either, and sabotaging the guard is what
     * showed it: with the checks removed these failed on "Unexpected status code
     * 0" — caught, but again before counting a single row. So the exit code is
     * taken with run() and asserted AFTER the rows, and a broken guard now fails
     * saying what was written. The wording is pinned once, separately, in the
     * test below where the message IS the subject.
     */
    public function test_it_refuses_the_right_id_under_the_wrong_name(): void
    {
        // The case the name half of the guard exists for: an id recycled onto,
        // or a demo organization renamed into, something real.
        $this->demoTenant('A1-Group');

        $code = $this->artisan('demo:fill')->run();

        $this->assertSame($this->emptyCounts(), $this->counts(), 'nothing may be written to a tenant that is not the demo one');
        $this->assertSame(1, $code);
    }

    public function test_it_refuses_when_is_demo_is_not_set(): void
    {
        // Right id, right name, but the column that actually means "this tenant
        // holds invented data" says no. That is the one to believe.
        $this->demoTenant(self::DEMO_NAME, isDemo: false);

        $code = $this->artisan('demo:fill')->run();

        $this->assertSame($this->emptyCounts(), $this->counts(), 'is_demo unset must stop every write');
        $this->assertSame(1, $code);
    }

    public function test_the_refusal_says_which_check_failed(): void
    {
        // Separate, because here the wording is the point: somebody reading a
        // refusal at 2am needs to know WHICH of the three gates said no.
        $this->demoTenant(self::DEMO_NAME, isDemo: false);

        $this->artisan('demo:fill')
            ->expectsOutputToContain('is_demo UNSET')
            ->assertFailed();
    }

    public function test_it_never_picks_a_store_review_login(): void
    {
        // The bug this exists for, found by a dry run against production on
        // 2026-09-04: the demo tenant's ONLY eight users were the store review
        // logins, the selector took the four oldest active users, and the run
        // proposed giving the vans to "Apple Review", "Google Review" and
        // "TikTok Review". Those names would have gone onto the App Store
        // screenshots for an Apple reviewer to find.
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');

        $reviewer = app(TenantContext::class)->run($tenant['org'], fn () => User::query()->create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Apple Review',
            'email' => 'apple@ezbizi.com',
            'password' => 'a-real-password',
            'is_active' => true,
        ]));

        $this->artisan('demo:fill')->assertSuccessful();

        $this->assertSame(
            0,
            VehicleAssignment::query()->withoutGlobalScopes()->where('user_id', $reviewer->id)->count(),
            'a store review login must never hold a vehicle',
        );
        $this->assertSame(
            0,
            TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $reviewer->id)->count(),
            'a store review login must never appear on the time clock',
        );
        $this->assertGreaterThan(
            0,
            VehicleAssignment::query()->withoutGlobalScopes()->count(),
            'and the step must actually have run, or this proves nothing',
        );
    }

    public function test_the_staff_it_creates_are_placed_not_unplaced(): void
    {
        // A user with no Assignment row is "unplaced", and that is a real state
        // with consequences rather than a tidiness point. TimeClock::visibleTo
        // and HrPeopleController::visibleTo only show unplaced people to
        // somebody standing at the ORGANIZATION, so a franchise-level viewer
        // would meet vans held by nobody and an empty time clock. HR's overview
        // gives them their own row reading "Not assigned to a place",
        // and five of those on an App Store screenshot reads as a broken
        // install.
        //
        // Found by following the Inspection session's null-parent thread into
        // my own unshipped command on 2026-09-08, not by a failing test.
        $tenant = $this->demoTenant();

        $this->artisan('demo:fill')->assertSuccessful();

        $staff = User::query()->withoutGlobalScopes()
            ->where('email', 'like', '%@demo.ezbizi.invalid')
            ->get();

        $this->assertGreaterThan(0, $staff->count(), 'the step must have run, or this proves nothing');

        foreach ($staff as $person) {
            $placement = Assignment::query()->withoutGlobalScopes()
                ->where('user_id', $person->id)
                ->first();

            $this->assertNotNull($placement, $person->name.' has no assignment and is unplaced');
            $this->assertSame(
                $tenant['franchise']->id,
                $placement->assigned_franchise_id,
                $person->name.' must be placed at the demo franchise',
            );
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->demoTenant();

        $code = $this->artisan('demo:fill', ['--dry-run' => true])->run();

        $this->assertSame($this->emptyCounts(), $this->counts(), '--dry-run must write nothing at all');
        $this->assertSame(0, $code);
    }

    public function test_it_fills_scheduling_fleet_and_the_time_clock(): void
    {
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');
        $this->makeVehicle($tenant, 'V-00002');

        $code = $this->artisan('demo:fill')->run();

        // Scheduling: an area with zips and a working week is what turns the
        // screen from a search box over nothing into something photographable.
        $this->assertSame(3, ServiceArea::query()->withoutGlobalScopes()->count());
        $this->assertSame(10, ServiceAreaZip::query()->withoutGlobalScopes()->count());
        $this->assertSame(15, ServiceAreaSlot::query()->withoutGlobalScopes()->count());

        // Fleet: every vehicle has a current holder, which is the line that read
        // "Nobody has the keys" on all five of them.
        $this->assertSame(2, VehicleAssignment::query()->withoutGlobalScopes()->where('open', 1)->count());
        $this->assertSame(2, VehicleServiceVisit::query()->withoutGlobalScopes()->count());

        $this->assertGreaterThan(0, TimeClockShift::query()->withoutGlobalScopes()->count());

        $this->assertSame(0, $code);
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');

        $this->artisan('demo:fill')->assertSuccessful();
        $before = $this->counts();

        $second = $this->artisan('demo:fill')->run();

        // State first, exit code last. assertSuccessful() chained onto artisan()
        // throws before a row is read, so a run that doubled AND failed would
        // report the failure and never mention the doubling.
        $this->assertSame($before, $this->counts(), 'a second run must be a no-op, not doubles');
        $this->assertSame(0, $second);
    }

    public function test_running_it_again_a_week_later_adds_nothing(): void
    {
        // The same-second test above cannot see the bug this one exists for.
        // Service visits and closed shifts have no natural key, so they were
        // keyed on dates computed from now() — which form a DIFFERENT key
        // tomorrow, so a later run matched nothing and appended a second set.
        // Idempotency that only holds within one day is not idempotency, and
        // the demo tenant would have grown a new pair of shifts per person
        // every time anybody ran this.
        $tenant = $this->demoTenant();
        $this->makeVehicle($tenant, 'V-00001');

        $this->artisan('demo:fill')->assertSuccessful();
        $before = $this->counts();

        $this->travel(8)->days();
        $later = $this->artisan('demo:fill')->run();

        $this->assertSame($before, $this->counts(), 'a run on a later day must still be a no-op');
        $this->assertSame(0, $later);
    }

    /**
     * Chat for the store screenshots (Apple, 2026-11-25): the App Review login
     * in six chats with the invented staff, a photo, a voice message, call
     * lines, one chat unread. Once, however often it runs.
     */
    public function test_it_fills_chat_for_the_review_login_once(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.default'));
        $tenant = $this->demoTenant();
        $reviewer = app(TenantContext::class)->run($tenant['org'], fn () => User::query()->create([
            'organization_id' => $tenant['org']->id,
            'name' => 'Apple Review',
            'email' => 'apple@ezbizi.com',
            'password' => 'a-real-password',
            'is_active' => true,
        ]));

        $this->artisan('demo:fill')->assertSuccessful();

        $chats = \App\Models\ChatMember::query()->withoutGlobalScopes()->where('user_id', $reviewer->id)->pluck('chat_id');
        $this->assertCount(6, $chats);
        $messages = \App\Models\ChatMessage::query()->withoutGlobalScopes()->whereIn('chat_id', $chats);
        $this->assertSame(1, (clone $messages)->where('kind', 'image')->count());
        $this->assertSame(1, (clone $messages)->where('kind', 'voice')->count());
        $this->assertSame(3, (clone $messages)->where('kind', 'call')->count());
        $this->assertSame(2, \App\Models\Attachment::query()->withoutGlobalScopes()->where('attachable_type', 'chat_messages')->count());
        $this->assertSame(1, \App\Models\ChatMember::query()->withoutGlobalScopes()->where('user_id', $reviewer->id)->whereNull('read_at')->count(),
            'one chat, Priya\'s, is left unread');
        // Nobody else in the organization is touched.
        $this->assertSame(0, \App\Models\ChatMember::query()->withoutGlobalScopes()->where('user_id', '!=', $reviewer->id)
            ->whereNotIn('chat_id', $chats)->count());

        $before = $this->counts();
        $this->artisan('demo:fill')->assertSuccessful();
        $this->assertSame($before, $this->counts(), 'a second run adds no chats');
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return array_fill_keys(array_keys($this->counts()), 0);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'areas' => ServiceArea::query()->withoutGlobalScopes()->count(),
            'zips' => ServiceAreaZip::query()->withoutGlobalScopes()->count(),
            'slots' => ServiceAreaSlot::query()->withoutGlobalScopes()->count(),
            'assignments' => VehicleAssignment::query()->withoutGlobalScopes()->count(),
            'visits' => VehicleServiceVisit::query()->withoutGlobalScopes()->count(),
            'shifts' => TimeClockShift::query()->withoutGlobalScopes()->count(),
            'chats' => \App\Models\Chat::query()->withoutGlobalScopes()->count(),
            'chat_messages' => \App\Models\ChatMessage::query()->withoutGlobalScopes()->count(),
        ];
    }

    /** @param  array{org: Organization, company: Company, franchise: Franchise}  $tenant */
    private function makeVehicle(array $tenant, string $number): Vehicle
    {
        return app(TenantContext::class)->run($tenant['org'], fn () => Vehicle::query()->create([
            'organization_id' => $tenant['org']->id,
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'number' => $number,
            'label' => "Van {$number}",
            'year' => 2021,
            'make' => 'Ford',
            'model' => 'Transit 250',
            'plate' => 'DEMO-'.substr($number, -3),
            'vin' => str_pad($number, 17, 'X'),
            'colour' => 'White',
            'body_type' => 'van',
            'fuel_type' => 'gas',
            'status' => 'active',
        ]));
    }
}
