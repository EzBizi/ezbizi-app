<?php

namespace Tests\Feature\Memberships;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The page a customer joins from (UI queue 54): the business's own frame, and
 * a price nobody can misread. The stored price is the YEAR's
 * (Membership::dueMinor bills a twelfth a month), so a monthly plan must show
 * its month and its year, and never the year's price as a month's.
 */
class MembershipsJoinPageTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');

        $this->customer = $this->inTenant($this->t['org'], function () {
            MembershipPlan::create(['name' => 'Yearly sweep', 'price_minor' => 19900, 'billing' => 'yearly']);
            MembershipPlan::create(['name' => 'Chimney care', 'price_minor' => 21600, 'billing' => 'monthly']);

            return Customer::create([
                'owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1',
                'name' => 'Marta Quill', 'phone' => '(555) 201-3344', 'email' => 'marta@example.test',
            ]);
        });
    }

    public function test_a_monthly_plan_shows_its_month_and_its_year_never_the_year_as_a_month(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->customer->membership_join_url);

        $this->get($url)->assertOk()
            ->assertSee('$18 <small>a month · $216 a year</small>', false)
            ->assertSee('$199 <small>a year</small>', false)
            ->assertDontSee('$216 <small>a month', false)
            // The frame the business's emails wear, so the page reads as the
            // message that linked here.
            ->assertSee('via EzBizi');
    }

    public function test_a_member_is_thanked_with_their_plan_and_the_end_of_their_year(): void
    {
        [$url, $ends] = $this->inTenant($this->t['org'], function () {
            $starts = CarbonImmutable::today();
            Membership::create([
                'franchise_id' => $this->customer->owning_franchise_id,
                'customer_id' => $this->customer->getKey(),
                'membership_plan_id' => MembershipPlan::query()->where('name', 'Yearly sweep')->value('id'),
                'enrollment' => 'new',
                'starts_on' => $starts,
                'ends_on' => $starts->addYear()->subDay(),
            ]);

            return [$this->customer->membership_join_url, $starts->addYear()->subDay()];
        });

        $this->get($url)->assertOk()
            ->assertSee("You're a member, Marta Quill.", false)
            ->assertSee('Yearly sweep, until '.$ends->format('F j, Y'))
            // Control: a member is not offered the plans again.
            ->assertDontSee('<button type="submit">Join</button>', false);
    }
}
