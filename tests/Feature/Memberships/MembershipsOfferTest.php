<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\WebSite;
use App\Support\Memberships\CustomerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The review page's membership offer (Jose, 2026-10-08): status by the link's token, and a one-tap join. */
class MembershipsOfferTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;
    private WebSite $site;
    private string $c;
    private string $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($this->t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $this->t['company']->getKey(), 'host' => 'chimney.test',
        ])->assertCreated()->json('data.id');
        auth()->forgetGuards();

        [$this->site, $this->c, $this->plan] = $this->inTenant($this->t['org'], function () use ($id) {
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $plan = MembershipPlan::create(['name' => 'Yearly sweep', 'description' => 'Sweep and camera', 'price_minor' => 19900, 'billing' => 'yearly']);
            MembershipPlan::create(['name' => 'Retired', 'price_minor' => 100, 'billing' => 'yearly', 'is_active' => false]);

            return [WebSite::findOrFail($id), CustomerLinks::token($customer), $plan->id];
        });
    }

    private function offer(?string $c): array
    {
        return $this->getJson('/api/v1/web/membership?'.http_build_query(['key' => $this->site->public_key, 'c' => $c]))
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->json();
    }

    public function test_a_known_customer_sees_the_active_plans_and_no_membership(): void
    {
        $offer = $this->offer($this->c);

        $this->assertNull($offer['member']);
        $this->assertSame(['Yearly sweep'], array_column($offer['plans'], 'name'));
        $this->assertSame('Sweep and camera', $offer['plans'][0]['description']);
    }

    public function test_an_unknown_or_forged_token_gets_the_same_empty_shape(): void
    {
        $this->assertSame(['member' => null, 'plans' => []], $this->offer('nobody.forged'));
        $this->assertSame(['member' => null, 'plans' => []], $this->offer(null));
        $this->getJson('/api/v1/web/membership?key=wrong&c='.$this->c)->assertNotFound();
    }

    public function test_one_tap_joins_once_and_the_offer_then_shows_the_membership(): void
    {
        $join = fn () => $this->postJson('/api/v1/web/membership/join', ['key' => $this->site->public_key, 'c' => $this->c, 'plan_id' => $this->plan])->assertOk();

        $this->assertSame('Yearly sweep', $join()->json('member.plan_name'));
        $join();

        $this->assertSame(1, Membership::withoutGlobalScopes()->count());
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.created')->count());
        $this->assertSame('active', $this->offer($this->c)['member']['status']);
    }

    public function test_a_forged_token_or_an_inactive_plan_joins_nothing(): void
    {
        $retired = $this->inTenant($this->t['org'], fn () => MembershipPlan::query()->where('name', 'Retired')->value('id'));

        $this->postJson('/api/v1/web/membership/join', ['key' => $this->site->public_key, 'c' => 'x.y', 'plan_id' => $this->plan])
            ->assertOk()->assertExactJson(['member' => null]);
        $this->postJson('/api/v1/web/membership/join', ['key' => $this->site->public_key, 'c' => $this->c, 'plan_id' => $retired])
            ->assertOk()->assertExactJson(['member' => null]);

        $this->assertSame(0, Membership::withoutGlobalScopes()->count());
    }
}
