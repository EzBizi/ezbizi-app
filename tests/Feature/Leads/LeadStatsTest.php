<?php

namespace Tests\Feature\Leads;

use App\Models\Job;
use App\Models\Lead;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover (2026-10-19): Leads Stats, from EzBizi's own rows. */
class LeadStatsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_stats_count_the_readers_leads_in_the_period(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Pat']);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
            WorkizJob::create(['franchise_id' => $t['franchise']->id, 'remote_id' => 'W1', 'job_id' => $job->id,
                'job_type' => 'Chimney sweep', 'scheduled_for' => '2026-10-16 09:30:00', 'payload' => [], 'synced_at' => now()]);   // a Friday, 8-11
            $lead = fn (array $f) => (new Lead)->forceFill($f + ['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'franchise_id' => $t['franchise']->id, 'source' => Lead::SOURCE_WEBSITE_CHAT, 'remote_id' => uniqid(),
                'status' => Lead::STATUS_NEW, 'received_at' => '2026-10-15 15:00:00'])->save();
            $lead(['job_id' => $job->id, 'status' => Lead::STATUS_COMPLETED, 'postal_code' => '08540', 'classification' => 'BOOK', 'dispatcher' => 'Ana Diaz']);
            $lead(['status' => Lead::STATUS_LOST, 'ai_set' => ['status' => 'lost', 'reason' => 'Price too high'], 'postal_code' => '08540', 'dispatcher' => 'Ana Diaz']);
            $lead(['status' => Lead::STATUS_NOT_A_LEAD, 'ai_set' => ['reason' => 'Spam']]);
            $lead(['received_at' => '2026-09-01 15:00:00']);   // outside the period
        });

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/stats?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame([3, 1, 0.333], [$data['total'], $data['booked'], $data['booking_rate']]);
        $this->assertSame(['website_chat' => 3], $data['by_source']);
        $this->assertSame(['lost' => 1, 'not_a_lead' => 1], $data['loss']['statuses']);
        $this->assertEqualsCanonicalizing(['Price too high', 'Spam'], array_column($data['loss']['reasons'], 'reason'));
        $this->assertSame([['service' => 'Chimney sweep', 'count' => 1]], $data['services_booked']);
        $this->assertSame(['Fri' => ['8-11' => 1]], $data['time_slots']['counts']);
        $this->assertSame([['dispatcher' => 'Ana Diaz', 'total' => 2, 'booked' => 1]], $data['dispatchers']);
        $this->assertSame([['company_id' => $t['company']->id, 'total' => 3, 'booked' => 1]], $data['offices']);

        $points = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/stats/points?from=2026-10-01&to=2026-10-31')->json('data');
        $this->assertSame([['zip' => '08540', 'total' => 2, 'book' => 1, 'support' => 0, 'archive' => 0, 'unclassified' => 1]], $points);

        $this->signedInAs($this->userWithRole($t, 'Nobody', []))->getJson('/api/v1/leads/stats?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }
}
