<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The estimates API (spec 2026-09-29 §4): numbered on create, lines replaced
 * as one document, draft → sent → accepted/declined, anything → archived,
 * `estimate.accepted` raised once, and only a draft deleted.
 */
class EstimatesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;
    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        $this->customer = $this->aCustomer($this->alpha['franchise']->id);
    }

    public function test_creating_numbers_it_and_totals_its_lines(): void
    {
        $data = $this->create(['lines' => [
            ['name' => 'Sweep', 'quantity' => 2, 'unit_price_minor' => 15000],
            ['name' => 'Liner', 'quantity' => 1.5, 'unit_price_minor' => 333],
        ]])->assertCreated()->json('data');

        $this->assertNotEmpty($data['number']);
        $this->assertSame('draft', $data['status']);
        $this->assertSame(30000 + 500, $data['total_minor']);   // 1.5 x 333 = 499.5 -> 500
        $this->assertSame(['Sweep', 'Liner'], array_column($data['lines'], 'name'));
        $this->assertSame('USD', $data['currency']);
    }

    /** A1 titled 2,381 of its 2,383 estimates; EzBizi's own can carry one too. */
    public function test_the_title_is_set_on_create_and_changed_on_update(): void
    {
        $created = $this->create(['title' => 'Relining'])->assertCreated()->assertJsonPath('data.title', 'Relining')->json('data');
        $id = $created['id'];
        // Carried for A1's history, whose issued_on is always null.
        $this->assertNotNull($created['created_at']);

        $this->amend($id, ['title' => 'Relining and crown'])->assertOk()->assertJsonPath('data.title', 'Relining and crown');
        $this->amend($id, ['note' => 'Called'])->assertOk()->assertJsonPath('data.title', 'Relining and crown');
    }

    public function test_the_list_is_scoped_to_view(): void
    {
        $elsewhere = $this->extraFranchise($this->alpha, 'Elsewhere');
        $here = $this->create()->json('data.id');
        $this->create(['franchise_id' => $elsewhere->id, 'customer_id' => $this->aCustomer($elsewhere->id)]);

        $branch = $this->userWithRole($this->alpha, 'Branch',
            ['jobs.view', 'jobs.financials', 'estimates.view'], onlyAt: $this->alpha['franchise']);

        $ids = array_column($this->signedInAs($branch)->getJson('/api/v1/estimates')->assertOk()->json('data'), 'id');
        $this->assertSame([$here], $ids);
    }

    /**
     * The list's header (queue 32): counts per status and money per currency,
     * faceted like the jobs chips -- the status filter is left out, so the
     * chips say what tapping each would show and the sums describe the list.
     */
    public function test_the_first_page_counts_each_status_and_sums_the_money(): void
    {
        $line = fn (int $minor) => ['lines' => [['name' => 'Sweep', 'quantity' => 1, 'unit_price_minor' => $minor]]];
        $this->create($line(10000));
        $this->create($line(20000));
        $sent = $this->create($line(30000))->json('data.id');
        $this->amend($sent, ['status' => 'sent'])->assertOk();
        $won = $this->create($line(40000))->json('data.id');
        $this->amend($won, ['status' => 'sent'])->assertOk();
        $this->amend($won, ['status' => 'accepted'])->assertOk();

        $meta = $this->signedInAs($this->alpha['admin'])->getJson('/api/v1/estimates?status=draft')
            ->assertOk()->json('meta');

        $this->assertSame(['draft' => 2, 'sent' => 1, 'accepted' => 1, 'declined' => 0, 'archived' => 0], $meta['status_counts']);
        $this->assertSame(['accepted', 'declined', 'archived'], $meta['transitions']['sent']);
        $this->assertSame([], $meta['transitions']['archived']);
        $this->assertSame(['USD' => ['quoted' => 100000, 'accepted' => 40000]], $meta['totals']);
    }

    /** "Number or customer" (queue 32): matched on the estimate's number or the customer's name or number. */
    public function test_search_finds_by_number_or_customer(): void
    {
        $crown = $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $this->alpha['franchise']->id, 'number' => 'C-900', 'name' => 'Crown Repairs Ltd',
        ])->id);
        $theirs = $this->create(['customer_id' => $crown])->json('data');
        $mine = $this->create()->json('data');

        $search = fn (string $term) => array_column($this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/estimates?search='.urlencode($term))->assertOk()->json('data'), 'id');

        $this->assertSame([$theirs['id']], $search('crown'));
        $this->assertSame([$theirs['id']], $search('C-900'));
        $this->assertSame([$mine['id']], $search($mine['number']));
        $this->assertCount(2, $search(''), 'The control: an empty search filters nothing.');
    }

    public function test_the_header_is_an_object_even_when_nothing_matches(): void
    {
        $raw = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/estimates?customer_id=00000000-0000-0000-0000-000000000000')->assertOk()->getContent();

        $this->assertStringContainsString('"totals":{}', $raw);
    }

    public function test_the_transitions_are_the_documented_ones(): void
    {
        $id = $this->create()->json('data.id');

        $this->amend($id, ['status' => 'accepted'])->assertStatus(422)
            ->assertJsonValidationErrors(['status' => 'cannot go from draft to accepted']);
        $this->amend($id, ['status' => 'sent'])->assertOk();
        $this->amend($id, ['status' => 'sent'])->assertOk();   // unchanged: a no-op, not a refusal
        $accepted = $this->amend($id, ['status' => 'accepted'])->assertOk()->json('data');
        $this->assertNotNull($accepted['accepted_at']);
        $this->amend($id, ['status' => 'sent'])->assertStatus(422);
        $this->amend($id, ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_accepting_raises_estimate_accepted_once(): void
    {
        $id = $this->create()->json('data.id');
        $this->amend($id, ['status' => 'sent'])->assertOk();
        $this->amend($id, ['status' => 'accepted'])->assertOk();
        $this->amend($id, ['status' => 'accepted', 'note' => 'Called to confirm'])->assertOk();

        $events = $this->inTenant($this->alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'estimate.accepted')->where('subject_id', $id)->get());

        $this->assertCount(1, $events);
        // assertEquals: a JSON column stores keys in its own order, and the order means nothing.
        $this->assertEquals(['before' => ['status' => 'sent'], 'after' => ['status' => 'accepted']], $events[0]->payload);
    }

    public function test_lines_replace_and_recompute_and_freeze_after_a_decision(): void
    {
        $id = $this->create(['lines' => [['name' => 'Sweep', 'quantity' => 1, 'unit_price_minor' => 10000]]])->json('data.id');
        $this->amend($id, ['status' => 'sent'])->assertOk();

        $data = $this->amend($id, ['lines' => [['name' => 'Cap', 'quantity' => 2, 'unit_price_minor' => 4000]]])
            ->assertOk()->json('data');
        $this->assertSame(['Cap'], array_column($data['lines'], 'name'));
        $this->assertSame(8000, $data['total_minor']);

        $this->amend($id, ['status' => 'declined'])->assertOk();
        $this->amend($id, ['lines' => []])->assertStatus(422)->assertJsonValidationErrors(['lines' => 'fixed']);
    }

    public function test_only_a_draft_can_be_deleted(): void
    {
        $draft = $this->create()->json('data.id');
        $this->signedInAs($this->alpha['admin'])->deleteJson("/api/v1/estimates/{$draft}")->assertNoContent();

        $sent = $this->create()->json('data.id');
        $this->amend($sent, ['status' => 'sent'])->assertOk();
        $this->signedInAs($this->alpha['admin'])->deleteJson("/api/v1/estimates/{$sent}")
            ->assertStatus(422)->assertJsonValidationErrors(['status' => 'archive it instead']);
    }

    public function test_the_job_must_be_the_customers_and_the_franchises(): void
    {
        $someoneElse = $this->aCustomer($this->alpha['franchise']->id);
        $theirJob = $this->inTenant($this->alpha['org'], fn () => Job::create([
            'franchise_id' => $this->alpha['franchise']->id, 'customer_id' => $someoneElse,
            'number' => 'J-1', 'title' => 'A job', 'status' => 'scheduled', 'currency' => 'USD',
        ])->id);

        $this->create(['job_id' => $theirJob])->assertStatus(422)
            ->assertJsonValidationErrors(['job_id' => "another customer's"]);
    }

    public function test_another_franchise_is_refused_on_create(): void
    {
        $elsewhere = $this->extraFranchise($this->alpha, 'Elsewhere');
        $branch = $this->userWithRole($this->alpha, 'Branch',
            ['jobs.view', 'jobs.financials', 'estimates.view', 'estimates.create'], onlyAt: $this->alpha['franchise']);

        $this->signedInAs($branch)->postJson('/api/v1/estimates', [
            'franchise_id' => $elsewhere->id, 'customer_id' => $this->aCustomer($elsewhere->id),
        ])->assertForbidden();
    }

    private function create(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->signedInAs($this->alpha['admin'])->postJson('/api/v1/estimates', $overrides + [
            'franchise_id' => $this->alpha['franchise']->id,
            'customer_id' => $this->customer,
        ]);
    }

    private function amend(string $id, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->signedInAs($this->alpha['admin'])->patchJson("/api/v1/estimates/{$id}", $body);
    }

    private function aCustomer(string $franchiseId): string
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $franchiseId,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => 'A Customer',
        ])->id);
    }
}
