<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MembershipsOfferPriorClientsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_dry_run_raises_nothing_and_send_offers_each_non_member_once(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $plan = MembershipPlan::create(['name' => 'Yearly', 'price_minor' => 9900, 'billing' => 'yearly']);
            foreach (['Prior', 'Member', 'No phone'] as $i => $name) {
                $c = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => "C-$i", 'name' => $name,
                    'phone' => $name === 'No phone' ? null : '555-201-000'.$i]);
                Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $c->id, 'scheduled_for' => now()->subMonths(2)]);
                if ($name === 'Member') {
                    Membership::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $c->id, 'membership_plan_id' => $plan->id,
                        'enrollment' => 'new', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
                }
            }
        });
        $offers = fn () => AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.membership_offer')->count();

        $this->artisan('memberships:offer-prior-clients', ['organization' => $t['org']->id])->expectsOutputToContain('Eligible: 1')->assertSuccessful();
        $this->assertSame(0, $offers());

        $this->artisan('memberships:offer-prior-clients', ['organization' => $t['org']->id, '--send' => true])->assertSuccessful();
        $this->artisan('memberships:offer-prior-clients', ['organization' => $t['org']->id, '--send' => true])->assertSuccessful();
        $this->assertSame(1, $offers(), 'Only the prior client, and only once.');
    }
}
