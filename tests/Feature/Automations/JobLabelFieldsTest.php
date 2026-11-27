<?php

namespace Tests\Feature\Automations;

use App\Models\AutomationEvent;
use App\Models\Job;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20: sub-status, job type, and one tag in or out. */
class JobLabelFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_sets_sub_status_and_adds_and_removes_one_tag_without_erasing_others(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id]);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'tags' => ['VIP']]);
            $run = fn (string $field, string $value) => app(ActionRunner::class)->run(['type' => 'update_field', 'field' => $field, 'value' => $value],
                AutomationEvent::create(['organization_id' => $t['org']->id, 'event_key' => 'job.updated', 'payload' => [], 'occurred_at' => now(), 'available_at' => now()]), $job->fresh());

            $this->assertTrue($run('sub_status', 'Estimate Declined')['ok']);
            $this->assertTrue($run('tag_add', 'DPA')['ok']);
            $this->assertTrue($run('tag_add', 'dpa')['ok']);
            $this->assertSame(['VIP', 'DPA'], $job->fresh()->tags);
            $this->assertTrue($run('tag_remove', 'vip')['ok']);
            $job = $job->fresh();
            $this->assertSame(['DPA'], $job->tags);
            $this->assertSame('Estimate Declined', $job->sub_status);
        });
    }
}
