<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The renewal reminder's link books the next visit; no answer leaves staff a follow-up (Jose, 2026-10-05). */
class MembershipsVisitTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->membership = $this->inTenant($this->t['org'], function () {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $plan = MembershipPlan::create(['name' => 'Yearly sweep', 'price_minor' => 19900, 'billing' => 'yearly']);
            $starts = CarbonImmutable::today()->subYear()->addWeeks(3);

            return Membership::create([
                'franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id,
                'membership_plan_id' => $plan->id, 'enrollment' => 'new',
                'starts_on' => $starts, 'ends_on' => $starts->addYear()->subDay(),
            ]);
        });
    }

    private function events(string $key): int
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', $key)->count();
    }

    public function test_a_picked_date_books_one_job_and_says_so(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->membership->confirm_visit_url);
        $day = CarbonImmutable::today()->addWeeks(4)->toDateString();

        $this->get($url)->assertOk()->assertSee('Book this day');
        $this->post($url, ['date' => $day])->assertOk()->assertSee("You're booked", false);
        $this->post($url, ['date' => $day])->assertOk();

        $jobs = Job::withoutGlobalScopes()->get();
        $this->assertCount(1, $jobs, 'One visit per membership year.');
        $this->assertStringStartsWith($day, (string) $jobs[0]->getRawOriginal('scheduled_for'));
        $this->assertSame($jobs[0]->id, Membership::withoutGlobalScopes()->first()->visit_job_id);
        $this->assertSame(1, $this->events('membership.visit_confirmed'));

        $row = $this->signedInAs($this->t['admin'])->getJson('/api/v1/memberships')->assertOk()->json('data.0');
        $this->assertSame($day, $row['visit_on']);
        $this->assertSame($jobs[0]->number, $row['visit_job_number']);
    }

    public function test_a_past_day_or_a_bad_signature_books_nothing(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->membership->confirm_visit_url);

        $this->post($url, ['date' => CarbonImmutable::today()->toDateString()])->assertOk()->assertSee('Please pick a day between');
        $this->post(preg_replace('/signature=\w+/', 'signature=nope', $url), ['date' => '2099-01-01'])->assertForbidden();

        $far = CarbonImmutable::parse(Membership::withoutGlobalScopes()->first()->ends_on)->addMonths(7)->toDateString();
        $this->post($url, ['date' => $far])->assertOk()->assertSee('Please pick a day between');

        $this->assertSame(0, Job::withoutGlobalScopes()->count());
    }

    public function test_a_second_post_after_the_check_books_nothing_more(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->membership->confirm_visit_url);
        $day = CarbonImmutable::today()->addWeeks(4)->toDateString();
        $this->post($url, ['date' => $day])->assertOk();

        // The race, replayed: the request reads the year with no visit, and
        // before it locks, another request books one.
        Membership::withoutGlobalScopes()->whereKey($this->membership->id)->update(['visit_job_id' => null]);
        $job = Job::withoutGlobalScopes()->first();
        // The request reads the year twice before it locks (to find the
        // tenant, then the row it checks); the other booking lands between.
        $reads = 0;
        Membership::retrieved(function () use ($job, &$reads) {
            if (++$reads === 2) {
                Membership::withoutGlobalScopes()->whereKey($this->membership->id)->update(['visit_job_id' => $job->id]);
            }
        });
        $this->post($url, ['date' => $day])->assertOk();

        $this->assertSame(1, Job::withoutGlobalScopes()->count());
    }

    public function test_no_answer_two_weeks_after_the_reminder_is_followed_up_once(): void
    {
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(1, $this->events('membership.renewal_due'));
        $this->assertSame(0, $this->events('membership.visit_unconfirmed'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(15));
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(1, $this->events('membership.visit_unconfirmed'));
        CarbonImmutable::setTestNow();
    }

    public function test_a_booked_visit_is_not_followed_up(): void
    {
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $url = $this->inTenant($this->t['org'], fn () => $this->membership->confirm_visit_url);
        $this->post($url, ['date' => CarbonImmutable::today()->addWeeks(4)->toDateString()])->assertOk();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(15));
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(0, $this->events('membership.visit_unconfirmed'));
        CarbonImmutable::setTestNow();
    }

    public function test_the_follow_up_wait_is_the_organizations_setting(): void
    {
        $this->inTenant($this->t['org'], fn () => \App\Models\Setting::create(['organization_id' => $this->t['org']->id,
            'scope_type' => 'organization', 'scope_id' => $this->t['org']->id, 'key' => 'customers.membership_follow_up_days', 'value' => 3]));
        app()->forgetInstance(\App\Support\Settings\SettingsResolver::class);

        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(4));
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(1, $this->events('membership.visit_unconfirmed'), 'Three days, not the default fourteen.');
        CarbonImmutable::setTestNow();
    }
}
