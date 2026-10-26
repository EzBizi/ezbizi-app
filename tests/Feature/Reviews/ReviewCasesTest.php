<?php

namespace Tests\Feature\Reviews;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Review;
use App\Models\ReviewReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reviews as cases to work (Jose, 2026-10-16; UI queue 101): A1Leads GBP's useful parts, as EzBizi's own data. */
class ReviewCasesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function review(array $attributes = []): Review
    {
        return $this->inTenant($this->t['org'], fn () => Review::create([
            'organization_id' => $this->t['org']->id,
            'remote_name' => 'accounts/9/locations/'.($attributes['loc'] ?? '1').'/reviews/'.bin2hex(random_bytes(5)),
            'location_name' => 'accounts/9/locations/'.($attributes['loc'] ?? '1'),
            'reviewer_name' => 'Dana Whitfield', 'rating' => 2, 'comment' => 'Late.', 'reviewed_at' => now()->subDay(),
            ...array_diff_key($attributes, ['loc' => 1]),
        ]));
    }

    private function list(array $query = []): array
    {
        return $this->signedInAs($this->t['admin'])->getJson('/api/v1/reviews?'.http_build_query($query))->assertOk()->json();
    }

    public function test_filters_sort_and_locations(): void
    {
        $five = $this->review(['rating' => 5, 'loc' => '2', 'reviewed_at' => now()->subDays(3)]);
        $two = $this->review(['rating' => 2]);
        $old = $this->review(['rating' => 1, 'reviewed_at' => now()->subDays(40)]);
        $this->inTenant($this->t['org'], fn () => ReviewReply::create(['organization_id' => $this->t['org']->id,
            'review_id' => $five->id, 'body' => 'Thanks', 'status' => ReviewReply::STATUS_PUBLISHED]));

        $ids = fn (array $q) => array_column($this->list($q)['data'], 'id');
        $this->assertSame([$five->id], $ids(['rating' => 'positive']));
        $this->assertEqualsCanonicalizing([$two->id, $old->id], $ids(['rating' => 'negative']));
        $this->assertSame([$five->id], $ids(['replied' => 'yes']));
        $this->assertEqualsCanonicalizing([$two->id, $old->id], $ids(['replied' => 'no']));
        $this->assertNotContains($old->id, $ids(['from' => now()->subDays(10)->toDateString()]));
        $this->assertSame([$five->id], $ids(['location' => 'locations/2']));
        $this->assertSame([$five->id, $two->id, $old->id], $ids(['sort' => 'highest']));
        $this->assertSame([$old->id, $five->id, $two->id], $ids(['sort' => 'oldest']));
        $this->assertSame(['locations/1', 'locations/2'], array_column($this->list()['meta']['locations'], 'id'));
    }

    public function test_status_notes_customer_and_report_are_kept_and_shown(): void
    {
        $review = $this->review();
        [$customer, $job] = $this->inTenant($this->t['org'], function () {
            $c = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1', 'name' => 'Dana Whitfield', 'phone' => '(555) 201-3344']);
            Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $c->id, 'number' => 'J-OLD', 'title' => 'Sweep',
                'status' => 'done', 'currency' => 'USD', 'scheduled_for' => now()->subDays(30)->format('Y-m-d H:i:s'),
                'performed_by_user_id' => $this->t['admin']->id]);
            $j = Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $c->id, 'number' => 'J-NEW', 'title' => 'Sweep',
                'status' => 'done', 'currency' => 'USD', 'scheduled_for' => now()->subDays(2)->format('Y-m-d H:i:s'),
                'performed_by_user_id' => $this->t['admin']->id]);

            return [$c, $j];
        });
        $as = $this->signedInAs($this->t['admin']);
        $base = "/api/v1/reviews/{$review->id}";

        $as->patchJson($base, ['status' => 'working'])->assertOk()->assertJsonPath('data.status', 'working');
        $as->patchJson($base, ['status' => 'resolved'])->assertUnprocessable();
        $as->postJson("$base/notes", ['text' => 'Called her, no answer.'])->assertOk()
            ->assertJsonPath('data.notes.0.text', 'Called her, no answer.')->assertJsonPath('data.notes.0.by', $this->t['admin']->name);

        $as->getJson("$base/customer-suggestions")->assertOk()->assertJsonPath('data.0.id', $customer->id);
        $data = $as->putJson("$base/customer", ['customer_id' => $customer->id])->assertOk()->json('data');
        $this->assertSame('manual', $data['match']);
        $this->assertSame('J-NEW', $data['job']['number'], 'the job last done before the review');
        $this->assertSame($this->t['admin']->name, $data['job']['tech']);

        $as->postJson("$base/report", ['reason' => 'Not a customer of ours.'])->assertOk()->assertJsonPath('data.report_reason', 'Not a customer of ours.');

        $row = collect($this->list(['matched' => 'yes'])['data'])->firstWhere('id', $review->id);
        $this->assertSame(1, $row['note_count']);
        $this->assertSame('Dana Whitfield', $row['customer']['name']);
        $this->assertNotNull($row['reported_at']);
        $this->assertSame([], $this->list(['matched' => 'no'])['data']);

        $as->putJson("$base/customer", ['customer_id' => null])->assertOk()->assertJsonPath('data.customer', null)->assertJsonPath('data.job', null);
    }

    /** Its own grant, and reach as a write: a branch cannot work another branch's review. */
    public function test_working_a_review_needs_manage_and_reach(): void
    {
        $review = $this->review();
        $viewer = $this->userWithRole($this->t, 'Viewer', ['reviews.view', 'reviews.edit']);
        $this->signedInAs($viewer)->patchJson("/api/v1/reviews/{$review->id}", ['status' => 'working'])->assertForbidden();

        $other = $this->inTenant($this->t['org'], fn () => Franchise::create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['franchise']->company_id, 'name' => 'Other', 'is_active' => true]));
        $theirs = $this->review(['franchise_id' => $other->id]);
        $branch = $this->userWithRole($this->t, 'Branch', ['reviews.view', 'reviews.manage'], null, $this->t['franchise']);
        $this->signedInAs($branch)->patchJson("/api/v1/reviews/{$theirs->id}", ['status' => 'working'])->assertForbidden();

        // The control: the same grant on its own branch's review works.
        $mine = $this->review(['franchise_id' => $this->t['franchise']->id]);
        $this->signedInAs($branch)->patchJson("/api/v1/reviews/{$mine->id}", ['status' => 'working'])->assertOk();
    }

    /** A1Leads GBP's cards land on the same reviews, joined across grants, and never overwrite. */
    public function test_the_a1_leads_cards_are_copied_in_once(): void
    {
        $working = $this->review(['loc' => '7']);
        $reported = $this->review(['loc' => '7']);
        $mine = $this->review(['loc' => '7', 'status' => 'done']);
        $customer = $this->inTenant($this->t['org'], fn () => Customer::create(['owning_franchise_id' => $this->t['franchise']->id,
            'number' => 'C-9', 'name' => 'Sam Mull', 'phone' => '(937) 594-8944']));
        $card = fn (Review $r, array $c, int $seq) => \App\Models\A1LeadsReview::create([
            'organization_id' => $this->t['org']->id, 'seq' => $seq,
            // Rodrigo's grant names the account differently.
            'google_review_name' => 'accounts/OTHER/'.substr($r->remote_name, strpos($r->remote_name, 'locations/')),
            'card' => ['updated_at' => '2026-10-13T15:13:19Z', ...$c]]);
        $this->inTenant($this->t['org'], function () use ($card, $working, $reported, $mine) {
            $card($working, ['status' => 'Working on it', 'note' => 'Left a voicemail',
                'customer' => ['matched' => true, 'phone' => '+19375948944']], 1);
            $card($reported, ['status' => 'Reported - 2026-10-12'], 2);
            $card($mine, ['status' => "Can't locate", 'note' => 'Old note'], 3);
            \App\Models\A1LeadsReview::create(['organization_id' => $this->t['org']->id, 'seq' => 4,
                'google_review_name' => 'accounts/X/locations/99/reviews/gone', 'card' => ['status' => 'Removed']]);
        });

        $this->artisan('ezbizi:reviews-import-a1leads', ['organization' => $this->t['org']->id, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($working->fresh()->status, 'a dry run writes nothing');

        $this->artisan('ezbizi:reviews-import-a1leads', ['organization' => $this->t['org']->id])->assertSuccessful();
        $this->artisan('ezbizi:reviews-import-a1leads', ['organization' => $this->t['org']->id])->assertSuccessful();

        $w = $working->fresh();
        $this->assertSame('working', $w->status);
        $this->assertSame($customer->id, $w->customer_id);
        $this->assertSame('auto', $w->customer_match);
        $this->assertSame(['Left a voicemail'], $w->notes()->withoutGlobalScopes()->pluck('body')->all(), 'once, though run twice');

        $r = $reported->fresh();
        $this->assertSame('2026-10-12', $r->reported_at->toDateString());
        $this->assertSame('done', $r->status);

        $this->assertSame('done', $mine->fresh()->status, 'a status set in Reviews wins');
    }

    /** UI design 145: rating=low and this month / last month, under the filters but their own dates. */
    public function test_low_ratings_and_month_stats(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15));
        $this->review(['rating' => 5, 'loc' => '2']);
        $this->review(['rating' => 2]);
        $this->review(['rating' => 4, 'reviewed_at' => now()->subMonth()]);
        $this->review(['rating' => 1, 'reviewed_at' => now()->subMonths(3)]);

        $this->assertCount(2, $this->list(['rating' => 'low'])['data']);

        $stats = $this->list(['from' => now()->toDateString()])['meta']['stats'];
        $this->assertSame(['average' => 3.5, 'count' => 2], $stats['this_month'], 'the date filter does not narrow the stats');
        $this->assertEquals(['average' => 4.0, 'count' => 1], $stats['last_month']);

        $this->assertEquals(['average' => 5.0, 'count' => 1], $this->list(['location' => 'locations/2'])['meta']['stats']['this_month'],
            'other filters do');
    }
}
