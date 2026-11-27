<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\ServiceArea;
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

    /** Jose, 2026-10-08: the job's thank-you text links its own franchise's reviews page, unique and expiring. */
    public function test_a_jobs_review_link_names_the_job_and_expires(): void
    {
        [$link, $job] = $this->inTenant($this->t['org'], function () {
            $job = Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $this->customer->id,
                'number' => 'J-1', 'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD']);

            return [$job->review_link, $job];
        });
        // Short (Jose, 2026-10-08): the site's /r/ and a 7-character code, nothing personal.
        $this->assertMatchesRegularExpression('~^https://chimney\.test/r/[A-Za-z0-9]{7}$~', $link);
        $q = ['c' => basename($link)];
        $this->assertSame($link, $this->inTenant($this->t['org'], fn () => $job->review_link), 'the same job reuses its code');

        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'c' => $q['c']])->assertOk();
        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->first();
        $this->assertSame($this->customer->id, $event->subject_id);
        $this->assertSame($job->id, $event->payload['after']['job_id']);

        $this->travel(91)->days();   // past the 90-day default
        $this->assertNull($this->inTenant($this->t['org'], fn () => CustomerLinks::customerId($q['c'])));
    }

    /** Jose, 2026-10-08: each area's subdomain has its own Google profile, so the job's area picks the site. */
    public function test_a_jobs_review_link_goes_to_the_site_of_its_area(): void
    {
        $id = $this->signedInAs($this->t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $this->t['company']->getKey(), 'host' => 'nj.chimney.test',
        ])->assertCreated()->json('data.id');
        $area = $this->inTenant($this->t['org'], fn () => ServiceArea::create(['franchise_id' => $this->t['franchise']->id, 'name' => 'New Jersey']));
        $this->signedInAs($this->t['admin'])->patchJson("/api/v1/web/sites/{$id}", ['service_area_ids' => [$area->id]])
            ->assertOk()->assertJsonPath('data.service_area_ids', [$area->id]);
        auth()->forgetGuards();

        $links = $this->inTenant($this->t['org'], function () use ($id, $area) {
            WebValue::create(['site_id' => $id, 'path' => '', 'key' => 'review_link', 'value' => 'https://nj.chimney.test/reviews/']);
            $job = fn (?string $areaId) => Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $this->customer->id,
                'service_area_id' => $areaId, 'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD']);

            return [$job($area->id)->review_link, $job(null)->review_link];
        });

        $this->assertStringStartsWith('https://nj.chimney.test/r/', $links[0]);
        $this->assertStringStartsWith('https://chimney.test/r/', $links[1]);
    }

    /** Jose, 2026-10-08: a known customer's sad form asks only what happened; the lead still says who. */
    public function test_a_sad_form_sent_with_the_link_lands_as_that_customer(): void
    {
        $job = $this->inTenant($this->t['org'], fn () => Job::create(['franchise_id' => $this->t['franchise']->id,
            'customer_id' => $this->customer->id, 'number' => 'J-7', 'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD']));
        $c = $this->inTenant($this->t['org'], fn () => CustomerLinks::jobToken($this->customer, $job));

        $this->getJson('/api/v1/web/membership?key='.$this->site->public_key.'&c='.rawurlencode($c))->assertJsonPath('first_name', 'Marta');
        $this->getJson('/api/v1/web/membership?key='.$this->site->public_key.'&c=nope')->assertJsonPath('first_name', null);

        $this->postJson('/api/v1/web/form', ['key' => $this->site->public_key, 'c' => $c,
            'fields' => ['Rating' => 'Not happy', 'What happened?' => 'Late']])->assertCreated();

        $lead = $this->inTenant($this->t['org'], fn () => Lead::query()->latest('created_at')->first());
        $this->assertSame('Marta Quill', $lead->contact_name);
        $this->assertSame('marta@example.test', $lead->email);
        $this->assertStringContainsString('5552013344', (string) $lead->phone_digits);
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

    public function test_the_join_page_names_the_customers_company_and_what_a_plan_includes(): void
    {
        $this->inTenant($this->t['org'], fn () => MembershipPlan::query()->update(['description' => 'Sweep and camera inspection']));
        $url = $this->inTenant($this->t['org'], fn () => $this->customer->membership_join_url);

        $this->get($url)->assertOk()
            ->assertSee($this->t['company']->name)
            ->assertDontSee($this->t['org']->name.' yearly maintenance');
    }

    public function test_membership_link_is_short_reused_and_forwards_to_the_join_page(): void
    {
        $link = $this->inTenant($this->t['org'], fn () => $this->customer->membership_link);
        $this->assertMatchesRegularExpression('#/api/m/[A-Za-z0-9]{7}$#', $link);
        $this->assertSame($link, $this->inTenant($this->t['org'], fn () => $this->customer->membership_link), 'Reused, not one per message.');

        $target = $this->get(parse_url($link, PHP_URL_PATH))->assertRedirect()->headers->get('Location');
        $this->get($target)->assertOk()->assertSee('Yearly sweep');

        $this->get('/api/m/zzzzzzz')->assertNotFound();
    }

    public function test_membership_link_is_null_for_a_member_or_with_no_plan_on_offer(): void
    {
        $this->inTenant($this->t['org'], function () {
            MembershipPlan::query()->update(['is_active' => false]);
            $this->assertNull($this->customer->fresh()->membership_link, 'Nothing on offer.');

            MembershipPlan::query()->update(['is_active' => true]);
            $this->assertNotNull($this->customer->fresh()->membership_link);

            Membership::create([
                'franchise_id' => $this->t['franchise']->id, 'customer_id' => $this->customer->id,
                'membership_plan_id' => MembershipPlan::query()->value('id'), 'enrollment' => 'new',
                'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(),
            ]);
            $this->assertNull($this->customer->fresh()->membership_link, 'Already a member.');
        });
    }

    public function test_the_review_event_says_whether_the_offer_was_already_made(): void
    {
        $c = $this->inTenant($this->t['org'], fn () => CustomerLinks::token($this->customer));
        $offered = fn () => AutomationEvent::withoutGlobalScopes()->where('event_key', 'customer.reviewed')->latest('occurred_at')->first()->payload['after']['membership_offered'];

        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'c' => $c])->assertOk();
        $this->assertFalse($offered());

        $this->inTenant($this->t['org'], fn () => $this->customer->membership_link);   // the offer text rendered it
        AutomationEvent::withoutGlobalScopes()->delete();
        $this->postJson('/api/v1/web/review', ['key' => $this->site->public_key, 'rating' => 'happy', 'c' => $c])->assertOk();
        $this->assertTrue($offered());
    }

    public function test_the_join_link_lifetime_is_the_organizations_setting(): void
    {
        $this->inTenant($this->t['org'], function () {
            \App\Models\Setting::create(['organization_id' => $this->t['org']->id, 'scope_type' => \App\Support\Settings\SettingDefinition::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id, 'key' => 'customers.join_link_days', 'value' => 5]);
            app()->forgetInstance(\App\Support\Settings\SettingsResolver::class);   // it caches per organization
            $url = CustomerLinks::joinUrl($this->customer);
            parse_str(parse_url($url, PHP_URL_QUERY), $q);
            $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, (int) $q['expires'], 5);
        });
    }

    public function test_a_link_can_be_built_outside_any_tenant(): void
    {
        // 2026-11-09 prod: "No tenant in context" from CustomerLinks::days().
        $customer = $this->inTenant($this->t['org'], fn () => $this->customer->fresh());
        app()->forgetInstance(\App\Support\Settings\SettingsResolver::class);   // no cached read to hide it
        // No context at all, as in a queued job or a public page before it resolves.
        $this->assertStringContainsString('/memberships/join/', CustomerLinks::joinUrl($customer));
    }
}
