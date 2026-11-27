<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\ReviewLinkEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The review-link counts (Jose, 2026-10-15), by branch and person, for the franchises in view. */
class ReviewLinkReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Franchise $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');

        $this->inTenant($this->t['org'], function () {
            $this->other = Franchise::create(['organization_id' => $this->t['org']->id,
                'company_id' => $this->t['franchise']->company_id, 'name' => 'Second Branch', 'is_active' => true]);
            $automation = Automation::create(['organization_id' => $this->t['org']->id,
                'name' => 'Send Review', 'trigger_key' => 'job.status_changed', 'is_active' => true]);
            $actions = [['type' => 'sms.send', 'body' => 'Thanks! {{ review_link }}']];
            $version = AutomationVersion::create(['organization_id' => $this->t['org']->id, 'automation_id' => $automation->id,
                'version' => 1, 'conditions' => null, 'actions' => $actions, 'created_at' => now()]);

            // Branch one: two jobs texted, one opened and went to Google. Branch two: one texted, one sad.
            foreach ([[$this->t['franchise'], ['opened', 'happy', 'google']], [$this->t['franchise'], []], [$this->other, ['opened', 'sad', 'submitted']]] as $i => [$franchise, $kinds]) {
                $customer = Customer::create(['owning_franchise_id' => $franchise->id, 'number' => "C-$i", 'name' => "Customer $i"]);
                $job = Job::create(['franchise_id' => $franchise->id, 'customer_id' => $customer->id,
                    'number' => "J-$i", 'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD',
                    'performed_by_user_id' => $this->t['admin']->id]);
                $event = AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'job.status_changed',
                    'subject_type' => Job::class, 'subject_id' => $job->id, 'payload' => [],
                    'occurred_at' => now(), 'available_at' => now()]);
                AutomationRun::create(['automation_id' => $automation->id, 'automation_version_id' => $version->id,
                    'event_id' => $event->id, 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(),
                    'actions_snapshot' => $actions, 'action_count' => 1,
                    'action_results' => [['type' => 'sms.send', 'ok' => true, 'state' => 'succeeded']]]);
                foreach ($kinds as $kind) {
                    ReviewLinkEvent::create(['customer_id' => $customer->id, 'job_id' => $job->id, 'kind' => $kind, 'occurred_at' => now()]);
                }
            }
        });
    }

    public function test_the_organization_sees_every_count_by_branch_and_person(): void
    {
        $data = $this->signedInAs($this->t['admin'])->getJson('/api/v1/customer-experience/review-links')
            ->assertOk()->json('data');

        $this->assertSame(['sent' => 3, 'opened' => 2, 'happy' => 1, 'sad' => 1, 'submitted' => 1, 'google' => 1, 'reviewed' => 0, 'qr' => 0], $data['totals']);
        $this->assertSame([2, 1], array_column($data['by_branch'], 'sent'));
        $this->assertSame([$this->t['franchise']->name, 'Second Branch'], array_column($data['by_branch'], 'name'));
        $this->assertSame(1, $data['by_branch'][0]['google']);
        $this->assertSame($this->t['admin']->name, $data['by_person'][0]['name']);
        $this->assertSame(3, $data['by_person'][0]['sent']);
        $this->assertNotNull($data['tracked_since']);
    }

    /** File on the current view: somebody at the second branch sees only that branch. */
    public function test_a_branch_view_counts_only_its_own_jobs(): void
    {
        $user = $this->userWithRole($this->t, 'Branch', ['reviews.view'], null, $this->other);

        $data = $this->signedInAs($user)->getJson('/api/v1/customer-experience/review-links')->assertOk()->json('data');

        $this->assertSame(['sent' => 1, 'opened' => 1, 'happy' => 0, 'sad' => 1, 'submitted' => 1, 'google' => 0, 'reviewed' => 0, 'qr' => 0], $data['totals']);
        $this->assertSame(['Second Branch'], array_column($data['by_branch'], 'name'));
    }

    public function test_it_needs_the_reviews_grant_and_honours_the_date_range(): void
    {
        $this->signedInAs($this->userWithRole($this->t, 'Viewer', ['customers.view']))
            ->getJson('/api/v1/customer-experience/review-links')->assertForbidden();

        $data = $this->signedInAs($this->t['admin'])->getJson('/api/v1/customer-experience/review-links?'.http_build_query([
            'from' => now()->subDays(60)->toIso8601String(), 'to' => now()->subDays(40)->toIso8601String(),
        ]))->assertOk()->json('data');

        $this->assertSame(0, array_sum($data['totals']));
    }

    /** Jose 2026-11-09: "Reviewed", a Google review matched by name to the link sent before it. */
    public function test_reviewed_matches_a_google_review_to_the_link_by_name_and_counts_it(): void
    {
        [$clickedJob, $directJob] = $this->inTenant($this->t['org'], function () {
            (new \App\Models\BusinessLocation)->forceFill(['organization_id' => $this->t['org']->id, 'company_id' => $this->t['company']->id,
                'franchise_id' => $this->t['franchise']->id, 'remote_name' => 'locations/77', 'title' => 'North'])->save();
            $automation = Automation::query()->first();
            $version = AutomationVersion::query()->first();
            $jobs = [];
            foreach (['Dana Whitfield', 'Marco Ruiz', 'Lee Park'] as $i => $name) {
                $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => "R-$i", 'name' => $name]);
                $job = Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id, 'number' => "RJ-$i",
                    'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD', 'performed_by_user_id' => $this->t['admin']->id]);
                $event = AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'job.status_changed',
                    'subject_type' => Job::class, 'subject_id' => $job->id, 'payload' => [], 'occurred_at' => now(), 'available_at' => now()]);
                AutomationRun::create(['automation_id' => $automation->id, 'automation_version_id' => $version->id, 'event_id' => $event->id,
                    'status' => 'succeeded', 'started_at' => now()->subDays(3), 'finished_at' => now(), 'actions_snapshot' => $version->actions,
                    'action_count' => 1, 'action_results' => []]);
                $jobs[] = $job;
            }
            ReviewLinkEvent::create(['customer_id' => $jobs[0]->customer_id, 'job_id' => $jobs[0]->id, 'kind' => 'google', 'occurred_at' => now()]);
            $review = fn (string $who, int $stars, string $key) => \App\Models\Review::create(['organization_id' => $this->t['org']->id,
                'remote_name' => "accounts/1/locations/77/reviews/$key", 'location_name' => 'accounts/1/locations/77',
                'reviewer_name' => $who, 'rating' => $stars, 'reviewed_at' => now()->subDay()]);
            $review('Dana Whitfield', 5, 'a');   // exact, clicked
            $review('Marco R.', 4, 'b');         // likely, went straight to Google
            $review('Somebody Else', 5, 'c');    // nobody
            $review('Lee', 3, 'd');              // possible: shown beside Reviewed, not in it

            return [$jobs[0], $jobs[1]];
        });

        $this->artisan('ezbizi:review-link-matches', ['--since' => now()->subDays(10)->toDateString()])->assertSuccessful();
        $this->artisan('ezbizi:review-link-matches', ['--since' => now()->subDays(10)->toDateString()])->assertSuccessful();

        $data = $this->signedInAs($this->t['admin'])->getJson('/api/v1/customer-experience/review-links')->assertOk()->json('data');
        $this->assertSame(2, $data['totals']['reviewed'], 'once each, though matched twice');
        $this->assertSame(['exact' => 1, 'likely' => 1, 'confirmed' => 0, 'possible' => 1, 'without_click' => 1], $data['reviewed']['by']);
        $this->assertEquals(4.5, $data['reviewed']['stars']);

        $marco = \App\Models\Review::withoutGlobalScopes()->where('reviewer_name', 'Marco R.')->first();
        $this->assertSame($directJob->id, $marco->job_id, 'the review takes the customer and job of the link');
        $this->putJson("/api/v1/reviews/{$marco->id}/link-match", ['confirmed' => false])->assertOk()
            ->assertJsonPath('data.link_match.status', 'rejected')->assertJsonPath('data.customer', null);
        $this->assertSame(1, $this->getJson('/api/v1/customer-experience/review-links')->json('data.totals.reviewed'));

        // Jose 2026-11-09: a possible match a person confirms moves into Reviewed.
        $lee = \App\Models\Review::withoutGlobalScopes()->where('reviewer_name', 'Lee')->first();
        $this->putJson("/api/v1/reviews/{$lee->id}/link-match", ['confirmed' => true])->assertOk();
        $data = $this->getJson('/api/v1/customer-experience/review-links')->json('data');
        $this->assertSame(2, $data['totals']['reviewed']);
        $this->assertSame(['exact' => 1, 'likely' => 0, 'confirmed' => 1, 'possible' => 0, 'without_click' => 1], $data['reviewed']['by']);
    }

    public function test_names_score_exact_likely_possible_or_nothing(): void
    {
        $s = fn ($r, $c) => \App\Support\CustomerExperience\ReviewLinkMatcher::score($r, $c);
        $this->assertSame('exact', $s('José Pérez', 'jose perez'));
        $this->assertSame('likely', $s('Jose P', 'Jose Perez'));
        $this->assertSame('likely', $s('J. Perez', 'Jose Perez'));
        $this->assertSame('possible', $s('Jose', 'Jose Perez'));
        $this->assertNull($s('Maria Perez', 'Jose Perez'));
        $this->assertNull($s('A Google User', 'Jose Perez'));
    }
}
