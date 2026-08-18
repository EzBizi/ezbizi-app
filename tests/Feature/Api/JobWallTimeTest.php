<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\Job;
use App\Support\Automations\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * TODO #20 — a job's scheduled time is wall clock in the franchise timezone.
 *
 * An instant would move on DST. 9am Tuesday for a technician in Chicago has to
 * stay 9am, including the Sunday the clocks spring forward.
 */
class JobWallTimeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_nine_am_chicago_on_spring_forward_stays_nine_am(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $alpha['franchise']->forceFill(['timezone' => 'America/Chicago'])->save();
        });

        $customer = $this->signedInAs($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $id = $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'Morning call',
            'status'       => 'scheduled',
            'scheduled_for' => '2026-03-08 09:00:00',
        ])->assertCreated()->json('data.id');

        $shown = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$id}")
            ->assertOk()
            ->json('data.scheduled_for');

        $this->assertStringContainsString('2026-03-08T09:00:00', $shown);
        $this->assertSame(
            '2026-03-08 09:00:00',
            Job::withoutGlobalScopes()->find($id)->getRawOriginal('scheduled_for'),
        );
    }

    public function test_an_automation_write_matches_the_api_wall_time(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $alpha['franchise']->forceFill(['timezone' => 'America/Chicago'])->save();
        });

        $customer = $this->signedInAs($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $jobId = $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title'        => 'A job',
            'status'       => 'scheduled',
        ])->assertCreated()->json('data.id');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $automation = Automation::create([
                'organization_id' => $alpha['org']->id,
                'name'            => 'Stamp a time',
                'trigger_key'     => 'job.status_changed',
                'is_active'       => true,
            ]);

            $version = AutomationVersion::create([
                'organization_id' => $alpha['org']->id,
                'automation_id'   => $automation->id,
                'version'         => 1,
                'conditions'      => null,
                'actions'         => [[
                    'type'  => 'update_field',
                    'field' => 'scheduled_for',
                    'value' => '2026-06-15 14:30:00',
                ]],
                'created_at'      => now(),
            ]);

            $automation->forceFill(['active_version_id' => $version->id])->save();
        });

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'in_progress'])
            ->assertOk();

        $this->inTenant($alpha['org'], function () {
            $engine = app(AutomationEngine::class);

            AutomationEvent::query()->whereNull('delivered_at')
                ->orderBy('occurred_at')
                ->get()
                ->each(fn (AutomationEvent $e) => $engine->deliver($e));
        });

        $shown = $this->signedInAs($alpha['admin'])
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk()
            ->json('data.scheduled_for');

        $this->assertStringContainsString('2026-06-15T14:30:00', $shown);
        $this->assertSame(
            '2026-06-15 14:30:00',
            Job::withoutGlobalScopes()->find($jobId)->getRawOriginal('scheduled_for'),
        );
    }
}
