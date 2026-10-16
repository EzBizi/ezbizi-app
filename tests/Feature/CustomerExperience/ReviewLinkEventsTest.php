<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\Customer;
use App\Models\Job;
use App\Models\ReviewLinkEvent;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Support\Memberships\CustomerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The review page reports what a customer did with their link (Jose, 2026-10-15). */
class ReviewLinkEventsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private WebSite $site;

    private string $code;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($this->t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $this->t['company']->getKey(), 'host' => 'chimney.test',
        ])->assertCreated()->json('data.id');
        auth()->forgetGuards();

        [$this->site, $this->job, $this->code] = $this->inTenant($this->t['org'], function () use ($id) {
            WebValue::create(['site_id' => $id, 'path' => '', 'key' => 'review_link', 'value' => 'https://chimney.test/reviews/']);
            $customer = Customer::create(['owning_franchise_id' => $this->t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $job = Job::create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'Sweep', 'status' => 'done', 'currency' => 'USD']);

            return [WebSite::findOrFail($id), $job, CustomerLinks::code($customer, $job)];
        });
    }

    private function send(string $kind, ?string $c = null): void
    {
        $this->postJson('/api/v1/web/review-link', ['key' => $this->site->public_key, 'c' => $c ?? $this->code, 'kind' => $kind])
            ->assertOk()->assertJson(['ok' => true]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return ReviewLinkEvent::withoutGlobalScopes()->get()->countBy('kind')->sortKeys()->all();
    }

    public function test_each_kind_is_recorded_once_per_link_with_its_job(): void
    {
        foreach (['opened', 'opened', 'happy', 'google', 'sad', 'submitted'] as $kind) {
            $this->send($kind);
        }

        $this->assertSame(['google' => 1, 'happy' => 1, 'opened' => 1, 'sad' => 1, 'submitted' => 1], $this->counts());
        $row = ReviewLinkEvent::withoutGlobalScopes()->first();
        $this->assertSame($this->job->id, $row->job_id);
        $this->assertSame($this->t['org']->id, $row->organization_id);
    }

    /** The public key is in the page source; only a real link makes a row, and the reply cannot tell. */
    public function test_a_made_up_link_records_nothing_and_answers_the_same(): void
    {
        $this->send('opened', 'Zz9Zz9Z');
        $this->send('opened', 'not.a.real.token');

        $this->assertSame([], $this->counts());

        // The control: the same call with the real code does record.
        $this->send('opened');
        $this->assertSame(['opened' => 1], $this->counts());
    }

    public function test_an_unknown_kind_or_key_is_refused_or_ignored(): void
    {
        $this->postJson('/api/v1/web/review-link', ['key' => $this->site->public_key, 'c' => $this->code, 'kind' => 'clicked'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/web/review-link', ['key' => 'nope', 'c' => $this->code, 'kind' => 'opened'])->assertOk();

        $this->assertSame([], $this->counts());
    }
}
