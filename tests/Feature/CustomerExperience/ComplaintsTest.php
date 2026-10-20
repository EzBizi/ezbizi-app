<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\AutomationEvent;
use App\Models\Complaint;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Customer Experience › Complaints (Jose, 2026-10-20): bad reviews, Bad
 * Ratings and complaint calls in one list, worked to Solved.
 */
class ComplaintsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Franchise $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        $this->other = $this->inTenant($this->t['org'], fn () => Franchise::create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['franchise']->company_id, 'name' => 'Other', 'is_active' => true]));
    }

    private function event(string $key, $subject, array $after = []): void
    {
        $this->inTenant($this->t['org'], fn () => AutomationEvent::create(['organization_id' => $this->t['org']->id,
            'event_key' => $key, 'subject_type' => $subject::class, 'subject_id' => $subject->id,
            'payload' => ['after' => $after], 'occurred_at' => now()->subMinute(), 'available_at' => now()]));
    }

    private function review(int $stars, ?string $franchise = null, array $more = []): Review
    {
        return $this->inTenant($this->t['org'], fn () => Review::create(['organization_id' => $this->t['org']->id,
            'franchise_id' => $franchise ?? $this->t['franchise']->id,
            'remote_name' => 'accounts/1/locations/1/reviews/'.bin2hex(random_bytes(4)), 'location_name' => 'accounts/1/locations/1',
            'reviewer_name' => 'Dana Whitfield', 'rating' => $stars, 'comment' => 'Soot on the carpet.', 'reviewed_at' => now(), ...$more]));
    }

    private function lead(?string $type, string $name): Lead
    {
        return $this->inTenant($this->t['org'], fn () => Lead::create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id, 'franchise_id' => $this->t['franchise']->id, 'source' => 'website_form',
            'remote_id' => bin2hex(random_bytes(4)), 'status' => 'NEW', 'received_at' => now(),
            'lead_type' => $type, 'contact_name' => $name, 'summary' => "{$name} is unhappy."]));
    }

    private function intake(): void
    {
        $this->artisan('ezbizi:complaints-intake')->assertSuccessful();
    }

    public function test_bad_reviews_bad_ratings_and_complaint_calls_open_once_each(): void
    {
        $bad = $this->review(2);
        $good = $this->review(5);
        $rating = $this->lead('BAD_RATING', 'Sam');
        $enquiry = $this->lead(null, 'Pat');
        $caller = $this->lead(null, 'Lee');
        $this->event('review.received', $bad);
        $this->event('review.received', $good);
        $this->event('lead.received', $rating);
        $this->event('lead.received', $enquiry);
        $this->event('lead.call_complaint', $caller, ['summary' => 'Wants a manager.']);

        $this->intake();
        $this->intake(); // the cursor moved on; and the same source never opens twice

        $this->assertEqualsCanonicalizing(['bad_review', 'bad_rating', 'call_complaint'],
            Complaint::withoutGlobalScopes()->pluck('kind')->all());
        $this->assertSame(3, AutomationEvent::withoutGlobalScopes()->where('event_key', 'complaint.opened')->count());
    }

    /** A profile's first import raises nothing, so the backfill reads reviews too, and tells no rule. */
    public function test_the_backfill_reads_old_bad_reviews_and_raises_nothing(): void
    {
        $this->review(1, null, ['reviewed_at' => now()->subDays(20)]);
        $this->review(4, null, ['reviewed_at' => now()->subDays(20)]);

        $this->artisan('ezbizi:complaints-intake', ['--since' => now()->subDays(90)->toDateString()])->assertSuccessful();

        $this->assertSame(1, Complaint::withoutGlobalScopes()->count());
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'complaint.opened')->count());
    }

    public function test_the_list_counts_filters_and_searches_and_names_the_people(): void
    {
        $this->event('review.received', $this->review(1));
        $this->event('lead.received', $this->lead('BAD_RATING', 'Sam Rivers'));
        $this->intake();
        $solved = Complaint::withoutGlobalScopes()->where('kind', 'bad_rating')->first();
        $this->inTenant($this->t['org'], fn () => $solved->forceFill(['status' => 'solved'])->save());

        $as = $this->signedInAs($this->t['admin']);
        $open = $as->getJson('/api/v1/complaints')->assertOk()->json();
        $this->assertSame(['bad_review'], array_column($open['data'], 'kind'), 'Open by default');
        $this->assertSame('Dana Whitfield', $open['data'][0]['title'], 'read from the review, not copied');
        $this->assertSame(['new' => 1, 'in_progress' => 0, 'solved' => 1, 'not_solvable' => 0, 'open' => 1, 'all' => 2], $open['meta']['counts']);
        $this->assertNotEmpty($open['meta']['people']);

        $this->assertSame(['Sam Rivers'], array_column($as->getJson('/api/v1/complaints?status=all&q=rivers')->json('data'), 'title'));
        $this->assertSame(['bad_rating'], array_column($as->getJson('/api/v1/complaints?status=all&kind=bad_rating')->json('data'), 'kind'));
    }

    public function test_working_one_moves_its_status_owner_and_notes_and_tells_rules(): void
    {
        $this->event('review.received', $this->review(1));
        $this->intake();
        $id = Complaint::withoutGlobalScopes()->value('id');
        $as = $this->signedInAs($this->t['admin']);

        $as->patchJson("/api/v1/complaints/{$id}", ['status' => 'in_progress', 'owner_user_id' => $this->t['admin']->id])->assertOk()
            ->assertJsonPath('data.owner.id', $this->t['admin']->id);
        $as->postJson("/api/v1/complaints/{$id}/notes", ['text' => 'Called, booked a re-clean.'])->assertOk()
            ->assertJsonPath('data.notes.0.text', 'Called, booked a re-clean.');
        $done = $as->patchJson("/api/v1/complaints/{$id}", ['status' => 'solved'])->assertOk()->json('data');
        $this->assertNotNull($done['solved_at']);
        $as->patchJson("/api/v1/complaints/{$id}", ['status' => 'closed'])->assertUnprocessable();

        $this->assertSame([['new', 'in_progress'], ['in_progress', 'solved']],
            AutomationEvent::withoutGlobalScopes()->where('event_key', 'complaint.status_changed')->orderBy('occurred_at')->get()
                ->map(fn ($e) => [$e->payload['before']['status'], $e->payload['after']['status']])->all());
    }

    public function test_the_ticks_and_the_branch_are_respected(): void
    {
        $this->event('review.received', $this->review(1));
        $this->event('review.received', $this->review(2, $this->other->id));
        $this->intake();
        $theirs = Complaint::withoutGlobalScopes()->where('franchise_id', $this->other->id)->value('id');

        $this->signedInAs($this->userWithRole($this->t, 'Nobody', ['customers.view']))->getJson('/api/v1/complaints')->assertForbidden();

        $viewer = $this->userWithRole($this->t, 'Viewer', ['customer_experience.complaints.view'], null, $this->t['franchise']);
        $list = $this->signedInAs($viewer)->getJson('/api/v1/complaints');
        $this->assertCount(1, $list->assertOk()->json('data'), 'its own branch only');
        $this->signedInAs($viewer)->getJson("/api/v1/complaints/{$theirs}")->assertNotFound();
        $mine = Complaint::withoutGlobalScopes()->where('franchise_id', $this->t['franchise']->id)->value('id');
        $this->signedInAs($viewer)->patchJson("/api/v1/complaints/{$mine}", ['status' => 'solved'])->assertForbidden();
        $this->assertSame([], $this->signedInAs($viewer)->getJson('/api/v1/complaints')->json('meta.people'));
    }

    /** Desk, 2026-10-20: a nameless caller is not "removed"; only a gone source is. */
    public function test_a_nameless_caller_carries_the_number_and_only_a_gone_source_is_missing(): void
    {
        $caller = $this->inTenant($this->t['org'], fn () => Lead::create(['organization_id' => $this->t['org']->id,
            'company_id' => $this->t['company']->id, 'franchise_id' => $this->t['franchise']->id, 'source' => 'phone',
            'remote_id' => 'call-1', 'status' => 'NEW', 'received_at' => now(), 'phone' => '+14145340603']));
        $this->event('lead.call_complaint', $caller);
        $review = $this->review(1);
        $this->event('review.received', $review);
        $this->intake();
        $this->inTenant($this->t['org'], fn () => $review->delete());

        $rows = collect($this->signedInAs($this->t['admin'])->getJson('/api/v1/complaints?status=all')->assertOk()->json('data'))->keyBy('kind');

        $this->assertNull($rows['call_complaint']['title']);
        $this->assertSame('+14145340603', $rows['call_complaint']['phone']);
        $this->assertFalse($rows['call_complaint']['source_missing']);
        $this->assertTrue($rows['bad_review']['source_missing']);
    }
}
