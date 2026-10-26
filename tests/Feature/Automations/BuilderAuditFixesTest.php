<?php

namespace Tests\Feature\Automations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The builder audit Jose asked for (2026-10-22): steps say what they need, and refuse what cannot work. */
class BuilderAuditFixesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_change_status_takes_a_status_from_the_list_and_steps_refuse_triggers_they_cannot_work_on(): void
    {
        $t = $this->provisionTenant('Alpha');
        $api = fn () => $this->signedInAs($t['admin']);
        $catalogue = $api()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $change = collect($catalogue['action_types'])->firstWhere('key', 'change_status');
        $this->assertSame(['value'], array_column($change['parameters'], 'key'));
        $this->assertSame(['PhoneCall'], collect($catalogue['action_types'])->firstWhere('key', 'voicemail.mark_dealt')['subjects']);
        $jobStatus = collect(collect($catalogue['modules'])->firstWhere('key', 'jobs')['subject']['writable_fields'])->firstWhere('key', 'status');
        $this->assertSame('enum', $jobStatus['kind']);
        $first = $jobStatus['options'][0]['value'];

        $rule = fn (array $step, string $trigger = 'job.created') => ['name' => 'R', 'trigger_key' => $trigger, 'actions' => [$step]];
        $api()->postJson('/api/v1/automations', $rule(['type' => 'change_status', 'value' => $first]))->assertCreated();
        $api()->postJson('/api/v1/automations', $rule(['type' => 'change_status', 'value' => 'Complete-ish']))
            ->assertUnprocessable()->assertJsonValidationErrors('actions.0.value');
        $api()->postJson('/api/v1/automations', $rule(['type' => 'voicemail.mark_dealt']))
            ->assertUnprocessable()->assertJsonValidationErrors('actions.0.type');
        foreach (['resend', 'revoke', 'reply', 'time_clock.clock_in', 'inspection_report.email'] as $step) {
            $api()->postJson('/api/v1/automations', $rule(['type' => $step, 'message' => 'x']))
                ->assertUnprocessable()->assertJsonValidationErrors('actions.0.type');
        }
    }
}
