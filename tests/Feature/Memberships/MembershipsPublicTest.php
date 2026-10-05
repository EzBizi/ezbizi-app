<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Memberships\CustomerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The customer's side (spec 2026-10-05): the review faces say who smiled; the join page signs them up. */
class MembershipsPublicTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;
    private WebSite $site;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($this->t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $this->t['company']->getKey(), 'host' => 'chimney.test',
        ])->assertCreated()->json('data.id');
        auth()->forgetGuards();

        [$this->site, $this->customer] = $this->inTenant($this->t['org'], function () use ($id) {
            WebValue::create(['site_id' => $id, 'path' => '', 'key' => 'review_link', 'value' => 'https://chimney.test/reviews/']);
            MembershipPlan::create(['name' => 'Yearly sweep', 'price_minor' => 19900, 'billing' => 'yearly']);

            return [WebSite::findOrFail($id), Customer::create([
                'owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1',
                'name' => 'Marta Quill', 'phone' => '(555) 201-3344', 'email' => 'marta@example.test',
            ])];
        });
    }

    private function reviewed(): int
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->count();
    }

    public function test_the_review_link_names_the_customer_and_the_faces_send_it_back(): void
    {
        $link = $this->inTenant($this->t['org'], fn () => $this->customer->review_link);
        $this->assertStringStartsWith('https://chimney.test/reviews/?c=', $link);
        parse_str(parse_url($link, PHP_URL_QUERY), $q);

        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'c' => $q['c']])
            ->assertOk();
        $this->assertSame(1, $this->reviewed());

        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->first();
        $this->assertSame($this->customer->id, $event->subject_id);
        $this->assertTrue($event->payload['after']['verified']);
        $this->assertStringContainsString('/memberships/join/', $event->payload['after']['membership_join_url']);
    }

    public function test_a_forged_token_matches_nobody_and_the_answer_is_the_same(): void
    {
        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'c' => $this->customer->id.'.forged'])
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(0, $this->reviewed());
    }

    public function test_without_a_token_the_typed_phone_finds_them_unverified_once_a_day(): void
    {
        $key = $this->site->public_key;
        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'happy', 'phone' => '555 000 0000'])->assertOk();
        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'happy'])->assertOk();
        $this->assertSame(0, $this->reviewed(), 'A stranger and nothing typed match nobody.');

        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'happy', 'phone' => '+1 555-201-3344'])->assertOk();
        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'sad', 'email' => 'marta@example.test', 'comment' => 'Late'])->assertOk();

        $this->assertSame(1, $this->reviewed(), 'Once a day per customer.');
        $this->assertFalse(AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->first()->payload['after']['verified']);
    }

    public function test_a_garbage_token_beside_a_phone_is_unverified_and_does_not_block_the_real_link(): void
    {
        $key = $this->site->public_key;
        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'happy', 'c' => 'anything', 'phone' => '5552013344'])->assertOk();

        $events = fn () => AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->orderBy('occurred_at')->get();
        $this->assertCount(1, $events());
        $this->assertFalse($events()->first()->payload['after']['verified']);
        $this->assertSame('asked', $events()->first()->payload['after']['source']);

        $c = $this->inTenant($this->t['org'], fn () => CustomerLinks::token($this->customer));
        $this->postJson('/api/v1/web/review', ['key' => $key, 'rating' => 'happy', 'c' => $c])->assertOk();
        $this->assertCount(2, $events(), 'A stranger typing the phone first does not block the real link.');
        $this->assertEqualsCanonicalizing([false, true], $events()->map(fn ($e) => $e->payload['after']['verified'])->all());
    }

    public function test_a_phone_on_two_customers_names_nobody(): void
    {
        $this->inTenant($this->t['org'], fn () => Customer::create([
            'owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-2',
            'name' => 'Another House', 'phone' => '555-201-3344',
        ]));

        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'phone' => '5552013344'])->assertOk();
        $this->assertSame(0, $this->reviewed());
    }

    public function test_the_join_link_signs_them_up_once(): void
    {
        $url = $this->inTenant($this->t['org'], fn () => $this->customer->membership_join_url);
        $plan = $this->inTenant($this->t['org'], fn () => MembershipPlan::query()->value('id'));

        $this->get($url)->assertOk()->assertSee('Yearly sweep');
        $this->get(preg_replace('/signature=\w+/', 'signature=nope', $url))->assertForbidden();

        $this->post($url, ['plan' => $plan])->assertOk()->assertSee('member');
        $this->post($url, ['plan' => $plan])->assertOk();

        $this->assertSame(1, Membership::withoutGlobalScopes()->count());
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.created')->count());
    }
}
