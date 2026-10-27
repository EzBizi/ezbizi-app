<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Job;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A job's thank-you text with no review link to give (no site with one set
 * for the job's franchise) is skipped, not sent with a hole (Web, 2026-10-08).
 */
class ReviewLinkSkipTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_step_naming_a_missing_review_link_is_skipped_and_one_without_it_is_not(): void
    {
        $t = $this->provisionTenant('Alpha');

        [$withLink, $without, $noOffer] = $this->inTenant($t['org'], function () use ($t) {
            $customer = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $job = Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-1', 'title' => 'Sweep', 'status' => 'Done', 'currency' => 'USD']);
            $event = new AutomationEvent(['event_key' => 'job.status_changed', 'payload' => ['after' => ['status' => 'Done']]]);
            $runner = app(ActionRunner::class);

            return [
                $runner->run(['type' => 'sms.send', 'to' => 'customer:', 'body' => 'Thanks! {{ review_link }}'], $event, $job),
                $runner->run(['type' => 'sms.send', 'to' => 'customer:', 'body' => 'Thanks!'], $event, $job),
                $runner->run(['type' => 'sms.send', 'to' => 'customer:', 'body' => 'Join: {{membership_link}}'], $event, $customer),
            ];
        });

        $this->assertTrue($withLink['skipped'] ?? false);
        $this->assertStringContainsString('no review link', $withLink['detail']);
        // Only a step that names the link is held back FOR the link; with no texting
        // number here the plain one is skipped as not connected (Jose, 2026-10-27).
        $this->assertStringNotContainsString('review link', $without['detail'], 'only a step that names the link is held back for it');
        $this->assertTrue($noOffer['skipped'] ?? false, 'a membership offer with no link is held back too');
    }
}
